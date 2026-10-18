<?php

declare(strict_types=1);

namespace App\Vito\Plugins\Webticus\VitoPrPreviews\Actions;

use App\Actions\HostedDomain\ActivateHostedDomain;
use App\Actions\Site\CreateSite;
use App\Actions\Site\UpdateDeploymentScript;
use App\Actions\SSL\AssignSslToDomains;
use App\Actions\Webserver\GenerateNginxConfig;
use App\Enums\SiteStatus;
use App\Enums\SslMethod;
use App\Models\Server;
use App\Models\Site;
use App\Models\Ssl;
use App\Vito\Plugins\Webticus\VitoPrPreviews\Support\DeletePreview;
use App\Vito\Plugins\Webticus\VitoPrPreviews\Support\ManagedDatabase;
use App\Vito\Plugins\Webticus\VitoPrPreviews\Support\PreviewEnvironment;
use App\Vito\Plugins\Webticus\VitoPrPreviews\Support\PreviewIdentity;
use App\WorkflowActions\AbstractWorkflowAction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

class ManagePreview extends AbstractWorkflowAction
{
    public function inputs(): array
    {
        return [
            'operation' => 'deploy or destroy',
            'server_id' => 'Target server ID',
            'source_control_id' => 'Connected GitHub source control ID',
            'repository' => 'GitHub repository in owner/name format',
            'base_branch' => 'Default branch used for the initial clone, e.g. main',
            'domain_pattern' => 'Preview hostname with one {pr} placeholder, e.g. pr-{pr}.example.com',
            'site_user' => 'Shared Linux user for all previews (default pr-previews)',
            'maintenance_protection' => 'Require a Laravel maintenance bypass secret (boolean; default true)',
            'maintenance_secret' => 'Stable 64-character hexadecimal bypass secret supplied by GitHub',
            'php_version' => 'Installed PHP version, e.g. 8.4',
            'ssl_id' => 'Saved server SSL ID, or omit to select a matching saved certificate',
            'ssl_enabled' => 'Enable HTTPS (boolean; default true); false requires no certificate',
            'force_ssl' => 'Redirect HTTP to HTTPS (boolean; default true when SSL is enabled)',
            'database_driver' => 'sqlite, mariadb or mysql (default sqlite)',
            'deploy_script' => 'Optional repository script path, e.g. deploy.sh; runs with DEPLOY_PREVIEW=1',
            'pr_number' => 'Pull request number',
            'sha' => 'Full 40-character PR head SHA',
            'request_id' => 'Unique 32-character hexadecimal request ID',
            'environment' => 'Optional JSON object of app-specific environment values; defaults to {}',
            'seed' => 'Seed a newly created preview database (boolean; default false)',
        ];
    }

    public function outputs(): array
    {
        return ['site_id' => 'Managed preview site ID', 'url' => 'Preview URL'];
    }

    public function run(array $input): array
    {
        $data = Validator::make($input, [
            'operation' => ['required', Rule::in(['deploy', 'destroy'])],
            'server_id' => ['required', 'integer', 'min:1'],
            'source_control_id' => ['required_if:operation,deploy', 'integer', 'min:1'],
            'repository' => ['required', 'string', 'max:200'],
            'base_branch' => ['required_if:operation,deploy', 'string', 'max:200'],
            'domain_pattern' => ['required', 'string', 'max:253'],
            'php_version' => ['required_if:operation,deploy', 'regex:/\A8\.[3-9]\z/'],
            'site_user' => ['nullable', 'string', 'regex:/\A[a-z_][a-z0-9_-]{1,30}[a-z0-9]\z/'],
            'maintenance_protection' => ['nullable', 'boolean'],
            'maintenance_secret' => ['nullable', 'regex:/\A[a-f0-9]{64}\z/'],
            'ssl_id' => ['nullable', 'integer', 'min:1'],
            'ssl_enabled' => ['nullable', 'boolean'],
            'force_ssl' => ['nullable', 'boolean'],
            'database_driver' => ['nullable', Rule::in(['sqlite', 'mariadb', 'mysql'])],
            'deploy_script' => ['nullable', 'string', 'max:200', 'regex:/\A[A-Za-z0-9_.-]+(?:\/[A-Za-z0-9_.-]+)*\z/', 'not_regex:/\.\./'],
            'prepare_only' => ['nullable', 'boolean'],
            'pr_number' => ['required', 'integer', 'min:1', 'max:9999999'],
            'sha' => ['required', 'regex:/\A[a-f0-9]{40}\z/'],
            'request_id' => ['required', 'regex:/\A[a-f0-9]{32}\z/'],
            'environment' => ['nullable', 'string', 'max:16384'],
            'seed' => ['nullable', 'boolean'],
        ])->validate();

        $identity = new PreviewIdentity($data['repository'], (int) $data['pr_number'], $data['domain_pattern'], $data['site_user'] ?? 'pr-previews');
        $data['ssl_enabled'] = (bool) ($data['ssl_enabled'] ?? true);
        $data['force_ssl'] = $data['ssl_enabled'] && (bool) ($data['force_ssl'] ?? true);
        $data['database_driver'] ??= 'sqlite';
        $data['maintenance_protection'] = (bool) ($data['maintenance_protection'] ?? true);
        if ($data['operation'] === 'deploy' && $data['maintenance_protection'] && empty($data['maintenance_secret'])) {
            throw new RuntimeException('Protected previews require a maintenance_secret.');
        }
        $url = ($data['ssl_enabled'] ? 'https://' : 'http://').$identity->domain;
        $server = Server::query()->findOrFail($data['server_id']);
        $this->authorize('update', $server);

        $webserver = $server->webserver();

        if ($server->project_id !== $this->workflow->project_id || ! $webserver || $webserver->handler()::id() !== 'nginx') {
            throw new RuntimeException('The server must belong to this workflow project and use Nginx.');
        }

        $lock = "pr-previews:{$server->id}:{$identity->resourceName}";

        return Cache::lock($lock, 1800)->block(30, function () use ($server, $data, $identity, $url): array {
            $site = $server->sites()->where('domain', $identity->domain)->first();

            if ($site instanceof Site && ($site->user !== $identity->username
                || data_get($site->type_data, 'pr_preview.repository') !== $identity->repository
                || (int) data_get($site->type_data, 'pr_preview.number') !== $identity->number)) {
                throw new RuntimeException('Refusing to modify a site not owned by this repository and PR.');
            }

            if ($data['operation'] === 'destroy') {
                if ($site instanceof Site) {
                    $this->authorize('delete', [$site, $server]);
                    (new ManagedDatabase($this->user))->destroy($site, $identity);
                    (new DeletePreview)->delete($site);
                }

                $this->success($server, $identity, $data);

                return ['site_id' => null, 'url' => $url];
            }

            $certificate = null;
            if ($data['ssl_enabled']) {
                $certificate = isset($data['ssl_id'])
                    ? Ssl::activeServerLevel($server->id)->findOrFail($data['ssl_id'])
                    : app(AssignSslToDomains::class)->findBestMatch($identity->domain, Ssl::activeServerLevel($server->id)->get());

                if (! $certificate || ! $certificate->coversDomain($identity->domain)
                    || ! $certificate->expires_at || $certificate->expires_at->isPast()) {
                    throw new RuntimeException('A valid saved server certificate covering the preview hostname is required when SSL is enabled.');
                }
            }

            $path = "/home/{$identity->username}/{$identity->domain}";
            PreviewEnvironment::render($identity, $path, $data['environment'] ?? '{}', sslEnabled: $data['ssl_enabled']);

            if (! $site instanceof Site) {
                $this->authorize('create', [Site::class, $server]);
                $site = app(CreateSite::class)->create($server, [
                    'type' => 'pr-preview',
                    'domain' => $identity->domain,
                    'user' => $identity->username,
                    'php_version' => $data['php_version'],
                    'web_directory' => 'current/public',
                    'source_control' => $data['source_control_id'],
                    'repository' => $identity->repository,
                    'branch' => $data['base_branch'],
                    'composer' => false,
                    'package_manager' => 'none',
                ]);
                $site->jsonUpdate('type_data', 'pr_preview', [
                    'repository' => $identity->repository, 'number' => $identity->number, 'domain_pattern' => $data['domain_pattern'], 'site_user' => $identity->username,
                ]);
                $site->refresh();
            }

            $this->authorize('update', [$site, $server]);

            if ($site->status !== SiteStatus::READY || $site->path !== $path || $site->php_version !== $data['php_version']) {
                throw new RuntimeException('Preview provisioning or runtime configuration needs attention in Vito.');
            }

            $database = (new ManagedDatabase($this->user))->ensure($site, $identity, $data['database_driver']);
            $environment = PreviewEnvironment::render($identity, $path, $data['environment'] ?? '{}', $database, $data['ssl_enabled']);

            $template = app(GenerateNginxConfig::class)->defaultTemplate();
            $security = <<<'NGINX'
                add_header X-Robots-Tag "noindex, nofollow, noarchive" always;
                add_header Cache-Control "no-store" always;
                location = /robots.txt { return 200 "User-agent: *\nDisallow: /\n"; }
                charset utf-8;
            NGINX;
            $site->forceFill([
                'ssl_enabled' => $data['ssl_enabled'],
                'force_ssl' => $data['force_ssl'],
                'vhost_generation_enabled' => true,
                'vhost_template' => str_replace('charset utf-8;', $security, $template),
            ])->save();
            $hostedDomain = $site->hostedDomains()->where('domain', $identity->domain)->firstOrFail();
            $hostedDomain->forceFill(['ssl_method' => $data['ssl_enabled'] ? SslMethod::CUSTOM : SslMethod::NONE, 'ssl_id' => $certificate?->id])->save();
            app(ActivateHostedDomain::class)->activate($hostedDomain);

            $ref = "refs/pull/{$identity->number}/head";
            $fetch = 'cd '.escapeshellarg($path).' && GIT_TERMINAL_PROMPT=0 git fetch --no-tags origin '.escapeshellarg($ref)
                .' && test "$(git rev-parse FETCH_HEAD)" = '.escapeshellarg($data['sha']);
            $site->ssh()->exec($fetch);

            $prepare = file_get_contents(__DIR__.'/../scripts/prepare.sh');
            $prepareArguments = array_map(escapeshellarg(...), [$path, base64_encode($environment), $data['database_driver'], $data['maintenance_protection'] ? $data['maintenance_secret'] : '', '/usr/bin/php'.$data['php_version']]);
            $server->ssh($site->user)->exec('bash -se -- '.implode(' ', $prepareArguments)." <<'VITO_PR_PREVIEW_PREPARE'\n{$prepare}\nVITO_PR_PREVIEW_PREPARE");

            $script = file_get_contents(__DIR__.'/../scripts/deploy.sh');
            $arguments = array_map(escapeshellarg(...), [
                $path, (string) $identity->number, $data['sha'], $data['request_id'],
                '/usr/bin/php'.$data['php_version'],
                ($data['seed'] ?? false) ? '1' : '0',
                $data['deploy_script'] ?? '', $url,
            ]);
            $command = 'bash -se -- '.implode(' ', $arguments)." <<'VITO_PR_PREVIEW_SCRIPT'\n{$script}\nVITO_PR_PREVIEW_SCRIPT";

            if ($data['prepare_only'] ?? false) {
                $site->ensureDeploymentScriptsExist();
                app(UpdateDeploymentScript::class)->update($site->activeDeploymentScript(), ['script' => $command, 'restart_workers' => false]);
                $metadata = $site->type_data['pr_preview'];
                $metadata['pending_sha'] = $data['sha'];
                $metadata['request_id'] = $data['request_id'];
                $metadata['ssl_enabled'] = $data['ssl_enabled'];
                $metadata['maintenance_protection'] = $data['maintenance_protection'];
                $metadata['domain_pattern'] = $data['domain_pattern'];
                $site->jsonUpdate('type_data', 'pr_preview', $metadata);

                return ['site_id' => $site->id, 'url' => $url];
            }

            $server->ssh($site->user)->exec($command);

            return $this->finishPreview($site, $identity, $data, $url);
        });
    }

    protected function finishPreview(Site $site, PreviewIdentity $identity, array $data, string $url): array
    {
        $server = $site->server;
        $path = $site->path;

        // Poll the app after Nginx's graceful reload and before reporting success.
        $port = $data['ssl_enabled'] ? '443' : '80';
        $resolve = escapeshellarg($identity->domain.":{$port}:127.0.0.1");
        $validateMetadata = '$data = json_decode(stream_get_contents(STDIN), true); exit(is_array($data) && ($data["sha"] ?? null) === $argv[1] && ($data["pr"] ?? null) === (int) $argv[2] ? 0 : 1);';
        $curl = 'curl -fsSk --max-time 30 --resolve '.$resolve;
        $authenticate = '';
        $protectedCheck = '';
        $cookie = '';
        if ($data['maintenance_protection'] ?? true) {
            $makeCookie = '$key = trim(file_get_contents($argv[1])); $expires = time() + 600; echo "laravel_maintenance=".base64_encode(json_encode(["expires_at" => $expires, "mac" => hash_hmac("sha256", (string) $expires, $key)]));';
            $authenticate = 'cookie=$('.escapeshellarg('/usr/bin/php'.$site->php_version).' -r '.escapeshellarg($makeCookie).' '.escapeshellarg($path.'/shared/.maintenance-secret').'); ';
            $cookie = ' -H "Cookie: $cookie"';
            $protectedCheck = 'test "$(curl -sSk --max-time 30 --resolve '.$resolve.' -o /dev/null -w "%{http_code}" '.escapeshellarg($url.'/').')" = 503 && ';
        }
        $check = $authenticate.'for attempt in 1 2 3 4 5 6 7 8 9 10; do '.$protectedCheck.$curl.$cookie.' '.escapeshellarg($url.'/up')
            .' >/dev/null && '.$curl.$cookie.' '.escapeshellarg($url.'/').' >/dev/null && '.$curl.$cookie
            .' '.escapeshellarg($url.'/__preview.json').' | '.escapeshellarg('/usr/bin/php'.$site->php_version)
            .' -r '.escapeshellarg($validateMetadata).' '.escapeshellarg($data['sha']).' '.escapeshellarg((string) $identity->number)
            .' && exit 0; sleep 1; done; exit 1';

        try {
            $server->ssh()->exec($check);
        } catch (Throwable $exception) {
            $rollback = 'cd '.escapeshellarg($path).' && if test -L previous; then '
                .'ln -s "$(readlink previous)" current.rollback && mv -Tf current.rollback current; fi';
            $server->ssh($site->user)->exec($rollback);

            throw $exception;
        }

        $metadata = $site->type_data['pr_preview'];
        $metadata['sha'] = $data['sha'];
        $site->jsonUpdate('type_data', 'pr_preview', $metadata);
        $this->success($server, $identity, $data);

        return ['site_id' => $site->id, 'url' => $url];
    }

    private function success(Server $server, PreviewIdentity $identity, array $data): void
    {
        $server->ssh()->exec('printf "%s\\n" '.escapeshellarg($identity->marker($data['request_id'], $data['operation'], $data['sha'])));
    }
}
