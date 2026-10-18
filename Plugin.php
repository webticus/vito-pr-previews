<?php

declare(strict_types=1);

namespace App\Vito\Plugins\Webticus\VitoPrPreviews;

use App\Plugins\AbstractPlugin;
use App\Plugins\RegisterWorkflowAction;
use App\Vito\Plugins\Webticus\VitoPrPreviews\Actions\ManagePreview;

final class Plugin extends AbstractPlugin
{
    protected string $name = 'PR Previews';

    protected string $description = 'Create, update and remove isolated Laravel pull-request sites through Vito workflows.';

    public function boot(): void
    {
        RegisterWorkflowAction::make('pr-previews-manage')
            ->label('Manage Laravel PR preview')
            ->description($this->description)
            ->category('Site')
            ->handler(ManagePreview::class)
            ->register();
    }
}
