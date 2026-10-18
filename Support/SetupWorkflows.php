<?php

declare(strict_types=1);

namespace App\Vito\Plugins\Webticus\VitoPrPreviews\Support;

use App\Actions\Workflow\CreateWorkflow;
use App\Actions\Workflow\UpdateWorkflow;
use App\Models\Server;
use App\Models\User;
use App\Models\Workflow;
use App\Vito\Plugins\Webticus\VitoPrPreviews\Actions\ManagePreview;
use App\Vito\Plugins\Webticus\VitoPrPreviews\Actions\PreparePreview;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

final class SetupWorkflows
{
    public function setup(User $user, Server $server, string $prefix): array
    {
        Gate::forUser($user)->authorize('update', $server);
        Gate::forUser($user)->authorize('create', [Workflow::class, $server->project]);

        return Cache::lock('pr-previews:setup:'.$server->id, 60)->block(30, fn (): array => DB::transaction(function () use ($user, $server, $prefix): array {
            $result = [];
            foreach (['deploy', 'cleanup'] as $kind) {
                $workflow = $this->find($server, $prefix, $kind);
                if (! $workflow) {
                    $workflow = app(CreateWorkflow::class)->create($user, $server->project, ['name' => $prefix.' '.$kind]);
                    $blueprint = WorkflowBlueprint::make($server->id, $kind === 'cleanup');
                    app(UpdateWorkflow::class)->update($workflow, ['name' => $workflow->name, ...$blueprint]);
                }
                Gate::forUser($user)->authorize('view', $workflow);
                $result[$kind] = $workflow;
            }

            return $result;
        }));
    }

    public function find(Server $server, string $prefix, string $kind): ?Workflow
    {
        $handler = $kind === 'cleanup' ? ManagePreview::class : PreparePreview::class;
        $matches = $server->project->workflows()->get()->filter(function (Workflow $workflow) use ($server, $handler): bool {
            foreach ($workflow->payload['nodes'] ?? [] as $node) {
                $action = $node['data']['action'] ?? [];
                if (($action['starting'] ?? false) && ($action['handler'] ?? '') === $handler
                    && (int) ($action['inputs']['server_id'] ?? 0) === $server->id) {
                    return true;
                }
            }

            return false;
        });
        $named = $server->project->workflows()->where('name', $prefix.' '.$kind)->first();
        if ($named && ! $matches->contains('id', $named->id)) {
            throw new RuntimeException('A workflow with this name exists and belongs to another setup. Choose a different prefix.');
        }

        return $named ?? ($matches->count() === 1 ? $matches->first() : null);
    }
}
