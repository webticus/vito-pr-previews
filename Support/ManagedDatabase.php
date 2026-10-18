<?php

declare(strict_types=1);

namespace App\Vito\Plugins\Webticus\VitoPrPreviews\Support;

use App\Actions\Database\CreateDatabase;
use App\Actions\Database\CreateDatabaseUser;
use App\Actions\Database\DeleteDatabase;
use App\Actions\Database\DeleteDatabaseUser;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

final readonly class ManagedDatabase
{
    public function __construct(private User $actor) {}

    public function ensure(Site $site, PreviewIdentity $identity, string $driver): array
    {
        $metadata = $site->type_data['pr_preview'];
        $previous = $metadata['database_driver'] ?? (isset($metadata['sha']) ? 'sqlite' : null);

        if ($previous !== null && $previous !== $driver) {
            throw new RuntimeException('Remove and recreate the preview to change its database driver.');
        }

        $metadata['database_driver'] = $driver;
        $site->jsonUpdate('type_data', 'pr_preview', $metadata);

        if ($driver === 'sqlite') {
            return ['driver' => 'sqlite'];
        }

        $server = $site->server;
        $service = $server->database();

        if (! $service || ! in_array($service->handler()::id(), ['mysql', 'mariadb'], true)
            || ($driver === 'mariadb' && $service->handler()::id() !== 'mariadb')) {
            throw new RuntimeException('The requested MySQL/MariaDB service must be installed as the server default database.');
        }

        $name = str_replace('-', '', $identity->username);
        $database = isset($metadata['database_id']) ? $server->databases()->find($metadata['database_id']) : null;

        if (! $database) {
            if ($server->databases()->where('name', $name)->exists()) {
                throw new RuntimeException('Refusing to adopt a database without preview ownership metadata.');
            }

            Gate::forUser($this->actor)->authorize('create', [Database::class, $server]);
            $database = app(CreateDatabase::class)->create($server, [
                'name' => $name, 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
            ]);
            $metadata['database_id'] = $database->id;
            $site->jsonUpdate('type_data', 'pr_preview', $metadata);
        }

        if ($database->name !== $name) {
            throw new RuntimeException('Preview database ownership does not match.');
        }

        $user = isset($metadata['database_user_id']) ? $server->databaseUsers()->find($metadata['database_user_id']) : null;

        if (! $user) {
            if ($server->databaseUsers()->where('username', $name)->exists()) {
                throw new RuntimeException('Refusing to adopt a database user without preview ownership metadata.');
            }

            Gate::forUser($this->actor)->authorize('create', [DatabaseUser::class, $server]);
            $user = app(CreateDatabaseUser::class)->create($server, [
                'username' => $name,
                'password' => bin2hex(random_bytes(24)),
                'host' => 'localhost',
                'permission' => 'admin',
            ], [$database->name]);
            $metadata['database_user_id'] = $user->id;
            $site->jsonUpdate('type_data', 'pr_preview', $metadata);
        }

        if ($user->username !== $name || $user->host !== 'localhost' || $user->databases !== [$name]) {
            throw new RuntimeException('Preview database user must have access to its own database only.');
        }

        return ['driver' => $driver, 'host' => '127.0.0.1', 'port' => '3306', 'database' => $name, 'username' => $user->username, 'password' => $user->password];
    }

    public function destroy(Site $site, PreviewIdentity $identity): void
    {
        $metadata = $site->type_data['pr_preview'];
        $server = $site->server;
        $name = str_replace('-', '', $identity->username);
        $database = isset($metadata['database_id']) ? $server->databases()->find($metadata['database_id']) : null;
        $user = isset($metadata['database_user_id']) ? $server->databaseUsers()->find($metadata['database_user_id']) : null;

        if (($database && $database->name !== $name)
            || ($user && ($user->username !== $name || $user->host !== 'localhost'
                || array_diff($user->databases ?? [], [$name]) !== []))) {
            throw new RuntimeException('Refusing to delete database resources with mismatched ownership.');
        }

        // Keep the site and its ownership IDs until database cleanup succeeds so failed teardown can be retried.
        if ($user) {
            Gate::forUser($this->actor)->authorize('delete', [$user, $server]);
            app(DeleteDatabaseUser::class)->delete($server, $user);
        }

        if ($database) {
            Gate::forUser($this->actor)->authorize('delete', [$database, $server]);
            app(DeleteDatabase::class)->delete($server, $database);
        }
    }
}
