<?php

declare(strict_types=1);

namespace App\Vito\Plugins\Webticus\VitoPrPreviews;

use App\Plugins\AbstractPlugin;
use App\Plugins\RegisterSiteType;
use App\Plugins\RegisterWorkflowAction;
use App\Vito\Plugins\Webticus\VitoPrPreviews\Actions\ManagePreview;
use App\Vito\Plugins\Webticus\VitoPrPreviews\Actions\PreparePreview;
use App\Vito\Plugins\Webticus\VitoPrPreviews\Actions\VerifyPreview;
use App\Vito\Plugins\Webticus\VitoPrPreviews\Support\PreviewSite;

final class Plugin extends AbstractPlugin
{
    protected string $name = 'PR Previews';

    protected string $description = 'Create, update and remove Laravel pull-request sites through Vito workflows.';

    public function boot(): void
    {
        RegisterSiteType::make('pr-preview')->label('Laravel PR preview')->handler(PreviewSite::class)->register();

        RegisterWorkflowAction::make('pr-previews-manage')
            ->label('Manage Laravel PR preview')
            ->description($this->description)
            ->category('Site')
            ->handler(ManagePreview::class)
            ->register();

        RegisterWorkflowAction::make('pr-previews-prepare')
            ->label('Prepare Laravel PR preview')
            ->description('Provision the preview and configure its deployment script; connect to Vito Deploy Site next.')
            ->category('Site')
            ->handler(PreparePreview::class)
            ->register();

        RegisterWorkflowAction::make('pr-previews-verify')
            ->label('Verify Laravel PR preview')
            ->description('Verify the native deployment and preview health after Deploy Site.')
            ->category('Site')
            ->handler(VerifyPreview::class)
            ->register();
    }
}
