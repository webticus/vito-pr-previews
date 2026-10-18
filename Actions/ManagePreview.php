<?php

declare(strict_types=1);

namespace App\Vito\Plugins\Webticus\VitoPrPreviews\Actions;

use App\Actions\HostedDomain\ActivateHostedDomain;
use App\Actions\Site\CreateSite;
use App\Actions\Site\DeleteSite;
use App\Actions\SSL\AssignSslToDomains;
use App\Actions\Webserver\GenerateNginxConfig;
use App\Enums\SiteStatus;
use App\Enums\SslMethod;
use App\Models\Server;
use App\Models\Site;
use App\Models\Ssl;
use App\Vito\Plugins\Webticus\VitoPrPreviews\Support\PreviewEnvironment;
use App\Vito\Plugins\Webticus\VitoPrPreviews\Support\PreviewIdentity;
use App\WorkflowActions\AbstractWorkflowAction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

final class ManagePreview extends AbstractWorkflowAction
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
            'php_version' => 'Installed PHP version, e.g. 8.4',
            'ssl_id' => 'Saved server SSL ID, or omit to select a matching saved certificate',
            'pr_number' => 'Pull request number',
            'sha' => 'Full 40-character PR head SHA',
            'request_id' => 'Unique 32-character hexadecimal request ID',
            'environment' => 'Optional JSON object of app-specific environment values; defaults to {}',
            'seed' => 'Seed a newly created preview database (boolean; default false)',
        ];
    }

    public function outputs(): array
    {
        return ['site_id' => 'Managed preview site ID', 'url' => 'HTTPS preview URL'];
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
            'ssl_id' => ['nullable', 'integer', 'min:1'],
            'pr_number' => ['required', 'integer', 'min:1', 'max:9999999'],
            'sha' => ['required', 'regex:/\A[a-f0-9]{40}\z/'],
            'request_id' => ['required', 'regex:/\A[a-f0-9]{32}\z/'],
            'environment' => ['nullable', 'string', 'max:16384'],
            'seed' => ['nullable', 'boolean'],
        ])->validate();

        $identity = new PreviewIdentity($data['repository'], (int) $data['pr_number'], $data['domain_pattern']);
        $server = Server::query()->findOrFail($data['server_id']);
        $this->authorize('update', $server);

        $webserver = $server->webserver();

        if ($server->project_id !== $this->workflow->project_id || ! $webserver || $webserver->handler()::id() !== 'nginx') {
            throw new RuntimeException('The server must belong to this workflow project and use Nginx.');
        }

        $lock = "pr-previews:{$server->id}:{$identity->username}";

        return Cache::lock($lock, 1800)->block(30, function () use ($server, $data, $identity): array {
            $site = $server->sites()->where('domain', $identity->domain)->first();

            if ($site instanceof Site && ($site->user !== $identity->username
                || data_get($site->type_data, 'pr_preview.repository') !== $identity->repository
                || (int) data_get($site->type_data, 'pr_preview.number') !== $identity->number)) {
                throw new RuntimeException('Refusing to modify a site not owned by this repository and PR.');
            }

            if ($data['operation'] === 'destroy') {
                if ($site instanceof Site) {
                    $this->authorize('delete', [$site, $server]);
                    app(DeleteSite::class)->delete($site, ['domain' => $identity->domain]);
                }

                $this->success($server, $identity, $data);

                return ['site_id' => null, 'url' => "https://{$identity->domain}"];
            }

            $certificate = isset($data['ssl_id'])
                ? Ssl::activeServerLevel($server->id)->findOrFail($data['ssl_id'])
                : app(AssignSslToDomains::class)->findBestMatch($identity->domain, Ssl::activeServerLevel($server->id)->get());

            if (! $certificate || ! $certificate->coversDomain($identity->domain)
                || ! $certificate->expires_at || $certificate->expires_at->isPast()) {
                throw new RuntimeException('A valid saved server certificate covering the preview hostname is required.');
            }

            $path = "/home/{$identity->username}/{$identity->domain}";
            $environment = PreviewEnvironment::render($identity, $path, $data['environment'] ?? '{}');

            if (! $site instanceof Site) {
                $this->authorize('create', [Site::class, $server]);
                $site = app(CreateSite::class)->create($server, [
                    'type' => 'php',
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
                    'repository' => $identity->repository, 'number' => $identity->number,
                ]);
                $site->refresh();
            }

            $this->authorize('update', [$site, $server]);

            if ($site->status !== SiteStatus::READY || $site->path !== $path || $site->php_version !== $data['php_version']) {
                throw new RuntimeException('Preview provisioning or runtime configuration needs attention in Vito.');
            }

            $template = app(GenerateNginxConfig::class)->defaultTemplate();
            $security = <<<'NGINX'
                add_header X-Robots-Tag "noindex, nofollow, noarchive" always;
                add_header Cache-Control "no-store" always;
                location = /robots.txt { return 200 "User-agent: *\nDisallow: /\n"; }
                charset utf-8;
            NGINX;
            $site->forceFill([
                'ssl_enabled' => true,
                'force_ssl' => true,
                'vhost_generation_enabled' => true,
                'vhost_template' => str_replace('charset utf-8;', $security, $template),
            ])->save();
            $hostedDomain = $site->hostedDomains()->where('domain', $identity->domain)->firstOrFail();
            $hostedDomain->forceFill(['ssl_method' => SslMethod::CUSTOM, 'ssl_id' => $certificate->id])->save();
            app(ActivateHostedDomain::class)->activate($hostedDomain);

            $ref = "refs/pull/{$identity->number}/head";
            $fetch = 'cd '.escapeshellarg($path).' && GIT_TERMINAL_PROMPT=0 git fetch --no-tags origin '.escapeshellarg($ref)
                .' && test "$(git rev-parse FETCH_HEAD)" = '.escapeshellarg($data['sha']);
            $site->ssh()->exec($fetch);

            $script = file_get_contents(__DIR__.'/../scripts/deploy.sh');
            $arguments = array_map(escapeshellarg(...), [
                $path, (string) $identity->number, $data['sha'], $data['request_id'],
                '/usr/bin/php'.$data['php_version'], base64_encode($environment),
                ($data['seed'] ?? false) ? '1' : '0',
            ]);
            $command = 'bash -se -- '.implode(' ', $arguments)." <<'VITO_PR_PREVIEW_SCRIPT'\n{$script}\nVITO_PR_PREVIEW_SCRIPT";
            $server->ssh($site->user)->exec($command);

            // Poll the app after Nginx's graceful reload and before reporting success.
            $check = 'for attempt in 1 2 3 4 5 6 7 8 9 10; do curl -fsSk --max-time 30 --resolve '
                .escapeshellarg($identity->domain.':443:127.0.0.1').' '.escapeshellarg('https://'.$identity->domain.'/up')
                .' >/dev/null && curl -fsSk --max-time 30 --resolve '.escapeshellarg($identity->domain.':443:127.0.0.1')
                .' '.escapeshellarg('https://'.$identity->domain.'/').' >/dev/null && exit 0; sleep 1; done; exit 1';

            try {
                $server->ssh()->exec($check);
            } catch (Throwable $exception) {
                $rollback = 'cd '.escapeshellarg($path).' && if test -L previous; then '
                    .'ln -s "$(readlink previous)" current.rollback && mv -Tf current.rollback current; fi';
                $server->ssh($site->user)->exec($rollback);

                throw $exception;
            }

            $site->jsonUpdate('type_data', 'pr_preview.sha', $data['sha']);
            $this->success($server, $identity, $data);

            return ['site_id' => $site->id, 'url' => "https://{$identity->domain}"];
        });
    }

    private function success(Server $server, PreviewIdentity $identity, array $data): void
    {
        $server->ssh()->exec('printf "%s\\n" '.escapeshellarg($identity->marker($data['request_id'], $data['operation'], $data['sha'])));
    }
}
