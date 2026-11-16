<?php
declare(strict_types=1);

use Fares\Dbman\Application;
use Fares\Dbman\DatabaseManager;

require __DIR__ . '/support.php';

// Never consume the application's DB_* variables or its .env file.
if (getenv('DBMAN_TEST_ALLOW_CREATE') !== '1'
    || !in_array(getenv('DBMAN_TEST_HOST'), ['127.0.0.1', 'localhost'], true)
    || filter_var(getenv('DBMAN_TEST_PORT'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false
    || !getenv('DBMAN_TEST_USER')) {
    fwrite(STDERR, "Set DBMAN_TEST_ALLOW_CREATE=1, DBMAN_TEST_HOST=127.0.0.1, DBMAN_TEST_PORT and DBMAN_TEST_USER for an explicitly disposable local database server.\n");
    exit(1);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$host = getenv('DBMAN_TEST_HOST');
$port = (int) getenv('DBMAN_TEST_PORT');
$user = getenv('DBMAN_TEST_USER');
$password = getenv('DBMAN_TEST_PASS') ?: '';
$database = 'dbman_test_' . bin2hex(random_bytes(8));
$db = new mysqli($host, $user, $password, '', $port);
$db->set_charset('utf8mb4');
$created = false;
$project = temporaryProject();
$migrations = $project . '/database/migrations';
$seeders = $project . '/database/seeders';
$first = '2026_01_01_0000_create_items.sql';
$second = '2026_01_02_0000_create_notes.sql';
$firstDown = $migrations . '/2026_01_01_0000_create_items.down.sql';
$output = '';
$manager = new DatabaseManager($db, $migrations, $seeders, static function (string $text) use (&$output): void { $output .= $text; });
$scalar = static function (string $sql) use ($db): mixed {
    $result = $db->query($sql);
    $value = $result->fetch_row()[0];
    $result->free();
    return $value;
};
$tables = static function () use ($db): array {
    $result = $db->query('SHOW TABLES');
    $names = array_column($result->fetch_all(), 0);
    $result->free();
    sort($names);
    return $names;
};
try {
    // CREATE without IF NOT EXISTS ensures this run can never adopt another schema.
    $db->query("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $created = true;
    $db->select_db($database);
    foreach (glob(__DIR__ . '/fixtures/database/migrations/*.sql') as $file) {
        copy($file, $migrations . '/' . basename($file));
    }
    foreach (glob(__DIR__ . '/fixtures/database/seeders/*.sql') as $file) {
        copy($file, $seeders . '/' . basename($file));
    }

    $manager->run('status');
    check($tables() === [], 'Status must create no tracking or application tables.');
    check(substr_count($output, '[pending]') === 3, 'Status must list pending migrations and seeders.');
    fails(static fn () => $manager->run('seed'), 'Cannot seed while migrations are pending');
    check($tables() === ['schema_migrations', 'schema_seeders'], 'Pending-seed rejection must not create application tables.');
    $manager->run('migrate');
    check((int) $scalar('SELECT COUNT(*) FROM schema_migrations') === 2, 'Migrate must record each successful migration.');
    check((int) $scalar('SELECT COUNT(*) FROM items') === 0, 'Migrate must not seed by default.');
    $manager->run('migrate');
    check((int) $scalar('SELECT COUNT(*) FROM schema_migrations') === 2, 'Migrations must not run twice.');
    check($scalar("SELECT checksum FROM schema_migrations ORDER BY id LIMIT 1") === hash_file('sha256', $migrations . '/' . $first), 'Tracking checksums must remain compatible.');
    check($scalar("SHOW CREATE TABLE schema_migrations") !== null, 'Tracking tables must exist.');
    $manager->run('seed');
    check($scalar('SELECT name FROM items WHERE id=1') === 'عنصر تجريبي', 'Seeding must preserve UTF-8 data.');
    $manager->run('seed');
    check((int) $scalar('SELECT COUNT(*) FROM schema_seeders') === 1, 'Seeders must not run twice.');
    fails(static fn () => $manager->run('rollback'), 'Applied seeders');
    check((int) $scalar('SELECT COUNT(*) FROM schema_migrations') === 2, 'Blocked rollback must preserve migration history.');

    $db->query('CREATE TABLE unmanaged (id INT PRIMARY KEY) ENGINE=InnoDB');
    $db->query('INSERT INTO unmanaged VALUES (1)');
    file_put_contents($migrations . '/2099_01_01_0000_pending.sql', 'CREATE TABLE unmanaged (id INT);');
    $manager->run('clean');
    check((int) $scalar('SELECT COUNT(*) FROM items') === 0 && (int) $scalar('SELECT COUNT(*) FROM notes') === 0, 'Clean must remove managed table rows.');
    check((int) $scalar('SELECT COUNT(*) FROM unmanaged') === 1, 'Clean must not truncate tables mentioned only in pending SQL.');
    check((int) $scalar('SELECT COUNT(*) FROM schema_migrations') === 2, 'Clean must preserve migration history.');
    check((int) $scalar('SELECT COUNT(*) FROM schema_seeders') === 0, 'Clean must clear seeder history.');
    check((int) $scalar('SELECT @@SESSION.FOREIGN_KEY_CHECKS') === 1, 'Clean must restore foreign-key checks.');
    unlink($migrations . '/2099_01_01_0000_pending.sql');
    $db->query('DROP TABLE unmanaged');

    $raw = file_get_contents($migrations . '/' . $first);
    file_put_contents($migrations . '/' . $first, $raw . "\n-- changed\n");
    $output = '';
    $manager->run('status');
    check(str_contains($output, '[changed] ' . $first), 'Status must show changed files.');
    fails(static fn () => $manager->run('migrate'), 'was changed');
    fails(static fn () => $manager->run('clean'), 'was changed');
    fails(static fn () => $manager->run('fresh'), 'was changed');
    file_put_contents($migrations . '/' . $first, '');
    $output = '';
    $manager->run('status');
    check(str_contains($output, '[changed] ' . $first), 'An emptied applied file must still show changed status.');
    unlink($migrations . '/' . $first);
    $output = '';
    $manager->run('status');
    check(str_contains($output, '[missing] ' . $first), 'Status must show missing files.');
    fails(static fn () => $manager->run('migrate'), 'file is missing');
    file_put_contents($migrations . '/' . $first, $raw);

    $failedSeeder = $seeders . '/2026_01_02_0000_fail.sql';
    file_put_contents($failedSeeder, "INSERT INTO items (id, name) VALUES (2, 'synthetic-private-row'); INSERT INTO missing_table VALUES (1);");
    fails(static fn () => $manager->run('seed'), 'SQL execution failed');
    check((int) $scalar('SELECT COUNT(*) FROM items WHERE id=2') === 0, 'A late SQL error must roll back earlier seeder writes.');
    check((int) $scalar('SELECT COUNT(*) FROM schema_seeders') === 1, 'A failed seeder must not have a tracking record.');
    file_put_contents($failedSeeder, "INSERT INTO items (id, name) VALUES (2, 'recovered');");
    $manager->run('seed');
    check((int) $scalar('SELECT COUNT(*) FROM items WHERE id=2') === 1, 'A corrected failed seeder must be retryable.');
    $manager->run('clean');
    unlink($failedSeeder);

    file_put_contents($failedSeeder, "INSERT INTO items VALUES (2, 'must not commit'); CREATE TABLE unsafe (id INT);");
    fails(static fn () => $manager->run('seed'), 'Seeders support');
    check((int) $scalar('SELECT COUNT(*) FROM items') === 0, 'All pending seeders must be preflighted before writing.');
    check(!in_array('unsafe', $tables(), true), 'Seeder DDL must never execute.');
    unlink($failedSeeder);
    $db->query('CREATE TABLE legacy (id INT) ENGINE=MyISAM');
    fails(static fn () => $manager->run('seed'), 'InnoDB');
    $db->query('DROP TABLE legacy');
    $originalMode = (string) $scalar('SELECT @@SESSION.sql_mode');
    foreach (['ANSI_QUOTES', 'NO_BACKSLASH_ESCAPES'] as $mode) {
        $db->query("SET SESSION sql_mode = '" . $mode . "'");
        fails(static fn () => $manager->run('seed'), 'SQL mode');
        fails(static fn () => $manager->run('clean'), 'SQL mode');
    }
    $statement = $db->prepare('SET SESSION sql_mode = ?');
    $statement->bind_param('s', $originalMode);
    $statement->execute();
    $statement->close();

    $downSql = file_get_contents($firstDown);
    unlink($firstDown);
    fails(static fn () => $manager->run('rollback', false, 2), 'Rollback file is missing');
    check(in_array('notes', $tables(), true), 'Rollback must preflight every down file before changing anything.');
    file_put_contents($firstDown, $downSql);
    $manager->run('rollback');
    check(!in_array('notes', $tables(), true) && in_array('items', $tables(), true), 'Rollback must select latest applied migration.');
    $manager->run('rollback');
    check($tables() === ['schema_migrations', 'schema_seeders'], 'Rollback must retain tracking tables.');
    $manager->run('rollback');
    check((int) $scalar('SELECT COUNT(*) FROM schema_migrations') === 0, 'Empty rollback must be harmless.');

    $manager->run('migrate', true);
    check((int) $scalar('SELECT COUNT(*) FROM items') === 1, 'migrate --seed must apply migrations then seeders.');
    $db->query('CREATE TABLE external_notes (item_id INT, FOREIGN KEY (item_id) REFERENCES items(id)) ENGINE=InnoDB');
    $db->query('INSERT INTO external_notes VALUES (1)');
    fails(static fn () => $manager->run('clean'), 'unmanaged table');
    check((int) $scalar('SELECT COUNT(*) FROM items') === 1, 'Blocked cleanup must preserve parent rows.');
    $db->query('DROP TABLE external_notes');

    $dynamic = $migrations . '/2026_01_03_0000_dynamic.sql';
    file_put_contents($dynamic, "PREPARE statement FROM 'SELECT 1'; EXECUTE statement; DEALLOCATE PREPARE statement;");
    $manager->run('mark', false, 1, 'migration', basename($dynamic));
    fails(static fn () => $manager->run('clean'), 'clean cannot infer');
    $db->query("DELETE FROM schema_migrations WHERE filename='2026_01_03_0000_dynamic.sql'");
    unlink($dynamic);
    $db->query('SET FOREIGN_KEY_CHECKS = 0');
    $manager->run('clean');
    check((int) $scalar('SELECT @@SESSION.FOREIGN_KEY_CHECKS') === 0, 'Cleanup must restore the original session setting, including zero.');
    $db->query('SET FOREIGN_KEY_CHECKS = 1');

    $pending = $migrations . '/2099_01_01_0000_pending.sql';
    file_put_contents($pending, 'CREATE TABLE future_table (id INT PRIMARY KEY) ENGINE=InnoDB;');
    $db->query('CREATE TABLE future_table (id INT PRIMARY KEY) ENGINE=InnoDB');
    fails(static fn () => $manager->run('fresh'), 'Rollback file is missing');
    check(in_array('notes', $tables(), true), 'Fresh must preflight even unapplied migration rollback files.');
    file_put_contents(substr($pending, 0, -4) . '.down.sql', 'DROP TABLE IF EXISTS future_table;');
    $manager->run('fresh');
    check($tables() === [], 'Fresh must run all down files and drop tracking tables without recreating anything.');
    $manager->run('fresh');
    check($tables() === [], 'Fresh can repeat when all down files are idempotent.');
    unlink($pending);
    unlink(substr($pending, 0, -4) . '.down.sql');

    $db->query('CREATE TABLE existing (id INT) ENGINE=InnoDB');
    $baseline = $migrations . '/0000_baseline.sql';
    file_put_contents($baseline, 'DROP TABLE IF EXISTS existing; CREATE TABLE existing (id INT) ENGINE=InnoDB;');
    fails(static fn () => $manager->run('migrate'), 'Refusing destructive baseline');
    check(in_array('existing', $tables(), true), 'Baseline protection must preserve an existing schema.');
    unlink($baseline);
    $db->query('DROP TABLE existing');

    $partial = $migrations . '/0000_partial.sql';
    file_put_contents($partial, 'CREATE TABLE partial_table (id INT) ENGINE=InnoDB; INSERT INTO missing_table VALUES (1);');
    fails(static fn () => $manager->run('migrate'), 'Earlier DDL may already have committed');
    check(in_array('partial_table', $tables(), true), 'Tests must expose real DDL partial-commit behavior.');
    check((int) $scalar('SELECT COUNT(*) FROM schema_migrations') === 0, 'Failed DDL migrations must not be recorded as complete.');
    unlink($partial);
    $db->query('DROP TABLE partial_table');

    $markFile = $migrations . '/0000_mark.sql';
    file_put_contents($markFile, 'CREATE TABLE must_not_run (id INT);');
    $manager->run('mark', false, 1, 'migration', basename($markFile));
    check(!in_array('must_not_run', $tables(), true), 'Mark must never execute the SQL file.');
    $manager->run('mark', false, 1, 'migration', basename($markFile));
    check((int) $scalar('SELECT COUNT(*) FROM schema_migrations') === 1, 'Mark must be idempotent.');
    file_put_contents($markFile, 'SELECT 1;');
    fails(static fn () => $manager->run('mark', false, 1, 'migration', basename($markFile)), 'was changed');
    $db->query('DELETE FROM schema_migrations');
    unlink($markFile);
    fails(static fn () => $manager->run('mark', false, 1, 'migration', '../outside.sql'), 'Unknown SQL file');
    $manager->run('migrate');
    $columns = $db->query('SHOW COLUMNS FROM schema_migrations')->fetch_all(MYSQLI_ASSOC);
    check(array_column($columns, 'Field') === ['id', 'filename', 'checksum', 'applied_at'], 'Legacy tracking schema must remain unchanged.');

    $other = new mysqli($host, $user, $password, $database, $port);
    try {
        $other->query("SELECT GET_LOCK(CONCAT('Fares-database-manager:', DATABASE()), 0)")->free();
        fails(static fn () => $manager->run('status'), 'Could not acquire');
        $other->query("SELECT RELEASE_LOCK(CONCAT('Fares-database-manager:', DATABASE()))")->free();
        $manager->run('status');
        $result = $other->query("SELECT GET_LOCK(CONCAT('Fares-database-manager:', DATABASE()), 0)");
        check((int) $result->fetch_row()[0] === 1, 'Manager must release its advisory lock.');
        $result->free();
    } finally {
        $other->close();
    }

    [$code, $stdout, $stderr] = runProcess(
        [PHP_BINARY, dirname(__DIR__) . '/bin/dbman', 'status', '--project-dir=' . $project],
        sys_get_temp_dir(),
        ['DB_HOST' => $host, 'DB_PORT' => (string) $port, 'DB_NAME' => $database, 'DB_USER' => $user, 'DB_PASS' => $password, 'APP_TIMEZONE' => 'UTC'],
    );
    check($code === 0 && $stderr === '' && substr_count($stdout, '[applied]') === 2, 'The real CLI must resolve the requested project, connect and show status.');
    $manager->run('fresh');
    check($tables() === [], 'Final teardown must remove all fixture tables.');
    echo "Passed {$checks} disposable-database checks.\n";
} finally {
    if ($created) {
        $db->query("DROP DATABASE `{$database}`");
    }
    $db->close();
    removeTemporaryProject($project);
}
