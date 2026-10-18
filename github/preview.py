#!/usr/bin/env python3
"""Trigger Vito's trusted lifecycle workflows and publish GitHub deployments."""

import json
import os
from pathlib import Path
import re
import time
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen
import uuid


class Api:
    def __init__(self, base, token):
        self.base = base.rstrip("/")
        self.token = token

    def request(self, path, method="GET", payload=None, raw=False):
        headers = {
            "Authorization": "Bearer " + self.token,
            "Accept": "application/json",
            "User-Agent": "burzsait-pr-preview",
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


def verify_preview(url, number, sha):
    for attempt in range(12):
        try:
            with urlopen(Request(url + "/__preview.json", headers={"Cache-Control": "no-cache"}), timeout=30) as response:
                metadata = json.load(response)
                robots = response.headers.get("X-Robots-Tag", "")
            if metadata == {"sha": sha, "pr": number} and "noindex" in robots:
                with urlopen(url + "/up", timeout=30) as response:
                    if response.status == 200:
                        return
        except (HTTPError, URLError, TimeoutError, ValueError):
            pass
        if attempt < 11:
            time.sleep(10)
    raise RuntimeError("Public HTTPS preview did not serve the requested commit with noindex protection.")


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
    url = "https://" + domain_pattern.replace("{pr}", str(number))
    log_url = f"https://github.com/{repository}/actions/runs/{os.environ['GITHUB_RUN_ID']}"
    project = int(os.environ.get("VITO_PROJECT_ID", "2"))
    workflow = int(os.environ["VITO_WORKFLOW_ID"])
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
    }
    if os.environ.get("VITO_SSL_ID"):
        configuration["ssl_id"] = int(os.environ["VITO_SSL_ID"])

    if operation == "destroy":
        run_vito(vito, project, workflow, operation, number, sha, configuration=configuration)
        deactivate_deployments(github, repository, environment, log_url)
        print(f"Removed preview for PR #{number}.")
    else:
        deployment = github.request(f"/repos/{repository}/deployments", "POST", {
            "ref": sha,
            "environment": environment,
            "description": f"Burzsait PR #{number}",
            "auto_merge": False,
            "required_contexts": [],
            "transient_environment": True,
            "production_environment": False,
        })["id"]
        deployment_status(github, repository, deployment, "in_progress", url, log_url)
        try:
            run_vito(vito, project, workflow, operation, number, sha, configuration=configuration)
            verify_preview(url, number, sha)
            # Closing a PR during a slow build must still remove its preview.
            latest = github.request(f"/repos/{repository}/pulls/{number}")
            if latest["state"] != "open":
                run_vito(vito, project, workflow, "destroy", number, sha, configuration=configuration)
                deactivate_deployments(github, repository, environment, log_url)
                return
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
