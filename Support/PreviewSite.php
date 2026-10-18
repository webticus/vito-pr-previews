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

        $isolatedUser = $this->site->isolatedUser;
        if (! $isolatedUser) {
            throw new RuntimeException('Previews require a dedicated shared Vito site user.');
        }
        $lock = $isolatedUser->lock();
        $lock->block(30);
        try {
            $isolatedUser->refresh();
            $name = 'iuser_'.$isolatedUser->id;
            $os = $this->site->server->os();
            if (! $isolatedUser->ssh_key) {
                $os->generateSSHKey($name, $this->site);
                $isolatedUser->ssh_key = $os->readSSHKey($name, $this->site);
                $isolatedUser->save();
            }
            $this->site->setRelation('isolatedUser', $isolatedUser);
            $client = Http::withToken($source->provider()->data()['token'])->acceptJson()->timeout(30);
            $endpoint = 'https://api.github.com/repos/'.$this->site->repository.'/keys';
            $keyId = null;
            $publicKey = implode(' ', array_slice(preg_split('/\\s+/', trim($isolatedUser->ssh_key)), 0, 2));
            for ($page = 1; $page <= 100; $page++) {
                $response = $client->get($endpoint, ['per_page' => 100, 'page' => $page]);
                if (! $response->successful()) {
                    throw new RuntimeException('Could not look up the persistent preview deploy key on GitHub.');
                }
                foreach ($response->json() as $key) {
                    if (implode(' ', array_slice(preg_split('/\\s+/', trim($key['key'])), 0, 2)) === $publicKey) {
                        if (! $key['read_only']) {
                            throw new RuntimeException('The shared preview deploy key must be read-only.');
                        }
                        $keyId = $key['id'];
                        break 2;
                    }
                }
                if (count($response->json()) < 100) {
                    break;
                }
            }
            if ($keyId === null) {
                $response = $client->post($endpoint, [
                    'title' => 'Vito PR previews ('.$this->site->user.')', 'key' => $isolatedUser->ssh_key, 'read_only' => true,
                ]);
                if ($response->status() !== 201 || ! $response->json('id')) {
                    throw new RuntimeException('GitHub rejected the shared read-only deploy key. Use a dedicated site user per repository or a GitHub App connection.');
                }
                $keyId = $response->json('id');
            }
            $this->site->jsonUpdate('type_data', 'pr_preview_deploy_key_id', $keyId);
        } finally {
            $lock->release();
        }
    }
}
