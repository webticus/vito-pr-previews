<?php

declare(strict_types=1);

use App\Vito\Plugins\Webticus\VitoPrPreviews\Support\PreviewEnvironment;
use App\Vito\Plugins\Webticus\VitoPrPreviews\Support\PreviewIdentity;
use App\Vito\Plugins\Webticus\VitoPrPreviews\Support\WorkflowBlueprint;

require __DIR__.'/../Support/PreviewIdentity.php';
require __DIR__.'/../Support/PreviewEnvironment.php';
require __DIR__.'/../Support/WorkflowBlueprint.php';

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
check($identity->username === (new PreviewIdentity('example/website', 13, 'website-pr-{pr}.example.com'))->username, 'PRs must share the configured site user.');
check((new PreviewIdentity('example/website', 13, 'website-pr-{pr}.example.com', 'custom-previews'))->username === 'custom-previews', 'The shared user must be configurable.');
check($identity->resourceName !== (new PreviewIdentity('example/website', 13, 'website-pr-{pr}.example.com'))->resourceName, 'Databases must stay distinct across PRs.');
check($identity->resourceName !== (new PreviewIdentity('example/other', 12, 'other-pr-{pr}.example.com'))->resourceName, 'Databases must stay distinct across repositories.');
rejects(fn () => new PreviewIdentity('example/website', 12, 'pr-{pr}.example.com', 'invalid;id'));

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

$sql = PreviewEnvironment::render($identity, '/home/preview/app', '{}', [
    'driver' => 'mariadb', 'database' => 'prv123', 'host' => '127.0.0.1',
    'port' => '3306', 'username' => 'prv123', 'password' => 'test-password',
]);
check(str_contains($sql, 'DB_CONNECTION="mariadb"'), 'Selected SQL driver must be used.');
check(str_contains($sql, 'DB_DATABASE="prv123"') && str_contains($sql, 'DB_USERNAME="prv123"'), 'SQL resources must use dedicated credentials.');
$http = PreviewEnvironment::render($identity, '/home/preview/app', '{}', sslEnabled: false);
check(str_contains($http, 'APP_URL="http://website-pr-12.example.com"'), 'SSL must be optional.');
check(str_contains($http, 'SESSION_SECURE_COOKIE="false"'), 'HTTP previews must have usable session cookies.');

$custom = PreviewEnvironment::render($identity, '/home/preview/app', json_encode([
    'DB_HOST' => 'db.example.com', 'DB_DATABASE' => 'custom', 'MAIL_MAILER' => 'smtp',
    'SESSION_DRIVER' => 'redis', 'CACHE_STORE' => 'redis', 'QUEUE_CONNECTION' => 'redis',
    'REDIS_HOST' => 'redis.example.com', 'AWS_SECRET_ACCESS_KEY' => 'example', 'APP_DEBUG' => true, 'APP_KEY' => 'custom-key',
], JSON_THROW_ON_ERROR), ['driver' => 'external']);
foreach (['DB_HOST="db.example.com"', 'DB_DATABASE="custom"', 'MAIL_MAILER="smtp"', 'QUEUE_CONNECTION="redis"', 'AWS_SECRET_ACCESS_KEY="example"', 'APP_KEY="custom-key"', 'APP_DEBUG="true"'] as $expected) {
    check(str_contains($custom, $expected), 'Explicit environment settings must override defaults.');
}
check(! str_contains($custom, 'DB_USERNAME='), 'External database credentials must come entirely from the configuration.');

rejects(fn () => PreviewEnvironment::render($identity, '/home/preview/app', '{"NAME":"line\nINJECTED=value"}'));
rejects(fn () => PreviewEnvironment::render($identity, '/home/preview/app', '["value"]'));
rejects(fn () => PreviewEnvironment::render($identity, '/home/preview/app', 'null'));

echo "Preview identity and environment safety checks passed.\n";

$deploy = WorkflowBlueprint::make(4);
$cleanup = WorkflowBlueprint::make(4, true);
check(count($deploy['nodes']) === 3 && count($deploy['edges']) === 2, 'Setup must wire Prepare, native Deploy Site and Verify.');
check($deploy['nodes'][1]['data']['action']['handler'] === 'App\\WorkflowActions\\Site\\DeploySite', 'The build must use the native Deploy Site action.');
check($deploy['nodes'][2]['data']['action']['inputs']['deployment_id'] === '{deployment_id}', 'Verification must use the native deployment output.');
check($cleanup['nodes'][0]['data']['action']['inputs']['operation'] === 'destroy', 'Cleanup must default to destroy.');
check($cleanup['nodes'][0]['data']['action']['inputs']['server_id'] === 4, 'Setup must select the current server.');
echo "Workflow setup wiring checks passed.\n";
