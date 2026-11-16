<?php
declare(strict_types=1);

use Fares\Dbman\Application;
use Fares\Dbman\Connection;
use Fares\Dbman\DatabaseManager;
use Fares\Dbman\SqlScript;

require __DIR__ . '/support.php';

$project = temporaryProject();
$keys = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS', 'APP_TIMEZONE'];
$environment = [];
foreach ($keys as $key) {
    $environment[$key] = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
    putenv($key);
    unset($_ENV[$key], $_SERVER[$key]);
}

try {
    $output = '';
    $error = '';
    $application = new Application(
        static function (string $text) use (&$output): void { $output .= $text; },
        static function (string $text) use (&$error): void { $error .= $text; },
    );
    foreach ([[], ['help'], ['--help'], ['-h'], ['--project-dir=/does/not/exist', 'help']] as $arguments) {
        check($application->run($arguments, '/missing') === 0, 'Help must work without a project or database.');
    }
    check(str_contains($output, 'fresh does not recreate'), 'Help must explain destructive command semantics.');
    foreach ([
        ['unknown'], ['status', '--seed'], ['migrate', '--seed', '--seed'],
        ['rollback', '--steps=0'], ['rollback', '--steps=-1'], ['rollback', '--steps=1.5'],
        ['rollback', '--steps=999999999999999999999999999999'], ['rollback', '--steps=2', '--steps=1'],
        ['mark', 'migration', 'one.sql'], ['mark', 'other', 'one.sql', '--force'],
        ['--project-dir'], ['--project-dir='], ['--project-dir=/tmp', '--project-dir=/tmp'],
    ] as $arguments) {
        check($application->run($arguments, $project) === 1, 'Invalid arguments must fail before connecting.');
    }
    check($application->run(['status'], '/missing') === 1, 'Missing projects must fail.');
    check(str_contains($error, 'Project directory does not exist'), 'Missing project error must be actionable.');
    $error = '';
    check($application->run(['status'], $project) === 1, 'Missing DB configuration must fail without connecting.');
    check(str_contains($error, 'DB_HOST'), 'Required settings must be named.');

    $special = $project . '/directory with [brackets]';
    mkdir($special);
    file_put_contents($special . '/2026_01_01_0000_table.sql', 'SELECT 1;');
    file_put_contents($special . '/2026_01_01_0001_table.down.sql', 'SELECT 1;');
    check(count(DatabaseManager::sqlFiles($special)) === 1, 'SQL discovery must support spaces and glob characters in project paths.');
    check(str_ends_with(DatabaseManager::rollbackPath($special . '/2026_01_01_0000_table.sql'), '0001_table.down.sql'), 'Rollback discovery must support literal project paths.');
    $migrations = $project . '/database/migrations';
    file_put_contents($migrations . '/b.sql', 'SELECT 2;');
    file_put_contents($migrations . '/a.sql', 'SELECT 1;');
    file_put_contents($migrations . '/a.down.sql', 'SELECT 1;');
    check(array_keys(DatabaseManager::sqlFiles($migrations)) === ['a.sql', 'b.sql'], 'Discovery must sort filenames and exclude down files.');
    check(DatabaseManager::rollbackPath($migrations . '/a.sql') === $migrations . '/a.down.sql', 'Exact rollback names must work.');
    check(DatabaseManager::rollbackPath($migrations . '/b.sql') === null, 'Missing rollback must return null.');
    fails(static fn () => DatabaseManager::sqlFiles($project . '/absent'), 'SQL directory is missing');
    check(DatabaseManager::sqlFiles($project . '/absent', false) === [], 'Missing optional seed directory must work.');
    $up = $migrations . '/2026_01_01_0000_create_items.sql';
    file_put_contents($up, 'SELECT 1;');
    file_put_contents($migrations . '/2026_01_01_0001_create_items.down.sql', 'SELECT 1;');
    check(str_ends_with(DatabaseManager::rollbackPath($up), '0001_create_items.down.sql'), 'Legacy rollback sequence must work.');
    file_put_contents($migrations . '/2026_01_01_0002_create_items.down.sql', 'SELECT 1;');
    fails(static fn () => DatabaseManager::rollbackPath($up), 'Multiple rollback files');
    file_put_contents(substr($up, 0, -4) . '.down.sql', 'SELECT 1;');
    check(DatabaseManager::rollbackPath($up) === substr($up, 0, -4) . '.down.sql', 'Exact rollback must take priority.');

    check(count(SqlScript::statements("SELECT 'a;b'; -- ignored ;\n SELECT \"c;d\"; # comment\n")) === 2, 'Strings and comments must not split SQL.');
    check(count(SqlScript::statements("INSERT INTO x VALUES ('it''s; ok', 'slash\\\\;');")) === 1, 'Escaped and doubled quotes must work.');
    check(SqlScript::statements("/* only comments */ -- end\n# end") === [], 'Comment-only scripts must have no statements.');
    foreach (["SELECT 'unfinished", 'SELECT "unfinished', 'SELECT `unfinished', '/* unfinished'] as $sql) {
        fails(static fn () => SqlScript::statements($sql), 'Unterminated SQL');
    }
    fails(static fn () => SqlScript::statements('/*!50000 DROP TABLE x */;'), 'Executable SQL comments');
    fails(static fn () => SqlScript::statements('/*M!100100 DROP TABLE x */;'), 'Executable SQL comments');
    fails(static fn () => SqlScript::statements("DELIMITER $$\nSELECT 1;"), 'DELIMITER');
    $seeder = SqlScript::seederSql("START TRANSACTION;\nINSERT INTO items VALUES (1, 'COMMIT;');\nCOMMIT;");
    check(str_starts_with($seeder, 'INSERT') && str_contains($seeder, "'COMMIT;'"), 'Only outer transaction wrappers may be removed.');
    foreach (['CREATE TABLE x (id INT);', 'SET autocommit=1;', 'COMMIT;', 'TRUNCATE x;', "INSERT INTO x VALUES (1); COMMIT;"] as $sql) {
        fails(static fn () => SqlScript::seederSql($sql), 'Seeders support');
    }
    fails(static fn () => SqlScript::seederSql('START TRANSACTION; INSERT INTO x VALUES (1);'), 'must end with COMMIT');
    fails(static fn () => SqlScript::seederSql('/* empty */'), 'at least one DML');
    check(SqlScript::createdTables("/* CREATE TABLE `wrong` (id INT); */ CREATE TABLE `right` (name TEXT DEFAULT 'CREATE TABLE bogus (id INT);');") === ['right'], 'Cleanup must ignore fake CREATE statements in literals/comments.');
    check(SqlScript::createdTables('CREATE TABLE IF NOT EXISTS plain (id INT); CREATE TABLE `odd``name` (id INT);') === ['odd`name', 'plain'], 'Cleanup must handle ordinary and escaped identifiers.');
    foreach (['CREATE TABLE other.items (id INT);', 'CREATE TABLE copy LIKE items;', 'CREATE TABLE copy AS SELECT 1;'] as $sql) {
        fails(static fn () => SqlScript::createdTables($sql), 'clean requires');
    }
    foreach (['RENAME TABLE x TO y;', 'ALTER TABLE x RENAME TO y;', "PREPARE stmt FROM 'CREATE TABLE x (id INT)';"] as $sql) {
        fails(static fn () => SqlScript::createdTables($sql), 'clean cannot infer');
    }
    fails(static fn () => SqlScript::createdTables('CREATE TABLE schema_migrations (id INT);'), 'tracking tables');

    file_put_contents($project . '/.env', "DB_HOST=127.0.0.1\nDB_NAME=fixture\nDB_USER=fixture\nDB_PASS='test only'\n");
    $configuration = Connection::configuration($project);
    check($configuration['port'] === 3306 && $configuration['timezone'] === 'UTC', 'Configuration defaults must be stable.');
    check($configuration['password'] === 'test only', 'Quoted passwords must parse.');
    check(!isset($_ENV['DB_NAME']) && getenv('DB_NAME') === false, 'Loading dotenv must not mutate global environment.');
    putenv('DB_NAME=process_fixture');
    $_ENV['DB_NAME'] = 'env_fixture';
    $_SERVER['DB_NAME'] = 'server_fixture';
    file_put_contents($project . '/.env', "DB_HOST=127.0.0.1\nDB_NAME=file_fixture\nDB_USER=\${DB_NAME}\nDB_PORT=3307\n");
    $configuration = Connection::configuration($project);
    check($configuration['database'] === 'process_fixture' && $configuration['user'] === 'process_fixture', 'Process values must override dotenv, including interpolation.');
    check($configuration['port'] === 3307, 'Explicit ports must be preserved.');
    putenv('DB_NAME');
    check(Connection::configuration($project)['database'] === 'env_fixture', 'ENV must take precedence over SERVER and file.');
    unset($_ENV['DB_NAME'], $_SERVER['DB_NAME']);
    foreach (['0', '65536', '-1', '1.2', 'invalid'] as $port) {
        putenv('DB_PORT=' . $port);
        fails(static fn () => Connection::configuration($project), 'DB_PORT');
    }
    putenv('DB_PORT');
    file_put_contents($project . '/.env', "DB_PASS=\"synthetic-sensitive-value\n");
    fails(static fn () => Connection::configuration($project), 'Unable to read or parse');
    $error = '';
    $application->run(['status'], $project);
    check(!str_contains($error, 'synthetic-sensitive-value'), 'Dotenv diagnostics must not disclose values.');
    check(Connection::timezoneOffset('UTC') === '+00:00', 'UTC must map to a database offset.');
    check(Connection::timezoneOffset('Africa/Cairo', new DateTimeImmutable('2026-01-15T00:00:00Z')) === '+02:00', 'Cairo winter offset must work.');
    check(Connection::timezoneOffset('Africa/Cairo', new DateTimeImmutable('2026-07-15T00:00:00Z')) === '+03:00', 'Cairo summer offset must work.');
    fails(static fn () => Connection::timezoneOffset(''), 'APP_TIMEZONE');
    fails(static fn () => Connection::timezoneOffset('not/a/timezone'), 'APP_TIMEZONE');

    [$code, $stdout, $stderr] = runProcess([PHP_BINARY, dirname(__DIR__) . '/bin/dbman', 'help'], $project);
    check($code === 0 && $stderr === '' && str_contains($stdout, 'dbman'), 'The executable must boot through its own Composer autoloader.');
    echo "Passed {$checks} offline checks.\n";
} finally {
    foreach ($environment as $key => [$process, $env, $server]) {
        putenv($process === false ? $key : $key . '=' . $process);
        if ($env === null) {
            unset($_ENV[$key]);
        } else {
            $_ENV[$key] = $env;
        }
        if ($server === null) {
            unset($_SERVER[$key]);
        } else {
            $_SERVER[$key] = $server;
        }
    }
    removeTemporaryProject($project);
}
