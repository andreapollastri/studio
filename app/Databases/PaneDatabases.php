<?php

namespace App\Databases;

use App\Bridge\BridgeClient;
use App\Bridge\BridgeException;
use App\Models\DatabaseConnection;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Server\ServerException;
use App\Server\StudioAdmin;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * The databases of the project's Db tab, as connections of Tools → Databases so the same
 * client opens them: Local is the person's workspace database (or its sandbox's), read
 * from the workspace's .env through the bridge; Develop is the project site's, which
 * only the server helper knows; both are managed connections of that person, refreshed
 * on every open. Remote are the Databases connections tied to the project they may use.
 */
class PaneDatabases
{
    public function __construct(private readonly StudioAdmin $admin) {}

    /** The database of a workspace, as its .env says; throws PaneDatabaseException when it cannot be opened from here. */
    public function workspace(Workspace $workspace, User $user, ?string $sandbox = null): DatabaseConnection
    {
        try {
            $file = (new BridgeClient($workspace, $sandbox))->readFile('.env');
        } catch (BridgeException $e) {
            throw new PaneDatabaseException(__('The workspace\'s .env could not be read: :reason', ['reason' => $e->getMessage()]), previous: $e);
        }

        try {
            $config = ConnectionString::parse((string) ($file['content'] ?? ''));
        } catch (InvalidArgumentException $e) {
            throw new PaneDatabaseException(__('The workspace\'s .env names no database: :reason', ['reason' => $e->getMessage()]), previous: $e);
        }

        $driver = Driver::from($config['driver']);

        if ($driver === Driver::Sqlite || $driver->isRedis()) {
            throw new PaneDatabaseException(__('The workspace uses :driver, which the Db tab does not open: only MySQL, MariaDB and PostgreSQL.', ['driver' => $driver->label()]));
        }

        $project = $workspace->project;

        return $this->managed($user, 'workspace:'.$workspace->id.($sandbox !== null ? ':'.$sandbox : ''), $project->name.' · '.($sandbox !== null ? __('sandbox') : __('workspace')), [
            // the database is the project's own engine; DB_CONNECTION=mysql may well be MariaDB
            'driver' => $driver === Driver::Mysql && $project->db_engine === 'mariadb' ? Driver::Mariadb : $driver,
            'environment' => Environment::Local,
            'host' => $config['host'] ?? '127.0.0.1',
            'port' => $config['port'] ?? $driver->defaultPort(),
            'username' => $config['username'],
            'password' => $config['password'],
            'database' => $config['database'],
        ]);
    }

    /** The database of the project's own site on this server (prj_<slug>). */
    public function site(Project $project, User $user): DatabaseConnection
    {
        try {
            $credentials = $this->admin->run('project-db', [$project->slug]);
        } catch (ServerException $e) {
            throw new PaneDatabaseException($e->getMessage(), previous: $e);
        }

        $driver = $project->db_engine === 'pgsql' ? Driver::Pgsql : Driver::Mariadb;

        return $this->managed($user, 'site:'.$project->id, $project->name.' · '.__('develop'), [
            'driver' => $driver,
            'environment' => Environment::Staging,
            'host' => '127.0.0.1',
            'port' => $driver->defaultPort(),
            'username' => (string) ($credentials['username'] ?? ''),
            'password' => (string) ($credentials['password'] ?? ''),
            'database' => (string) ($credentials['database'] ?? ''),
        ]);
    }

    /**
     * The connections of Tools → Databases tied to the project that this person may use.
     *
     * @return Collection<int, DatabaseConnection>
     */
    public function remote(Project $project, User $user): Collection
    {
        return DatabaseConnection::query()
            ->visibleTo($user)
            ->where('project_id', $project->id)
            ->with(['project', 'user', 'sshConnection'])
            ->orderBy('name')
            ->get()
            ->filter(fn (DatabaseConnection $connection) => $connection->accessFor($user) !== null)
            ->values();
    }

    /** @param  array<string, mixed>  $attributes */
    private function managed(User $user, string $key, string $name, array $attributes): DatabaseConnection
    {
        $connection = DatabaseConnection::query()->where('user_id', $user->id)->where('managed', $key)->first();

        if ($connection === null) {
            // a connection of the person's own may already have that name
            $taken = DatabaseConnection::query()->where('user_id', $user->id)->where('name', 'like', $name.'%')->pluck('name')->all();
            $free = $name;
            for ($n = 2; in_array($free, $taken, true); $n++) {
                $free = "{$name} ({$n})";
            }

            $connection = new DatabaseConnection(['user_id' => $user->id, 'managed' => $key, 'name' => $free]);
        }

        $connection->fill([...$attributes, 'project_id' => null, 'project_access' => null, 'ssh_connection_id' => null, 'tls' => false])->save();

        return $connection;
    }
}
