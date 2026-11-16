<?php
declare(strict_types=1);

namespace Fares\Dbman;

use Closure;
use FilesystemIterator;
use InvalidArgumentException;
use mysqli;
use RuntimeException;
use Throwable;

final class DatabaseManager
{
    public const MIGRATIONS_TABLE = 'schema_migrations';
    public const SEEDERS_TABLE = 'schema_seeders';
    private Closure $output;

    public function __construct(
        private readonly mysqli $db,
        private readonly string $migrationsDir,
        private readonly string $seedersDir,
        ?callable $output = null,
    ) {
        $this->output = Closure::fromCallable($output ?? static fn (string $text) => fwrite(STDOUT, $text));
    }

    public function run(string $command, bool $seed = false, int $steps = 1, ?string $type = null, ?string $filename = null): void
    {
        if (!in_array($command, ['status', 'migrate', 'seed', 'rollback', 'clean', 'fresh', 'mark'], true) || $steps < 1) {
            throw new InvalidArgumentException('Invalid database command or rollback step count.');
        }
        if ($command === 'mark' && (!in_array($type, ['migration', 'seeder'], true) || $filename === null)) {
            throw new InvalidArgumentException('mark requires a migration/seeder type and filename.');
        }
        $migrations = self::sqlFiles($this->migrationsDir);
        $seeders = self::sqlFiles($this->seedersDir, $seed || $command === 'seed' || ($command === 'mark' && $type === 'seeder'));
        $this->acquireLock();
        try {
            if (in_array($command, ['migrate', 'seed', 'mark'], true)) {
                $this->ensureTrackingTables();
            }
            switch ($command) {
                case 'status':
                    $this->printStatus('Migrations', $migrations, $this->records(self::MIGRATIONS_TABLE));
                    $this->printStatus('Seeders', $seeders, $this->records(self::SEEDERS_TABLE));
                    break;
                case 'migrate':
                    $this->migrate($migrations);
                    if ($seed) {
                        $this->seed($migrations, $seeders);
                    }
                    break;
                case 'seed':
                    $this->seed($migrations, $seeders);
                    break;
                case 'rollback':
                    $this->rollback($migrations, $steps);
                    break;
                case 'clean':
                    $this->clean($migrations, $seeders);
                    break;
                case 'fresh':
                    $this->fresh($migrations);
                    break;
                case 'mark':
                    $this->mark($type === 'migration' ? $migrations : $seeders, $type === 'migration' ? self::MIGRATIONS_TABLE : self::SEEDERS_TABLE, $filename);
                    break;
            }
        } finally {
            try {
                $this->db->query("SELECT RELEASE_LOCK(CONCAT('Fares-database-manager:', DATABASE()))")->free();
            } catch (Throwable) {
                // Closing the caller-owned connection also releases the advisory lock.
            }
        }
    }

    /** @return array<string, string> */
    public static function sqlFiles(string $directory, bool $required = true): array
    {
        if (!is_dir($directory)) {
            if (!$required && !file_exists($directory)) {
                return [];
            }
            throw new RuntimeException('SQL directory is missing: ' . $directory);
        }
        if (!is_readable($directory)) {
            throw new RuntimeException('SQL directory is not readable: ' . $directory);
        }
        $files = [];
        foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $file) {
            $name = $file->getFilename();
            if (str_starts_with($name, '.') || !str_ends_with($name, '.sql') || str_ends_with($name, '.down.sql')) {
                continue;
            }
            $path = $file->getPathname();
            if (strlen($name) > 255 || preg_match('/[\x00-\x1f\x7f]/', $name) || !is_file($path) || !is_readable($path)) {
                throw new RuntimeException('SQL files must be readable regular files with valid filenames of at most 255 bytes.');
            }
            $files[$name] = $path;
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    public static function rollbackPath(string $upPath): ?string
    {
        $exact = substr($upPath, 0, -4) . '.down.sql';
        if (is_file($exact)) {
            return $exact;
        }
        if (preg_match('/^(\d{4}_\d{2}_\d{2})_\d{4}_(.+)\.sql$/D', basename($upPath), $matches) !== 1) {
            return null;
        }
        // Compare literal filenames; project paths may contain glob metacharacters.
        $candidates = [];
        foreach (new FilesystemIterator(dirname($upPath), FilesystemIterator::SKIP_DOTS) as $file) {
            $path = $file->getPathname();
            if ($file->isFile() && preg_match('/^' . preg_quote($matches[1], '/') . '_\d{4}_' . preg_quote($matches[2], '/') . '\.down\.sql$/D', basename($path))) {
                $candidates[] = $path;
            }
        }
        if (count($candidates) > 1) {
            throw new RuntimeException('Multiple rollback files match migration: ' . basename($upPath));
        }
        return $candidates[0] ?? null;
    }

    private function acquireLock(): void
    {
        // Keep the original key so packaged and legacy clients exclude each other.
        $result = $this->db->query("SELECT GET_LOCK(CONCAT('Fares-database-manager:', DATABASE()), 10) AS acquired");
        $row = $result->fetch_assoc();
        $result->free();
        if ((int) ($row['acquired'] ?? 0) !== 1) {
            throw new RuntimeException('Could not acquire the database migration lock.');
        }
    }

    private function ensureTrackingTables(): void
    {
        foreach ([self::MIGRATIONS_TABLE, self::SEEDERS_TABLE] as $table) {
            $this->db->query(
                "CREATE TABLE IF NOT EXISTS `{$table}` ("
                . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . '`filename` VARCHAR(255) NOT NULL,'
                . '`checksum` CHAR(64) NOT NULL,'
                . '`applied_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                . 'PRIMARY KEY (`id`),'
                . "UNIQUE KEY `{$table}_filename_unique` (`filename`)"
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            );
        }
    }

    private function trackingTableExists(string $table): bool
    {
        if (!in_array($table, [self::MIGRATIONS_TABLE, self::SEEDERS_TABLE], true)) {
            throw new InvalidArgumentException('Invalid tracking table.');
        }
        $statement = $this->db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        try {
            $statement->bind_param('s', $table);
            $statement->execute();
            $statement->store_result();
            $exists = $statement->num_rows > 0;
            $statement->free_result();
            return $exists;
        } finally {
            $statement->close();
        }
    }

    /** @return array<string, array{id: int, checksum: string, applied_at: string}> */
    private function records(string $table): array
    {
        if (!$this->trackingTableExists($table)) {
            return [];
        }
        $result = $this->db->query("SELECT `id`, `filename`, `checksum`, `applied_at` FROM `{$table}` ORDER BY `id` ASC");
        $records = [];
        while ($row = $result->fetch_assoc()) {
            $records[$row['filename']] = ['id' => (int) $row['id'], 'checksum' => $row['checksum'], 'applied_at' => $row['applied_at']];
        }
        $result->free();
        return $records;
    }

    private function assertScannerMode(): void
    {
        $result = $this->db->query('SELECT @@SESSION.sql_mode');
        $modes = explode(',', strtoupper((string) $result->fetch_row()[0]));
        $result->free();
        if (array_intersect($modes, ['ANSI_QUOTES', 'NO_BACKSLASH_ESCAPES']) !== []) {
            throw new RuntimeException('Seeding and cleanup require SQL mode without ANSI_QUOTES or NO_BACKSLASH_ESCAPES.');
        }
    }

    private function readSql(string $path, bool $allowEmpty = false): string
    {
        $sql = @file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException('Unable to read SQL file: ' . basename($path));
        }
        if (!$allowEmpty && trim($sql) === '') {
            throw new RuntimeException('SQL file is empty: ' . basename($path));
        }
        return $sql;
    }

    private function assertUnchanged(string $type, array $records, array $files): void
    {
        foreach ($records as $filename => $record) {
            if (!isset($files[$filename])) {
                throw new RuntimeException("Applied {$type} file is missing: {$filename}");
            }
            if (!hash_equals($record['checksum'], hash('sha256', $this->readSql($files[$filename])))) {
                throw new RuntimeException("Applied {$type} file was changed: {$filename}");
            }
        }
    }

    private function executeSql(string $sql, string $filename): void
    {
        try {
            $this->db->multi_query($sql);
            do {
                $result = $this->db->store_result();
                if ($result !== false) {
                    $result->free();
                }
                if (!$this->db->more_results()) {
                    break;
                }
                $this->db->next_result();
            } while (true);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                "SQL execution failed in {$filename} (database error {$exception->getCode()}). Earlier DDL may already have committed; inspect the schema before retrying.",
                0,
                $exception,
            );
        }
    }

    private function record(string $table, string $filename, string $checksum): void
    {
        if (!in_array($table, [self::MIGRATIONS_TABLE, self::SEEDERS_TABLE], true)) {
            throw new InvalidArgumentException('Invalid tracking table.');
        }
        $statement = $this->db->prepare("INSERT INTO `{$table}` (`filename`, `checksum`) VALUES (?, ?)");
        try {
            $statement->bind_param('ss', $filename, $checksum);
            $statement->execute();
        } finally {
            $statement->close();
        }
    }

    /** @return list<string> */
    private function applicationTables(): array
    {
        $result = $this->db->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
            . " AND TABLE_NAME NOT IN ('schema_migrations', 'schema_seeders') ORDER BY TABLE_NAME",
        );
        $tables = array_column($result->fetch_all(MYSQLI_ASSOC), 'TABLE_NAME');
        $result->free();
        return $tables;
    }

    private function migrate(array $files): void
    {
        $records = $this->records(self::MIGRATIONS_TABLE);
        $this->assertUnchanged('migration', $records, $files);
        $pending = array_diff_key($files, $records);
        if ($pending === []) {
            ($this->output)("No pending migrations.\n");
        }
        foreach ($pending as $filename => $path) {
            $sql = $this->readSql($path);
            $statements = SqlScript::statements($sql);
            if ($statements === []) {
                throw new RuntimeException("SQL file contains no statements: {$filename}");
            }
            foreach ($statements as $statement) {
                if (preg_match('/^DROP\s+TABLE\s+IF\s+EXISTS\b/i', $statement) && $this->applicationTables() !== []) {
                    throw new RuntimeException("Refusing destructive baseline {$filename} over an existing schema. Verify it was applied, then use dbman mark migration {$filename} --force.");
                }
            }
            ($this->output)("Migrating: {$filename}\n");
            $this->executeSql($sql, $filename);
            $this->record(self::MIGRATIONS_TABLE, $filename, hash('sha256', $sql));
            ($this->output)("Migrated:  {$filename}\n");
        }
    }

    private function seed(array $migrations, array $seeders): void
    {
        $records = $this->records(self::MIGRATIONS_TABLE);
        $this->assertUnchanged('migration', $records, $migrations);
        if (array_diff_key($migrations, $records) !== []) {
            throw new RuntimeException('Cannot seed while migrations are pending. Run migrate first.');
        }
        $records = $this->records(self::SEEDERS_TABLE);
        $this->assertUnchanged('seeder', $records, $seeders);
        $pending = array_diff_key($seeders, $records);
        if ($pending === []) {
            ($this->output)("No pending seeders.\n");
            return;
        }
        $result = $this->db->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND ENGINE <> 'InnoDB' LIMIT 1");
        $nonTransactional = $result->num_rows > 0;
        $result->free();
        if ($nonTransactional) {
            throw new RuntimeException('Seeding requires InnoDB tables so data and history can roll back together.');
        }
        $this->assertScannerMode();
        $plan = [];
        foreach ($pending as $filename => $path) {
            $raw = $this->readSql($path);
            $plan[$filename] = [SqlScript::seederSql($raw), hash('sha256', $raw)];
        }
        foreach ($plan as $filename => [$sql, $checksum]) {
            ($this->output)("Seeding: {$filename}\n");
            $this->db->begin_transaction();
            try {
                $this->executeSql($sql, $filename);
                $this->record(self::SEEDERS_TABLE, $filename, $checksum);
                $this->db->commit();
            } catch (Throwable $exception) {
                try {
                    $this->db->rollback();
                } catch (Throwable) {
                    // Retain the original error; the caller closes this connection.
                }
                throw $exception;
            }
            ($this->output)("Seeded:  {$filename}\n");
        }
    }

    /** @return array<string, array{string, string}> */
    private function rollbackPlan(array $files): array
    {
        $plan = [];
        foreach ($files as $filename => $path) {
            $down = self::rollbackPath($path);
            if ($down === null) {
                throw new RuntimeException("Rollback file is missing for migration: {$filename}");
            }
            $sql = $this->readSql($down);
            if (SqlScript::statements($sql) === []) {
                throw new RuntimeException("Rollback file contains no statements: {$filename}");
            }
            $plan[$filename] = [$sql, basename($down)];
        }
        return $plan;
    }

    private function rollback(array $files, int $steps): void
    {
        $records = $this->records(self::MIGRATIONS_TABLE);
        $this->assertUnchanged('migration', $records, $files);
        if ($this->records(self::SEEDERS_TABLE) !== []) {
            throw new RuntimeException('Applied seeders may depend on these migrations. Back up data before using dbman clean, then retry rollback.');
        }
        if ($records === []) {
            ($this->output)("No applied migrations to roll back.\n");
            return;
        }
        $targets = [];
        foreach (array_slice(array_reverse($records, true), 0, $steps, true) as $filename => $record) {
            $targets[$filename] = $files[$filename];
        }
        foreach ($this->rollbackPlan($targets) as $filename => [$sql, $downName]) {
            ($this->output)("Rolling back: {$filename}\n");
            $this->executeSql($sql, $downName);
            $statement = $this->db->prepare('DELETE FROM schema_migrations WHERE filename = ?');
            try {
                $statement->bind_param('s', $filename);
                $statement->execute();
            } finally {
                $statement->close();
            }
            ($this->output)("Rolled back:  {$filename}\n");
        }
    }

    private function clean(array $migrations, array $seeders): void
    {
        $this->assertScannerMode();
        $records = $this->records(self::MIGRATIONS_TABLE);
        $this->assertUnchanged('migration', $records, $migrations);
        $this->assertUnchanged('seeder', $this->records(self::SEEDERS_TABLE), $seeders);
        $tables = [];
        foreach ($records as $filename => $record) {
            foreach (SqlScript::createdTables($this->readSql($migrations[$filename])) as $table) {
                $tables[$table] = true;
            }
        }
        $tables = array_values(array_intersect(array_keys($tables), $this->applicationTables()));
        // Do not orphan data in unmanaged tables referencing a managed parent.
        $result = $this->db->query(
            'SELECT TABLE_SCHEMA, TABLE_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE'
            . ' WHERE REFERENCED_TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL',
        );
        $databaseResult = $this->db->query('SELECT DATABASE()');
        $database = $databaseResult->fetch_row()[0];
        $databaseResult->free();
        foreach ($result->fetch_all(MYSQLI_ASSOC) as $foreignKey) {
            if (in_array($foreignKey['REFERENCED_TABLE_NAME'], $tables, true)
                && ($foreignKey['TABLE_SCHEMA'] !== $database || !in_array($foreignKey['TABLE_NAME'], $tables, true))) {
                $result->free();
                throw new RuntimeException('clean refused: an unmanaged table references a managed table.');
            }
        }
        $result->free();
        $result = $this->db->query('SELECT @@SESSION.FOREIGN_KEY_CHECKS');
        $checks = (int) $result->fetch_row()[0];
        $result->free();
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($tables as $table) {
                $identifier = str_replace('`', '``', $table);
                $this->db->query("TRUNCATE TABLE `{$identifier}`");
            }
            if ($this->trackingTableExists(self::SEEDERS_TABLE)) {
                $this->db->query('TRUNCATE TABLE schema_seeders');
            }
        } finally {
            $this->db->query('SET FOREIGN_KEY_CHECKS = ' . $checks);
        }
        ($this->output)('Cleaned ' . count($tables) . " managed table(s); migration history and table structures were preserved.\n");
    }

    private function fresh(array $files): void
    {
        $this->assertUnchanged('migration', $this->records(self::MIGRATIONS_TABLE), $files);
        foreach ($this->rollbackPlan(array_reverse($files, true)) as [$sql, $downName]) {
            ($this->output)("Removing schema from: {$downName}\n");
            $this->executeSql($sql, $downName);
        }
        $this->db->query('DROP TABLE IF EXISTS schema_seeders');
        $this->db->query('DROP TABLE IF EXISTS schema_migrations');
        ($this->output)("Fresh database teardown complete; rollback files executed and tracking tables removed.\n");
    }

    private function mark(array $files, string $table, string $filename): void
    {
        if (!isset($files[$filename])) {
            throw new RuntimeException('Unknown SQL file. Use the exact filename shown by status.');
        }
        $records = $this->records($table);
        $this->assertUnchanged('SQL', $records, $files);
        if (isset($records[$filename])) {
            ($this->output)("Already marked: {$filename}\n");
            return;
        }
        $this->record($table, $filename, hash('sha256', $this->readSql($files[$filename])));
        ($this->output)("Marked as applied without execution: {$filename}\n");
    }

    private function printStatus(string $heading, array $files, array $records): void
    {
        ($this->output)("{$heading}:\n");
        if ($files === [] && $records === []) {
            ($this->output)("  (none)\n");
        }
        foreach ($files as $filename => $path) {
            $status = !isset($records[$filename]) ? 'pending'
                : (hash_equals($records[$filename]['checksum'], hash('sha256', $this->readSql($path, true))) ? 'applied' : 'changed');
            ($this->output)("  [{$status}] {$filename}\n");
        }
        foreach (array_diff_key($records, $files) as $filename => $record) {
            ($this->output)("  [missing] {$filename}\n");
        }
    }
}
