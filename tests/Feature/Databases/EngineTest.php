<?php

use App\Databases\ConnectionString;
use App\Databases\Redis\Keyspace;
use App\Databases\Redis\RedisError;
use App\Databases\Redis\RespClient;
use App\Databases\Statements;
use App\Livewire\Tools\RedisClient;
use App\Models\DatabaseConnection;
use App\Models\SshConnection;
use App\Models\User;

test('links are read the way TablePlus writes them, for every kind of database', function (string $link, array $expected) {
    $parsed = ConnectionString::parse($link);

    foreach ($expected as $key => $value) {
        expect($parsed[$key])->toBe($value, $key);
    }
})->with([
    'mariadb through ssh' => ['mariadb+ssh://ssh_user:Ssh-pw1@203.0.113.9/db_user:Db-pw2@127.0.0.1/appdb', [
        'driver' => 'mariadb', 'host' => '127.0.0.1', 'port' => null, 'username' => 'db_user', 'password' => 'Db-pw2', 'database' => 'appdb',
        'ssh' => ['host' => '203.0.113.9', 'port' => 22, 'username' => 'ssh_user', 'password' => 'Ssh-pw1'],
    ]],
    'mysql with port and options' => ['mysql://root:p%40ss@db.example.com:3307/shop?name=Shop&env=prod', ['driver' => 'mysql', 'port' => 3307, 'password' => 'p@ss', 'name' => 'Shop', 'environment' => 'production', 'ssh' => null]],
    'an @ in a password' => ['mysql://u:p@w@rd@host/db', ['host' => 'host', 'password' => 'p@w@rd']],
    'postgres with tls' => ['postgres://u:p@h:6432/db?sslmode=require', ['driver' => 'pgsql', 'port' => 6432, 'tls' => true]],
    'postgres through ssh on a port' => ['postgresql+ssh://deploy@10.0.0.1:2222/app:pw@localhost/app', ['driver' => 'pgsql', 'host' => 'localhost', 'ssh' => ['host' => '10.0.0.1', 'port' => 2222, 'username' => 'deploy', 'password' => null]]],
    'sqlite under the home' => ['sqlite+ssh://deploy@web1/~/app/database/database.sqlite', ['driver' => 'sqlite', 'database' => '~/app/database/database.sqlite']],
    'sqlite absolute' => ['sqlite+ssh://deploy@web1//var/www/app.sqlite', ['database' => '/var/www/app.sqlite']],
    'sqlite here' => ['sqlite:///var/db.sqlite', ['database' => '/var/db.sqlite', 'ssh' => null]],
    'redis over tls' => ['rediss://:secret@cache.example.com:6380/2', ['driver' => 'redis', 'tls' => true, 'password' => 'secret', 'username' => null, 'database' => '2']],
    'valkey through ssh' => ['valkey+ssh://deploy@web1/:pw@127.0.0.1/0', ['driver' => 'valkey', 'password' => 'pw', 'database' => '0']],
    'ipv6' => ['mysql://u:p@[2001:db8::1]:3306/db', ['host' => '2001:db8::1', 'port' => 3306]],
]);

test('a link written for a connection is read back the same', function () {
    $owner = User::factory()->create();
    $ssh = SshConnection::factory()->for($owner)->create(['host' => '203.0.113.9', 'username' => 'deploy', 'password' => 'S/sh@pw']);
    $connection = DatabaseConnection::factory()->for($owner)->create(['driver' => 'mariadb', 'host' => '127.0.0.1', 'port' => 3306, 'username' => 'app', 'password' => 'p@ss/w:rd?', 'database' => 'shop', 'ssh_connection_id' => $ssh->id, 'environment' => 'staging', 'name' => 'Shop staging']);

    $link = ConnectionString::build($connection->load('sshConnection'), true);
    $parsed = ConnectionString::parse($link);

    expect($link)->toStartWith('mariadb+ssh://deploy:')
        ->and($parsed)->toMatchArray(['driver' => 'mariadb', 'host' => '127.0.0.1', 'username' => 'app', 'password' => 'p@ss/w:rd?', 'database' => 'shop', 'environment' => 'staging', 'name' => 'Shop staging'])
        ->and($parsed['ssh'])->toBe(['host' => '203.0.113.9', 'port' => 22, 'username' => 'deploy', 'password' => 'S/sh@pw'])
        ->and(ConnectionString::build($connection))->not->toContain('p%40ss');
});

test('SQL is split into statements as databases read it', function () {
    expect(Statements::split("SELECT 'a;b''c'; -- x;y\nINSERT INTO `t;` VALUES (\"q\\\";\");\n/* ; */\nDELIMITER //\nCREATE PROCEDURE p() BEGIN SELECT 1; END//\nDELIMITER ;\nSELECT 2", 'mysql'))
        ->toBe(["SELECT 'a;b''c'", "-- x;y\nINSERT INTO `t;` VALUES (\"q\\\";\")", 'CREATE PROCEDURE p() BEGIN SELECT 1; END', 'SELECT 2'])
        ->and(Statements::split('CREATE FUNCTION f() RETURNS int AS $body$ BEGIN RETURN 1; END; $body$ LANGUAGE plpgsql; SELECT $1', 'pgsql'))->toHaveCount(2)
        ->and(Statements::split('CREATE TRIGGER t AFTER INSERT ON a BEGIN UPDATE b SET c = 1; DELETE FROM d; END; SELECT 1;', 'sqlite'))->toHaveCount(2)
        ->and(Statements::split("-- only a comment\n;;", 'mysql'))->toBe([]);

    // fed in small chunks, a long dump gives the same statements
    $dump = str_repeat("INSERT INTO t VALUES (1, 'a;b''c'), (2, \$\$x;y\$\$);\n", 500);
    $splitter = new Statements('pgsql');
    $count = 0;
    foreach (str_split($dump, 23) as $chunk) {
        $count += count($splitter->feed($chunk));
    }
    expect($count + count($splitter->finish()))->toBe(500);
});

test('statements that only read are told from those that write', function (string $sql, bool $read) {
    expect(Statements::isRead($sql, 'pgsql'))->toBe($read);
})->with([
    ['SELECT * FROM users', true],
    ["SELECT 'delete me' FROM t", true],
    ['explain select 1', true],
    ['EXPLAIN ANALYZE DELETE FROM t', false],
    ['WITH gone AS (DELETE FROM t RETURNING *) SELECT * FROM gone', false],
    ['SELECT * INTO copy FROM t', false],
    ['PRAGMA user_version = 3', false],
    ['UPDATE t SET a = 1', false],
    ['/* select */ DROP TABLE t', false],
]);

test('RESP is spoken both ways: commands, replies, errors, pipelines', function () {
    [$ours, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    fwrite($server, "+OK\r\n\$5\r\nhello\r\n:42\r\n\$-1\r\n*2\r\n\$1\r\na\r\n*1\r\n:1\r\n-WRONGTYPE Operation against a key\r\n+PONG\r\n:7\r\n");
    $client = new RespClient($ours);

    expect($client->command('SET', 'greeting', 'hello'))->toBe('OK')
        ->and(fread($server, 1024))->toBe("*3\r\n\$3\r\nSET\r\n\$8\r\ngreeting\r\n\$5\r\nhello\r\n")
        ->and($client->command('GET', 'greeting'))->toBe('hello')
        ->and($client->command('INCR', 'n'))->toBe(42)
        ->and($client->command('GET', 'none'))->toBeNull()
        ->and($client->command('X'))->toBe(['a', [1]])
        ->and(fn () => $client->command('LPUSH', 'greeting', 'x'))->toThrow(RedisError::class, 'WRONGTYPE');

    $replies = $client->pipeline([['PING'], ['DBSIZE']]);
    expect($replies)->toBe(['PONG', 7])
        ->and(RespClient::parse('SET "a b" \'c d\' "x\\ny" plain'))->toBe(['SET', 'a b', 'c d', "x\ny", 'plain'])
        ->and(RedisClient::format(['a', null, 3]))->toBe("1) \"a\"\n2) (nil)\n3) (integer) 3")
        ->and(Keyspace::isRead('hgetall'))->toBeTrue()
        ->and(Keyspace::isRead('FLUSHALL'))->toBeFalse();
});
