#!/usr/bin/env python3
"""Trigger Vito's trusted lifecycle workflows and publish GitHub deployments."""

from http.cookiejar import CookieJar
import hashlib
import hmac
import json
import os
from pathlib import Path
import re
import time
from urllib.error import HTTPError, URLError
from urllib.request import HTTPCookieProcessor, Request, build_opener, urlopen
import uuid


class Api:
    def __init__(self, base, token):
        self.base = base.rstrip("/")
        self.token = token

    def request(self, path, method="GET", payload=None, raw=False):
        headers = {
            "Authorization": "Bearer " + self.token,
            "Accept": "application/json",
            "User-Agent": "VitoPRPreviews/0.1",
        }
        body = None if payload is None else json.dumps(payload).encode()
        if body is not None:
            headers["Content-Type"] = "application/json"
        for attempt in range(4):
            try:
                with urlopen(Request(self.base + path, body, headers, method=method), timeout=45) as response:
                    content = response.read().decode()
                return content if raw else (json.loads(content) if content else None)
            except (HTTPError, URLError, TimeoutError) as error:
                retryable = not isinstance(error, HTTPError) or error.code >= 500
                if method != "GET" or not retryable or attempt == 3:
                    # Do not print request headers, tokens, or response bodies.
                    raise RuntimeError(f"{method} {path} failed: {type(error).__name__}") from None
                time.sleep(2 ** attempt)


def unwrap(value):
    return value.get("data", value)


def pull_request_number(event, manual_number):
    number = str(manual_number or event.get("pull_request", {}).get("number", ""))
    if not re.fullmatch(r"[1-9][0-9]{0,6}", number):
        raise ValueError("A valid pull request number is required.")
    return int(number)


def validate_pull_request(pull_request, repository):
    head_repository = (pull_request.get("head", {}).get("repo") or {}).get("full_name")
    if head_repository != repository:
        raise ValueError("Automatic previews are restricted to branches in this repository.")
    sha = pull_request["head"]["sha"]
    if not re.fullmatch(r"[a-f0-9]{40}", sha):
        raise ValueError("GitHub returned an invalid commit SHA.")
    return sha


def run_vito(vito, project, workflow, operation, number, sha, timeout=1500, configuration=None):
    request_id = uuid.uuid4().hex
    path = f"/api/projects/{project}/workflows/{workflow}/runs"
    run = unwrap(vito.request(path, "POST", {
        "verbose": True,
        "inputs": {**(configuration or {}), "operation": operation, "pr_number": number, "sha": sha, "request_id": request_id},
    }))
    run_id = int(run["id"])
    print(f"Vito workflow {workflow}, run {run_id}: {operation} PR #{number}", flush=True)
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        status = unwrap(vito.request(f"{path}/{run_id}"))["status"]
        if status != "running":
            log = vito.request(f"{path}/{run_id}/log", raw=True)
            marker = f"PREVIEW_OK:{request_id}:{operation}:{number}:{sha}"
            if status != "completed" or "Workflow action failed:" in log or marker not in log:
                raise RuntimeError(f"Vito run {run_id} failed; inspect its workflow log in Vito.")
            return run_id
        time.sleep(8)
    raise RuntimeError(f"Vito run {run_id} timed out; check Vito before retrying.")


def maintenance_secret(token, repository, number):
    return hmac.new(token.encode(), f"vito-pr-previews:{repository.lower()}:{number}".encode(), hashlib.sha256).hexdigest()


def preview_comment(github, repository, number, url=None, secret=None, sha=None):
    marker = "<!-- vito-pr-preview -->"
    body = marker + "\n"
    if url is None:
        body += "Preview removed. Its site and dedicated database have been deleted."
    else:
        link = url + "/" + secret if secret else url
        body += f"[Open {'protected ' if secret else ''}preview]({link}) · `{sha[:7]}`"
        if secret:
            body += f"\n\nAccess secret: `{secret}`\n\nThe link unlocks this preview in your browser."
    page = 1
    while True:
        comments = github.request(f"/repos/{repository}/issues/{number}/comments?per_page=100&page={page}")
        for comment in comments:
            if comment.get("user", {}).get("login") == "github-actions[bot]" and comment.get("body", "").startswith(marker):
                github.request(f"/repos/{repository}/issues/comments/{comment['id']}", "PATCH", {"body": body})
                return
        if len(comments) < 100:
            break
        page += 1
    github.request(f"/repos/{repository}/issues/{number}/comments", "POST", {"body": body})


def verify_preview(url, number, sha, secret=None):
    headers = {"Cache-Control": "no-cache", "User-Agent": "VitoPRPreviews/0.1", "Accept": "*/*"}
    for attempt in range(12):
        try:
            client = build_opener(HTTPCookieProcessor(CookieJar()))
            if secret:
                try:
                    with client.open(Request(url + "/", headers=headers), timeout=30):
                        pass
                except HTTPError as error:
                    if error.code != 503:
                        raise
                else:
                    raise RuntimeError("Preview is accessible without its maintenance secret.")
                with client.open(Request(url + "/" + secret, headers=headers), timeout=30) as response:
                    if response.status != 200:
                        raise ValueError("Preview bypass failed.")
            with client.open(Request(url + "/__preview.json", headers=headers), timeout=30) as response:
                metadata = json.load(response)
                robots = response.headers.get("X-Robots-Tag", "")
            if metadata == {"sha": sha, "pr": number} and "noindex" in robots:
                with client.open(Request(url + "/up", headers=headers), timeout=30) as response:
                    if response.status == 200:
                        return
        except (HTTPError, URLError, TimeoutError, ValueError):
            pass
        if attempt < 11:
            time.sleep(10)
    raise RuntimeError("Public preview did not serve the requested commit with noindex protection.")


def deployment_status(github, repository, deployment, state, url, log_url):
    github.request(f"/repos/{repository}/deployments/{deployment}/statuses", "POST", {
        "state": state,
        "environment_url": url,
        "log_url": log_url,
        "auto_inactive": False,
    })


def deactivate_deployments(github, repository, environment, log_url):
    page = 1
    while True:
        deployments = github.request(f"/repos/{repository}/deployments?environment={environment}&per_page=100&page={page}")
        for deployment in deployments:
            deployment_status(github, repository, deployment["id"], "inactive", "", log_url)
        if len(deployments) < 100:
            return
        page += 1


def main():
    repository = os.environ["GITHUB_REPOSITORY"]
    event = json.loads(Path(os.environ["GITHUB_EVENT_PATH"]).read_text())
    number = pull_request_number(event, os.environ.get("PR_NUMBER"))
    github = Api("https://api.github.com", os.environ["GH_TOKEN"])
    api_url = os.environ["VITO_API_URL"].rstrip("/")
    if not api_url.startswith("https://") and api_url != "http://127.0.0.1:18001":
        raise ValueError("Use HTTPS for Vito, or the local encrypted SSH tunnel.")
    vito = Api(api_url, os.environ["VITO_API_TOKEN"])
    pull_request = github.request(f"/repos/{repository}/pulls/{number}")
    sha = validate_pull_request(pull_request, repository)
    operation = "deploy" if pull_request["state"] == "open" else "destroy"
    environment = f"preview-pr-{number}"
    domain_pattern = os.environ["VITO_DOMAIN_PATTERN"]
    ssl_enabled = os.environ.get("VITO_SSL_ENABLED", "true").lower() == "true"
    url = ("https://" if ssl_enabled else "http://") + domain_pattern.replace("{pr}", str(number))
    log_url = f"https://github.com/{repository}/actions/runs/{os.environ['GITHUB_RUN_ID']}"
    project = int(os.environ.get("VITO_PROJECT_ID", "2"))
    deploy_workflow = int(os.environ["VITO_WORKFLOW_ID"])
    cleanup_workflow = int(os.environ.get("VITO_CLEANUP_WORKFLOW_ID") or deploy_workflow)
    workflow = deploy_workflow if operation == "deploy" else cleanup_workflow
    repository_info = github.request(f"/repos/{repository}")
    configuration = {
        "server_id": int(os.environ["VITO_SERVER_ID"]),
        "source_control_id": int(os.environ["VITO_SOURCE_CONTROL_ID"]),
        "repository": repository,
        "base_branch": repository_info["default_branch"],
        "domain_pattern": domain_pattern,
        "php_version": os.environ["VITO_PHP_VERSION"],
        "environment": os.environ.get("VITO_ENVIRONMENT", "{}"),
        "seed": os.environ.get("VITO_SEED", "false").lower() == "true",
        "database_driver": os.environ.get("VITO_DATABASE_DRIVER", "sqlite"),
        "site_user": os.environ.get("VITO_SITE_USER", "pr-previews"),
        "maintenance_protection": os.environ.get("VITO_MAINTENANCE_PROTECTION", "true").lower() == "true",
        "ssl_enabled": ssl_enabled,
        "force_ssl": os.environ.get("VITO_FORCE_SSL", "true").lower() == "true",
    }
    if os.environ.get("VITO_DEPLOY_SCRIPT"):
        configuration["deploy_script"] = os.environ["VITO_DEPLOY_SCRIPT"]
    if os.environ.get("VITO_SSL_ID"):
        configuration["ssl_id"] = int(os.environ["VITO_SSL_ID"])

    secret = None
    if operation == "deploy" and configuration["maintenance_protection"]:
        secret = maintenance_secret(os.environ["VITO_API_TOKEN"], repository, number)
        if os.environ.get("GITHUB_ACTIONS") == "true":
            print("::add-mask::" + secret, flush=True)
        configuration["maintenance_secret"] = secret

    if operation == "destroy":
        run_vito(vito, project, workflow, operation, number, sha, configuration=configuration)
        deactivate_deployments(github, repository, environment, log_url)
        preview_comment(github, repository, number)
        print(f"Removed preview for PR #{number}.")
    else:
        deployment = github.request(f"/repos/{repository}/deployments", "POST", {
            "ref": sha,
            "environment": environment,
            "description": f"PR #{number} preview",
            "auto_merge": False,
            "required_contexts": [],
            "transient_environment": True,
            "production_environment": False,
        })["id"]
        deployment_status(github, repository, deployment, "in_progress", url, log_url)
        try:
            run_vito(vito, project, workflow, operation, number, sha, configuration=configuration)
            verify_preview(url, number, sha, secret)
            # Closing a PR during a slow build must still remove its preview.
            latest = github.request(f"/repos/{repository}/pulls/{number}")
            if latest["state"] != "open":
                run_vito(vito, project, cleanup_workflow, "destroy", number, sha, configuration=configuration)
                deactivate_deployments(github, repository, environment, log_url)
                preview_comment(github, repository, number)
                return
            preview_comment(github, repository, number, url, secret, sha)
            deployment_status(github, repository, deployment, "success", url, log_url)
        except Exception:
            deployment_status(github, repository, deployment, "failure", url, log_url)
            raise
        print(f"Preview ready: {url}")

    summary = os.environ.get("GITHUB_STEP_SUMMARY")
    if summary:
        with open(summary, "a") as output:
            output.write(f"PR #{number}: {'[Open preview](' + url + ')' if operation == 'deploy' else 'Preview removed'}\n")


if __name__ == "__main__":
    main()
