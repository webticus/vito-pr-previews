<?php

declare(strict_types=1);

namespace App\Vito\Plugins\Webticus\VitoPrPreviews\Support;

use App\Events\SiteDeletedEvent;
use App\Models\Site;

final class DeletePreview
{
    public function delete(Site $site): void
    {
        $lock = $site->isolatedUser?->lock() ?? $site->server->isolatedUserLock($site->user);
        $lock->block(30);
        try {
            $site->webserver()->deleteSite($site);
            $server = $site->server;
            $id = $site->id;
            $domain = $site->domain;
            $site->delete();
            SiteDeletedEvent::dispatch($server, $id, $domain);
        } finally {
            $lock->release();
        }
    }
}
