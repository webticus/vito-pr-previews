<?php

declare(strict_types=1);

namespace App\Vito\Plugins\Webticus\VitoPrPreviews\Support;

use InvalidArgumentException;

final class PreviewEnvironment
{
    public static function render(PreviewIdentity $identity, string $path, string $extra, array $database = ['driver' => 'sqlite'], bool $sslEnabled = true): string
    {
        $values = [
            'APP_NAME' => "PR Preview {$identity->number}",
            'APP_ENV' => 'preview',
            'APP_DEBUG' => 'false',
            'APP_URL' => ($sslEnabled ? 'https://' : 'http://').$identity->domain,
            'APP_MAINTENANCE_DRIVER' => 'file',
            'LOG_CHANNEL' => 'single',
            'LOG_LEVEL' => 'warning',
            'DB_CONNECTION' => $database['driver'],
            'DB_DATABASE' => $database['driver'] === 'sqlite' ? "{$path}/shared/database.sqlite" : $database['database'],
            'SESSION_DRIVER' => 'file',
            'SESSION_COOKIE' => str_replace('-', '_', $identity->username),
            'SESSION_DOMAIN' => $identity->domain,
            'SESSION_SECURE_COOKIE' => $sslEnabled ? 'true' : 'false',
            'SESSION_ENCRYPT' => 'true',
            'CACHE_STORE' => 'file',
            'CACHE_PREFIX' => $identity->username,
            'QUEUE_CONNECTION' => 'database',
            'BROADCAST_CONNECTION' => 'log',
            'FILESYSTEM_DISK' => 'local',
            'MAIL_MAILER' => 'log',
            'MAIL_FROM_ADDRESS' => 'preview@example.invalid',
            'MAIL_FROM_NAME' => 'PR Preview',
        ];
        if ($database['driver'] !== 'sqlite') {
            foreach (['host', 'port', 'username', 'password'] as $key) {
                $values['DB_'.strtoupper($key)] = $database[$key];
            }
        }
        $additional = json_decode($extra, true, 32, JSON_THROW_ON_ERROR);

        if (! is_array($additional) || ($additional !== [] && array_is_list($additional))) {
            throw new InvalidArgumentException('Environment must be a JSON object of scalar values.');
        }

        foreach ($additional as $key => $value) {
            if (! is_string($key) || ! preg_match('/\A[A-Z][A-Z0-9_]*\z/', $key) || ! is_scalar($value)
                || isset($values[$key]) || $key === 'APP_KEY'
                || preg_match('/\A(?:DB_|MAIL_|SESSION_|CACHE_|QUEUE_|AWS_|REDIS_)/', $key)) {
                throw new InvalidArgumentException('Environment cannot override preview isolation settings.');
            }

            $text = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            $values[$key] = str_replace(['{pr}', '{domain}'], [(string) $identity->number, $identity->domain], $text);
        }

        $lines = [];

        foreach ($values as $key => $value) {
            if (preg_match('/[\r\n\x00]/', $value)) {
                throw new InvalidArgumentException('Environment values must not contain newlines or NUL bytes.');
            }

            $escaped = str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value);
            $lines[] = "{$key}=\"{$escaped}\"";
        }

        return implode("\n", $lines)."\n";
    }
}
