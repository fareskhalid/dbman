<?php
declare(strict_types=1);

namespace Fares\Dbman;

use Closure;
use InvalidArgumentException;
use mysqli_sql_exception;
use Throwable;

final class Application
{
    private Closure $output;
    private Closure $error;

    public function __construct(?callable $output = null, ?callable $error = null)
    {
        $this->output = Closure::fromCallable($output ?? static fn (string $text) => fwrite(STDOUT, $text));
        $this->error = Closure::fromCallable($error ?? static fn (string $text) => fwrite(STDERR, $text));
    }

    /** @param list<string> $arguments */
    public function run(array $arguments, ?string $projectDir = null): int
    {
        try {
            $projectOption = false;
            $positional = [];
            for ($i = 0; $i < count($arguments); $i++) {
                $argument = $arguments[$i];
                if ($argument === '--project-dir' || str_starts_with($argument, '--project-dir=')) {
                    if ($projectOption) {
                        throw new InvalidArgumentException('Specify --project-dir only once.');
                    }
                    $projectOption = true;
                    $projectDir = $argument === '--project-dir'
                        ? ($arguments[++$i] ?? '')
                        : substr($argument, strlen('--project-dir='));
                    if ($projectDir === '' || str_starts_with($projectDir, '--')) {
                        throw new InvalidArgumentException('--project-dir requires a directory.');
                    }
                } else {
                    $positional[] = $argument;
                }
            }

            $command = array_shift($positional) ?? 'help';
            if (in_array($command, ['help', '--help', '-h'], true)) {
                $this->assertArguments($positional, []);
                ($this->output)($this->usage());
                return 0;
            }

            $steps = 1;
            switch ($command) {
                case 'status':
                case 'seed':
                case 'clean':
                case 'fresh':
                    $this->assertArguments($positional, []);
                    break;
                case 'migrate':
                    $this->assertArguments($positional, ['--seed']);
                    break;
                case 'rollback':
                    if ($positional !== []) {
                        if (count($positional) !== 1 || preg_match('/^--steps=([1-9][0-9]*)$/D', $positional[0], $matches) !== 1) {
                            throw new InvalidArgumentException('Usage: rollback [--steps=N]');
                        }
                        $steps = filter_var($matches[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                        if ($steps === false) {
                            throw new InvalidArgumentException('--steps must be a positive integer within PHP integer limits.');
                        }
                    }
                    break;
                case 'mark':
                    if (count($positional) !== 3 || !in_array($positional[0], ['migration', 'seeder'], true) || $positional[2] !== '--force') {
                        throw new InvalidArgumentException('Usage: mark <migration|seeder> <file.sql> --force');
                    }
                    break;
                default:
                    throw new InvalidArgumentException('Unknown command. Run dbman help for available commands.');
            }

            $directory = realpath($projectDir ?? (getcwd() ?: '.'));
            if ($directory === false || !is_dir($directory)) {
                throw new InvalidArgumentException('Project directory does not exist.');
            }

            $migrations = $directory . '/database/migrations';
            $seeders = $directory . '/database/seeders';
            // Validate paths before connecting to any database.
            DatabaseManager::sqlFiles($migrations);
            DatabaseManager::sqlFiles($seeders, $command === 'seed' || in_array('--seed', $positional, true) || ($command === 'mark' && $positional[0] === 'seeder'));
            $db = Connection::open($directory);
            try {
                (new DatabaseManager($db, $migrations, $seeders, $this->output))->run(
                    $command,
                    in_array('--seed', $positional, true),
                    $steps,
                    $command === 'mark' ? $positional[0] : null,
                    $command === 'mark' ? $positional[1] : null,
                );
            } finally {
                $db->close();
            }

            return 0;
        } catch (Throwable $exception) {
            // Server diagnostics can contain SQL values and credentials.
            $message = $exception instanceof mysqli_sql_exception
                ? 'Database operation failed (error ' . $exception->getCode() . ').'
                : $exception->getMessage();
            ($this->error)('Error: ' . $message . PHP_EOL);
            return 1;
        }
    }

    /** @param list<string> $arguments @param list<string> $allowed */
    private function assertArguments(array $arguments, array $allowed): void
    {
        if (count($arguments) !== count(array_unique($arguments)) || array_diff($arguments, $allowed) !== []) {
            throw new InvalidArgumentException('Invalid or duplicate arguments. Run dbman help for usage.');
        }
    }

    private function usage(): string
    {
        return <<<'TEXT'
dbman — SQL database manager

Usage:
  dbman [--project-dir=PATH] status
  dbman [--project-dir=PATH] migrate [--seed]
  dbman [--project-dir=PATH] seed
  dbman [--project-dir=PATH] rollback [--steps=N]
  dbman [--project-dir=PATH] clean
  dbman [--project-dir=PATH] fresh
  dbman [--project-dir=PATH] mark <migration|seeder> <file.sql> --force

status    Read-only migration and seeder status; creates no tracking tables.
migrate   Apply pending SQL files in filename order. Seeding is opt-in.
seed      Apply pending DML seeders after all migrations have been applied.
rollback  Execute down SQL for the latest N applied migrations (default: 1).
clean     Permanently truncate tables created by applied migrations and clear seeder history.
fresh     Execute ALL down files in reverse filename order and drop tracking tables.
mark      Record a manually verified SQL file as applied without executing it.

Paths are relative to the project directory (default: current working directory):
  database/migrations/*.sql, database/seeders/*.sql, .env
Process environment overrides .env. Required: DB_HOST, DB_NAME, DB_USER.
Optional: DB_PASS, DB_PORT (3306), APP_TIMEZONE (UTC).

WARNING: clean and fresh destroy data without prompting. fresh does not recreate
the schema. Back up valued databases and verify the target before running them.
Rollback requires empty seeder history. DDL failures can leave partial changes.

TEXT;
    }
}
