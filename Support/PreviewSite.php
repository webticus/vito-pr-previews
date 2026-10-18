<?php

declare(strict_types=1);

namespace App\Vito\Plugins\Webticus\VitoPrPreviews\Support;

use App\Models\Site;
use App\SiteTypes\PHPSite;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class PreviewSite extends PHPSite
{
    public static function id(): string
    {
        return 'pr-preview';
    }

    public static function make(): self
    {
        return new self(new Site(['type' => self::id()]));
    }

    protected function deployKey(): void
    {
        $source = $this->site->sourceControl;
        if (! $source || $source->isGithubApp()) {
            return;
        }

        if ($source->provider()::id() !== 'github') {
            throw new RuntimeException('PR previews require GitHub source control.');
        }

        if (! $this->site->getRawOriginal('ssh_key')) {
            $name = 'site_'.$this->site->id;
            $os = $this->site->server->os();
            $os->generateSSHKey($name, $this->site);
            $this->site->ssh_key = $os->readSSHKey($name, $this->site);
            $this->site->save();
        }

        if (empty($this->site->type_data['deploy_key_id'])) {
            $response = Http::withToken($source->provider()->data()['token'])->acceptJson()->timeout(30)
                ->post('https://api.github.com/repos/'.$this->site->repository.'/keys', [
                    'title' => $this->site->getDeployKeyName(), 'key' => $this->site->ssh_key, 'read_only' => true,
                ]);
            if ($response->status() !== 201 || ! $response->json('id')) {
                throw new RuntimeException('GitHub rejected the preview read-only deploy key.');
            }
            $this->site->jsonUpdate('type_data', 'deploy_key_id', $response->json('id'));
        }
    }
}
