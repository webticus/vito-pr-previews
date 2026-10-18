<?php

declare(strict_types=1);

namespace App\Vito\Plugins\Webticus\VitoPrPreviews\Support;

use InvalidArgumentException;

final readonly class PreviewIdentity
{
    public string $domain;

    public string $username;

    public string $resourceName;

    public function __construct(public string $repository, public int $number, string $pattern, string $siteUser = 'pr-previews')
    {
        if (! preg_match('/\A[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+\z/', $repository) || $number < 1 || $number > 9999999) {
            throw new InvalidArgumentException('A repository in owner/name format and a positive PR number are required.');
        }

        if (substr_count($pattern, '{pr}') !== 1) {
            throw new InvalidArgumentException('The domain pattern must contain exactly one {pr} placeholder.');
        }

        $domain = strtolower(str_replace('{pr}', (string) $number, $pattern));

        if (strlen($domain) > 253 || ! preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/', $domain)) {
            throw new InvalidArgumentException('The preview domain is not a valid DNS hostname.');
        }

        $this->domain = $domain;
        if (! preg_match('/\A[a-z_][a-z0-9_-]{1,30}[a-z0-9]\z/', $siteUser)) {
            throw new InvalidArgumentException('A valid shared Linux site user is required (3-32 characters).');
        }

        $this->username = $siteUser;
        $this->resourceName = 'prv'.substr(hash('sha256', strtolower($repository)), 0, 8).$number;
    }

    public function marker(string $requestId, string $operation, string $sha): string
    {
        if (! preg_match('/\A[a-f0-9]{32}\z/', $requestId) || ! preg_match('/\A[a-f0-9]{40}\z/', $sha)
            || ! in_array($operation, ['deploy', 'destroy'], true)) {
            throw new InvalidArgumentException('Invalid deployment identity.');
        }

        return "PREVIEW_OK:{$requestId}:{$operation}:{$this->number}:{$sha}";
    }
}
