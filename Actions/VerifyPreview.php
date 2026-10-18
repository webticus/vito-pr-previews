<?php

declare(strict_types=1);

namespace App\Vito\Plugins\Webticus\VitoPrPreviews\Actions;

use App\Enums\DeploymentStatus;
use App\Models\Site;
use App\Vito\Plugins\Webticus\VitoPrPreviews\Support\PreviewIdentity;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

final class VerifyPreview extends ManagePreview
{
    public function inputs(): array
    {
        return ['site_id' => 'Output from Deploy Site', 'deployment_id' => 'Output from Deploy Site'];
    }

    public function run(array $input): array
    {
        $input = Validator::make($input, ['site_id' => ['required', 'integer'], 'deployment_id' => ['required', 'integer']])->validate();
        $site = Site::query()->findOrFail($input['site_id']);
        $server = $site->server;
        $this->authorize('update', [$site, $server]);

        if ($server->project_id !== $this->workflow->project_id) {
            throw new RuntimeException('The preview must belong to the workflow project.');
        }

        $deployment = $site->deployments()->findOrFail($input['deployment_id']);
        if ($deployment->status !== DeploymentStatus::FINISHED) {
            throw new RuntimeException('Native deployment failed; inspect the site deployment log.');
        }

        $metadata = $site->type_data['pr_preview'] ?? [];
        $identity = new PreviewIdentity($metadata['repository'] ?? '', (int) ($metadata['number'] ?? 0), $metadata['domain_pattern'] ?? '');
        if ($site->user !== $identity->username || $site->repository !== $identity->repository || $site->domain !== $identity->domain) {
            throw new RuntimeException('Preview ownership does not match.');
        }

        $data = ['operation' => 'deploy', 'sha' => $metadata['pending_sha'], 'request_id' => $metadata['request_id'], 'ssl_enabled' => $metadata['ssl_enabled']];
        $identity->marker($data['request_id'], 'deploy', $data['sha']);
        $url = ($data['ssl_enabled'] ? 'https://' : 'http://').$site->domain;
        $result = $this->finishPreview($site, $identity, $data, $url);
        $deployment->commit_id = $data['sha'];
        $deployment->save();

        return $result;
    }
}
