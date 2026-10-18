<?php

declare(strict_types=1);

namespace App\Vito\Plugins\Webticus\VitoPrPreviews\Support;

use App\Vito\Plugins\Webticus\VitoPrPreviews\Actions\ManagePreview;
use App\Vito\Plugins\Webticus\VitoPrPreviews\Actions\PreparePreview;
use App\Vito\Plugins\Webticus\VitoPrPreviews\Actions\VerifyPreview;
use App\WorkflowActions\Site\DeploySite;

final class WorkflowBlueprint
{
    public static function make(int $serverId, bool $cleanup = false): array
    {
        $defaults = [
            'operation' => $cleanup ? 'destroy' : 'deploy', 'server_id' => $serverId,
            'source_control_id' => '', 'repository' => '', 'base_branch' => 'main',
            'domain_pattern' => '', 'php_version' => '', 'site_user' => 'pr-previews',
            'database_driver' => 'sqlite', 'ssl_enabled' => true, 'force_ssl' => true,
            'maintenance_protection' => true, 'maintenance_secret' => '', 'ssl_id' => '',
            'deploy_script' => '', 'environment' => '{}', 'seed' => false,
            'pr_number' => '', 'sha' => '', 'request_id' => '',
        ];
        $outputs = ['site_id' => 'Managed preview site ID', 'url' => 'Preview URL'];
        if ($cleanup) {
            return ['nodes' => [self::node('cleanup', 'Remove PR preview', ManagePreview::class, $defaults, $outputs, true, 100)], 'edges' => []];
        }

        return [
            'nodes' => [
                self::node('prepare', 'Prepare Laravel PR preview', PreparePreview::class, $defaults, $outputs, true, 100),
                self::node('deploy', 'Deploy Site', DeploySite::class, ['site_id' => '{site_id}'], ['site_id' => 'Site ID', 'deployment_id' => 'Deployment ID', 'deployment_status' => 'Deployment status'], false, 500),
                self::node('verify', 'Verify Laravel PR preview', VerifyPreview::class, ['site_id' => '{site_id}', 'deployment_id' => '{deployment_id}'], $outputs, false, 900),
            ],
            'edges' => [self::edge('prepare', 'deploy'), self::edge('deploy', 'verify')],
        ];
    }

    private static function node(string $id, string $label, string $handler, array $inputs, array $outputs, bool $starting, int $x): array
    {
        return ['id' => $id, 'type' => 'custom', 'position' => ['x' => $x, 'y' => 100],
            'data' => ['action' => compact('label', 'handler', 'inputs', 'outputs', 'starting')]];
    }

    private static function edge(string $source, string $target): array
    {
        return ['id' => $source.'-'.$target, 'source' => $source, 'target' => $target, 'type' => 'smoothstep', 'data' => ['status' => 'success']];
    }
}
