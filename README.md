# PR Previews for VitoDeploy

Create, update, and remove isolated Laravel pull-request websites using Vito workflows and the Vito API. No deployment helper needs to be installed on the application server: the plugin sends its build commands over Vito's existing SSH connection.

## Scope and requirements

- VitoDeploy **4.1.1**, Nginx, and an installed PHP 8.x runtime (8.3 or newer). Tested with PHP 8.5.
- A GitHub source control already connected to Vito with access to the repository.
- `/usr/local/bin/composer`, Git, Bash, `flock`, OpenSSL, and the SQLite PHP extension on the application server. Node/npm are needed when the app has `package-lock.json`.
- DNS for the preview hostnames and a saved, valid server certificate covering them. Cloudflare proxied wildcard DNS and an existing Origin CA certificate work.
- Laravel's standard `/up` health endpoint and migrations compatible with SQLite.

The first release targets Laravel applications hosted by Vito on Nginx. It does not provision DNS, create certificates, or support arbitrary application stacks.

## Install

Add this GitHub repository through **Admin → Plugins** in Vito, then install and enable **PR Previews**. The repository uses the `vitodeploy-plugin` topic for Vito's community discovery.

The namespace is `App\Vito\Plugins\Webticus\VitoPrPreviews`; a manual checkout belongs at `app/Vito/Plugins/Webticus/VitoPrPreviews`. With Docker, persist that plugin directory through a bind mount so container replacement retains it.

## Workflow

Create a workflow, add **Manage Laravel PR preview**, and mark it as the starting action. The action supports both deployment and removal.

| Input | Example / meaning |
| --- | --- |
| `operation` | `deploy` or `destroy` |
| `server_id` | Target server ID, in the workflow's project |
| `source_control_id` | Existing GitHub source-control ID; required for deployment |
| `repository` | `example/website` |
| `base_branch` | `main`; used only for the initial native clone |
| `domain_pattern` | `website-pr-{pr}.example.com`; exactly one `{pr}` |
| `php_version` | Installed PHP version, such as `8.5` |
| `ssl_id` | Saved matching server certificate; optional when Vito can select one |
| `pr_number` | Positive GitHub pull-request number |
| `sha` | Exact 40-character head SHA |
| `request_id` | A fresh 32-character hexadecimal ID per invocation |
| `environment` | Optional JSON object, such as `{"APP_LOCALE":"bg"}` |
| `seed` | `true` to seed the preview database once; default `false` |

Use the instance's `POST /api/projects/{project}/workflows/{workflow}/runs` endpoint with `verbose: true` and the action inputs in `inputs`. Poll the returned run ID and retrieve its log. Vito 4.1.1 does not expose workflow-create/edit API endpoints, so the initial workflow definition is created in the UI or through Vito's internal administrative actions.

```json
{
  "verbose": true,
  "inputs": {
    "operation": "deploy",
    "server_id": 1,
    "source_control_id": 1,
    "repository": "example/website",
    "base_branch": "main",
    "domain_pattern": "website-pr-{pr}.example.com",
    "php_version": "8.5",
    "pr_number": 42,
    "sha": "0123456789abcdef0123456789abcdef01234567",
    "request_id": "0123456789abcdef0123456789abcdef",
    "environment": "{}",
    "seed": false
  }
}
```

Vito 4.1.1 can report a workflow as completed after an action throws. Require both a completed run **and** its matching `PREVIEW_OK:{request_id}:{operation}:{pr_number}:{sha}` log marker. Reject logs containing `Workflow action failed:`. Then verify the public HTTPS `/__preview.json` metadata and `/up` endpoint before reporting success to GitHub.

For GitHub Actions, use `pull_request_target` with `opened`, `reopened`, `synchronize`, and `closed`, restrict it to branches within your repository, and run only trusted default-branch automation with your Vito credentials. Never run PR-controlled scripts on the GitHub runner holding those credentials. Serialize each PR's lifecycle without cancelling an in-progress deployment. Re-read the PR state from GitHub so a stale close event cannot remove a reopened preview.

## Behavior

Each repository/PR receives its own native Vito site and Linux user. The first deployment clones the repository using Vito's source control, fetches `refs/pull/{number}/head`, and verifies the requested SHA before building. Composer and npm execute as that isolated user.

The preview has its own app key, SQLite database, storage, sessions, and file cache. It uses `APP_ENV=preview`, `APP_DEBUG=false`, the log mail driver, and a database queue. No scheduler or queue worker is installed. Production environment files are never copied. App-specific environment values cannot override database, mail, session, cache, queue, AWS, or Redis isolation settings. The initial environment persists across subsequent updates; edit the preview's shared environment deliberately when changes are needed.

Vito enables SSL and forces HTTPS using the saved certificate. The site's custom Nginx template retains Vito's normal generation while adding noindex headers, disallowing robots, and disabling caching. A preview URL is publicly reachable; noindex is not access control.

Updates build a new release and switch `current` atomically while preserving data. The current and previous release are retained. Failed HTTP health checks restore the previous symlink when available; database migrations are not rolled back. Use migrations compatible with the previous app version.

`destroy` only removes a site whose ownership metadata matches this plugin, repository, and PR. It invokes Vito's full native deletion action, including the site, files, Linux user, PHP pool, and repository deploy key. Repeating removal is safe. It never deletes an unrelated site sharing the requested hostname.

## Checks

```bash
php tests/run.php
find . -name '*.php' -exec php -l {} \;
bash -n scripts/deploy.sh
shellcheck scripts/deploy.sh
```

## GitHub Action

The same repository includes a composite action. Store a project-scoped `VITO_API_TOKEN` secret in your application repository and set these repository variables: `VITO_API_URL`, `VITO_PROJECT_ID`, `VITO_SERVER_ID`, `VITO_SOURCE_CONTROL_ID`, `VITO_WORKFLOW_ID`, `VITO_DOMAIN_PATTERN`, `VITO_PHP_VERSION`, and optionally `VITO_SSL_ID`.

```yaml
name: PR previews
on:
  pull_request_target:
    types: [opened, reopened, synchronize, closed]
  workflow_dispatch:
    inputs:
      pr_number:
        required: true
        type: string
permissions:
  contents: read
  pull-requests: read
  deployments: write
concurrency:
  group: preview-${{ github.event.pull_request.number || inputs.pr_number }}
  cancel-in-progress: false
jobs:
  preview:
    if: github.event_name == 'workflow_dispatch' || github.event.pull_request.head.repo.full_name == github.repository
    runs-on: ubuntu-latest
    timeout-minutes: 40
    steps:
      - uses: webticus/vito-pr-previews@v0.1.0 # Pin the release commit SHA in production.
        with:
          vito-api-url: ${{ vars.VITO_API_URL }}
          vito-api-token: ${{ secrets.VITO_API_TOKEN }}
          project-id: ${{ vars.VITO_PROJECT_ID }}
          server-id: ${{ vars.VITO_SERVER_ID }}
          source-control-id: ${{ vars.VITO_SOURCE_CONTROL_ID }}
          workflow-id: ${{ vars.VITO_WORKFLOW_ID }}
          domain-pattern: ${{ vars.VITO_DOMAIN_PATTERN }}
          php-version: ${{ vars.VITO_PHP_VERSION }}
          ssl-id: ${{ vars.VITO_SSL_ID }}
          pr-number: ${{ inputs.pr_number }}
```

Use HTTPS for the Vito API. If your instance only exposes HTTP, open an authenticated SSH tunnel first and supply `http://127.0.0.1:18001` as the API URL. Avoid sending an API token over public HTTP. The action uses GitHub's deployment status and environment link for each preview; it does not post PR comments.

## License

MIT. See [LICENSE](LICENSE).
