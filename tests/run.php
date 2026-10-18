<?php

declare(strict_types=1);

use App\Vito\Plugins\Webticus\VitoPrPreviews\Support\PreviewEnvironment;
use App\Vito\Plugins\Webticus\VitoPrPreviews\Support\PreviewIdentity;

require __DIR__.'/../Support/PreviewIdentity.php';
require __DIR__.'/../Support/PreviewEnvironment.php';

function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function rejects(Closure $callback): void
{
    try {
        $callback();
    } catch (InvalidArgumentException|JsonException) {
        return;
    }

    throw new RuntimeException('Expected unsafe configuration to be rejected.');
}

$identity = new PreviewIdentity('example/website', 12, 'website-pr-{pr}.example.com');
check($identity->domain === 'website-pr-12.example.com', 'Preview domain must include the PR number.');
check($identity->username !== (new PreviewIdentity('example/other', 12, 'other-pr-{pr}.example.com'))->username, 'Repositories must have distinct users.');
check(strlen((new PreviewIdentity('example/website', 9999999, 'pr-{pr}.example.com'))->username) <= 32, 'Linux usernames must remain valid.');

foreach (['*.example.com', 'pr-{pr}-{pr}.example.com', 'pr-{pr}.example.com;id', 'pr-{pr}.-example.com'] as $pattern) {
    rejects(fn () => new PreviewIdentity('example/website', 12, $pattern));
}

rejects(fn () => new PreviewIdentity('example/../website', 12, 'pr-{pr}.example.com'));
rejects(fn () => new PreviewIdentity('example/website', 0, 'pr-{pr}.example.com'));
rejects(fn () => $identity->marker('invalid', 'destroy', str_repeat('a', 40)));

$environment = PreviewEnvironment::render($identity, '/home/preview/app', '{"APP_LOCALE":"bg","VITE_APP_NAME":"Preview $name","TEST_HOST":"{domain}"}');
check(str_contains($environment, 'DB_CONNECTION="sqlite"'), 'Database must be isolated.');
check(str_contains($environment, 'MAIL_MAILER="log"'), 'Preview mail must not leave the server.');
check(str_contains($environment, 'APP_DEBUG="false"'), 'Public previews must hide debug output.');
check(str_contains($environment, 'APP_LOCALE="bg"'), 'App-specific settings must be supported.');
check(str_contains($environment, '\\$name'), 'Dotenv interpolation must be escaped.');
check(str_contains($environment, 'TEST_HOST="website-pr-12.example.com"'), 'App-specific host placeholders must resolve.');

foreach (['DB_HOST', 'MAIL_MAILER', 'APP_DEBUG', 'APP_KEY', 'QUEUE_CONNECTION', 'AWS_SECRET_ACCESS_KEY'] as $key) {
    rejects(fn () => PreviewEnvironment::render($identity, '/home/preview/app', json_encode([$key => 'unsafe'], JSON_THROW_ON_ERROR)));
}

rejects(fn () => PreviewEnvironment::render($identity, '/home/preview/app', '{"NAME":"line\nINJECTED=value"}'));
rejects(fn () => PreviewEnvironment::render($identity, '/home/preview/app', '["value"]'));
rejects(fn () => PreviewEnvironment::render($identity, '/home/preview/app', 'null'));

echo "Preview identity and environment safety checks passed.\n";
