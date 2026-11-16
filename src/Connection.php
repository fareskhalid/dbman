<?php
declare(strict_types=1);

namespace Fares\Dbman;

use DateTimeImmutable;
use DateTimeZone;
use Dotenv\Dotenv;
use Dotenv\Repository\Adapter\ArrayAdapter;
use Dotenv\Repository\Adapter\EnvConstAdapter;
use Dotenv\Repository\Adapter\PutenvAdapter;
use Dotenv\Repository\Adapter\ServerConstAdapter;
use Dotenv\Repository\RepositoryBuilder;
use InvalidArgumentException;
use mysqli;
use RuntimeException;
use Throwable;

final class Connection
{
    /** @return array{host: string, port: int, database: string, user: string, password: string, timezone: string} */
    public static function configuration(string $projectDir): array
    {
        // Read process values first, but write dotenv values only to local memory.
        $repository = RepositoryBuilder::createWithNoAdapters()
            ->addReader(PutenvAdapter::class)
            ->addReader(EnvConstAdapter::class)
            ->addReader(ServerConstAdapter::class)
            ->addAdapter(ArrayAdapter::class)
            ->immutable()
            ->make();

        try {
            if (is_file($projectDir . '/.env')) {
                Dotenv::create($repository, $projectDir)->load();
            }
        } catch (Throwable $exception) {
            throw new RuntimeException('Unable to read or parse the project .env file.', 0, $exception);
        }

        foreach (['DB_HOST', 'DB_NAME', 'DB_USER'] as $key) {
            if (trim($repository->get($key) ?? '') === '') {
                throw new InvalidArgumentException("Missing required environment variable: {$key}");
            }
        }

        $rawPort = $repository->get('DB_PORT');
        $port = filter_var($rawPort === null || $rawPort === '' ? '3306' : $rawPort, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);
        if ($port === false) {
            throw new InvalidArgumentException('DB_PORT must be an integer from 1 to 65535.');
        }

        $timezone = trim($repository->get('APP_TIMEZONE') ?? 'UTC');
        self::timezoneOffset($timezone);

        return [
            'host' => $repository->get('DB_HOST'),
            'port' => $port,
            'database' => $repository->get('DB_NAME'),
            'user' => $repository->get('DB_USER'),
            'password' => $repository->get('DB_PASS') ?? '',
            'timezone' => $timezone,
        ];
    }

    public static function open(string $projectDir): mysqli
    {
        $config = self::configuration($projectDir);
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $db = mysqli_init();
        try {
            $db->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);
            $db->real_connect($config['host'], $config['user'], $config['password'], $config['database'], $config['port']);
            $db->set_charset('utf8mb4');
            $offset = self::timezoneOffset($config['timezone']);
            $statement = $db->prepare('SET SESSION time_zone = ?');
            try {
                $statement->bind_param('s', $offset);
                $statement->execute();
            } finally {
                $statement->close();
            }
            return $db;
        } catch (Throwable $exception) {
            $db->close();
            throw new RuntimeException('Database connection or session setup failed. Check DB_* settings and database availability.', 0, $exception);
        }
    }

    public static function timezoneOffset(string $name, ?DateTimeImmutable $at = null): string
    {
        try {
            if ($name === '') {
                throw new InvalidArgumentException();
            }
            $timezone = new DateTimeZone($name);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('APP_TIMEZONE must be a valid, non-empty timezone identifier.', 0, $exception);
        }
        $offset = ($at ?? new DateTimeImmutable('now'))->setTimezone($timezone)->format('P');
        if (preg_match('/^(?:-(?:0[0-9]|1[0-2]):[0-5][0-9]|-13:[0-5][0-9]|\+(?:0[0-9]|1[0-3]):[0-5][0-9]|\+14:00)$/D', $offset) !== 1) {
            throw new InvalidArgumentException('APP_TIMEZONE resolves outside the supported database offset range.');
        }
        return $offset;
    }
}
