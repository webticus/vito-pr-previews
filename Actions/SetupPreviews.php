<?php

declare(strict_types=1);

namespace App\Vito\Plugins\Webticus\VitoPrPreviews\Actions;

use App\DTOs\DynamicField;
use App\DTOs\DynamicForm;
use App\ServerFeatures\Action;
use App\Vito\Plugins\Webticus\VitoPrPreviews\Support\SetupWorkflows;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class SetupPreviews extends Action
{
    public function name(): string
    {
        return 'Set up PR previews';
    }

    public function active(): bool
    {
        return $this->server->isReady() && $this->server->webserver()?->handler()::id() === 'nginx';
    }

    public function form(): ?DynamicForm
    {
        $prefix = 'PR previews (server '.$this->server->id.')';
        $setup = new SetupWorkflows;
        $deploy = $setup->find($this->server, $prefix, 'deploy');
        $cleanup = $setup->find($this->server, $prefix, 'cleanup');
        $fields = [DynamicField::make('prefix')->text()->label('Workflow name prefix')->default($prefix)
            ->description('Creates Prepare → Deploy Site → Verify and a cleanup workflow. Existing matching workflows are kept.')];
        if ($deploy && $cleanup) {
            $fields[] = DynamicField::make('github_inputs')->textarea()->label('Copy these GitHub Action inputs')
                ->default("project-id: '".$this->server->project_id."'\nserver-id: '".$this->server->id."'\nworkflow-id: '".$deploy->id."'\ncleanup-workflow-id: '".$cleanup->id."'");
        }

        return DynamicForm::make($fields);
    }

    public function handle(Request $request): void
    {
        $data = $request->validate(['prefix' => ['required', 'string', 'max:100']]);
        if (! $this->active()) {
            throw ValidationException::withMessages(['prefix' => 'A ready server with Nginx is required.']);
        }
        $workflows = (new SetupWorkflows)->setup($request->user(), $this->server, trim($data['prefix']));
        $request->session()->flash('success', 'PR previews ready. GitHub inputs: workflow-id='.$workflows['deploy']->id
            .', cleanup-workflow-id='.$workflows['cleanup']->id.'. Reopen setup to copy them.');
    }
}
