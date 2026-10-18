# PR Previews for VitoDeploy

Automatically create a Laravel preview for each GitHub PR, update it on new commits, and remove it when the PR closes. Uses Vito's native sites and workflows; no separate deployment helper is installed on your app server.

Requires **Vito 4.1.1**, Nginx, PHP 8.3+, Composer at `/usr/local/bin/composer`, Git, and Node/npm when your app builds frontend assets. Your app needs Laravel's `/up` route. Connect your GitHub source control in Vito first.

## 1. Install the plugin

In Vito, open **Admin → Plugins**, find `webticus/vito-pr-previews`, then install and enable **PR Previews**. For Docker, persist `app/Vito/Plugins` in a volume or bind mount.

## 2. Create a Vito workflow

Create two workflows:

- **Deploy:** Prepare Laravel PR preview → Vito **Deploy Site** → Verify Laravel PR preview. Mark Prepare as the start. In Deploy Site use `site_id: {site_id}`. In Verify use `site_id: {site_id}` and `deployment_id: {deployment_id}`.
- **Cleanup:** Manage Laravel PR preview as the start. GitHub passes `operation: destroy`.

Save both and note their IDs from the URLs. GitHub supplies the configuration and PR details when it triggers them.

Add wildcard DNS for your preview hostnames, for example `*.preview.example.com`, pointing at your app server.

## 3. Configure GitHub

In your app repository's **Settings → Secrets and variables → Actions**, add:

- Secret `VITO_API_TOKEN`: a Vito API key with write access to the target project.
- Variable `VITO_API_URL`: your HTTPS Vito address, such as `https://vito.example.com`.

Add `.github/workflows/pr-preview.yml` below. Replace the example IDs and hostname with your values from Vito.

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
      - uses: webticus/vito-pr-previews@v0.1.0 # Prefer a pinned commit SHA.
        with:
          vito-api-url: ${{ vars.VITO_API_URL }}
          vito-api-token: ${{ secrets.VITO_API_TOKEN }}
          project-id: '1'
          server-id: '1'
          source-control-id: '1'
          workflow-id: '1'
          cleanup-workflow-id: '2'
          domain-pattern: pr-{pr}.preview.example.com
          php-version: '8.5'
          database-driver: mariadb
          ssl-id: '1'
          pr-number: ${{ inputs.pr_number }}
```

Merge this workflow into your default branch. Open a PR: its GitHub deployment link will point to `pr-123.preview.example.com`. Further commits update that URL; closing or merging the PR removes the site and its dedicated database/user.

## Database options

```yaml
database-driver: mariadb # mariadb, mysql, or sqlite
seed: 'true'             # Optional: seed a fresh preview database once.
```

MariaDB/MySQL use Vito's default database service on the app server. The plugin creates a database and a localhost-only user for each PR, preserves them on updates, and deletes both on cleanup. SQLite needs the SQLite PHP extension. To change an existing preview's database driver, remove and recreate that preview.

## SSL options

HTTPS and HTTP-to-HTTPS redirects default to enabled. Install a matching certificate in Vito, then choose its ID:

```yaml
ssl-enabled: 'true'
force-ssl: 'true'
ssl-id: '6' # Optional: omit to automatically select a matching saved certificate.
```

For HTTP previews, no certificate is needed:

```yaml
ssl-enabled: 'false'
force-ssl: 'false'
```

For Cloudflare proxied DNS, a saved matching Origin CA certificate works. The plugin does not issue certificates or change DNS. Your Vito **API** should use HTTPS, or an encrypted SSH tunnel to `http://127.0.0.1:18001`.

## Custom deployment script

```yaml
deploy-script: deploy.sh # Optional; otherwise the plugin builds and migrates Laravel.
```

The native **Deploy Site** action runs it inside the prepared PR release, as the preview user, with `DEPLOY_PREVIEW=1`, `PREVIEW_SHA`, `PREVIEW_URL`, and `PHP_BIN`. Your script should install dependencies, build assets, and migrate the preview database. It must handle preview mode and avoid pulling another branch. The plugin handles the release switch and verification.

## App-specific settings

```yaml
environment: '{"APP_LOCALE":"bg","TURNSTILE_HOSTNAMES":"{domain}"}'
```

`{domain}` and `{pr}` are expanded. Database, mail, session, cache, queue, AWS, and Redis settings are protected. Every preview gets its own app key, storage, cache, and database; mail is logged and no scheduler or queue worker is started. Production environment files are never copied.

Previews are public URLs with noindex protection. Only same-repository PRs run automatically. A failed HTTP health check restores the previous release when available; migrations are not reversed.

MIT licensed. Run checks with `php tests/run.php`, `python3 -m unittest discover -s github -p test_preview.py`, and `shellcheck scripts/deploy.sh`.
