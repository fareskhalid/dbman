# dbman

A standalone Composer CLI for plain SQL migrations and seeders on MySQL/MariaDB.
No application bootstrap, framework, ORM, or web endpoints are required.

## Install

Requirements: PHP 8.2+, mysqli, Composer 2.2+, and access to a MySQL/MariaDB database.
The CLI is tested on PHP 8.2 and PHP 8.5 with MySQL 8.4; validate your SQL against your own server version.

From this package checkout:

```bash
composer install
php bin/dbman help
```

For local development, add a path repository to your application's composer.json:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "/etc/dbman",
      "options": {
        "symlink": true,
        "versions": {
          "fareskhalid/dbman": "dev-main"
        }
      }
    }
  ]
}
```

Then run from the application:

```bash
composer require fareskhalid/dbman:dev-main
vendor/bin/dbman help
```

The package is not automatically published by creating this codebase. After you
publish a tagged release to a private Composer/VCS repository or Packagist, use
its released version constraint instead of the local path repository.

Keep this dependency in require if it must be available during deployment after
composer install --no-dev. Installation never runs migrations or copies SQL into
the consumer. Package scripts are development checks, not installation hooks.

## Project layout and configuration

```text
your-application/
├── composer.json
├── .env
└── database/
    ├── migrations/
    │   ├── 2026_01_01_0000_create_items.sql
    │   └── 2026_01_01_0000_create_items.down.sql
    └── seeders/
        └── 2026_01_01_0000_items.sql
```

Migrations and seeders belong to the application. The package includes only
synthetic test fixtures. The migrations directory must exist. The seeders
directory is optional unless running seed, migrate --seed, or mark seeder.

Run from the application root, or specify its directory explicitly:

```bash
vendor/bin/dbman --project-dir=/path/to/application status
```

Both --project-dir=PATH and --project-dir PATH work before or after the command.
Relative paths are resolved from the current working directory. The package never
walks parent directories looking for credentials or loads the API bootstrap.

| Variable | Requirement/default |
| --- | --- |
| DB_HOST | Required; localhost may use a Unix socket, 127.0.0.1 uses TCP |
| DB_NAME | Required; the database must already exist |
| DB_USER | Required |
| DB_PASS | Optional; defaults to an empty string |
| DB_PORT | Integer 1–65535; defaults to 3306 |
| APP_TIMEZONE | Valid timezone; defaults to UTC |

Values are read from the process environment, then $_ENV, then $_SERVER, then
the project's optional .env file, in that order. Dotenv interpolation respects
that precedence. Loading configuration does not write to global environment
variables. A malformed .env fails without printing its contents.

Connections use strict mysqli errors, a ten-second connection timeout, utf8mb4,
and a session timezone offset computed from APP_TIMEZONE. PHP's global timezone
is unchanged. Set APP_TIMEZONE=Africa/Cairo explicitly when migrating from the
Elite CLI, whose implicit default was Cairo.

## Commands

| Command | Behavior |
| --- | --- |
| status | Show pending/applied/changed/missing files; never create tracking tables |
| migrate | Apply pending migrations in lexicographic filename order |
| migrate --seed | Migrate, then apply pending seeders |
| seed | Seed only when all migrations are applied |
| rollback / rollback --steps=2 | Execute down files for the latest N applied records |
| clean | Truncate managed tables and clear seeder history; retain migration history |
| fresh | Execute every down file in reverse filename order, then drop tracking tables |
| mark migration file.sql --force | Record an independently verified migration without executing it |
| mark seeder file.sql --force | Record an independently verified seeder without executing it |

Successful commands exit 0; invalid arguments, configuration problems, lock
timeouts, and execution failures exit 1. Output goes to stdout; errors go to
stderr. Help needs installed Composer dependencies but no project configuration
or database connection.

**clean and fresh permanently destroy data without prompting.** Confirm the
target and make a backup before using them on a valued database. fresh is a
teardown command: it does not recreate or reseed the schema.

## SQL contract

Use immutable UTF-8 .sql files with ordinary SQL statements. Filename ordering
defines migration order; .down.sql files are excluded from forward discovery.
An exact same-stem down filename takes priority. The legacy naming convention
with the same date/description but a different four-digit sequence also works;
ambiguous matches are rejected.

SQL files are trusted executable code. Review all forward and down scripts.
dbman is not a SQL sandbox and cannot establish ownership of arbitrary SQL or
protect data from a deliberately destructive rollback script. Keep statements
within the selected database and reserve schema_migrations and schema_seeders
for dbman.

Plain quoted strings, quoted identifiers, regular comments, and semicolons inside
strings are supported. DELIMITER client directives and executable comments
(/*! ... */ and /*M! ... */) are rejected. Stored-program/client dump formats
requiring those features are outside this package's SQL contract. Use ordinary
backslash-escape SQL mode; seeding and cleanup reject ANSI_QUOTES and
NO_BACKSLASH_ESCAPES so the scanner and server cannot disagree.

Migration SQL may include DDL, DML, and prepared statements. Like the original
tool, a pending migration containing DROP TABLE IF EXISTS is refused while any
application table exists. After independently verifying a manually applied
baseline, use mark; do not use it to conceal a partially failed migration.

Seeders support INSERT, UPDATE, DELETE, and REPLACE, optionally wrapped by one
outer START TRANSACTION / COMMIT pair. The wrapper is removed and the tool owns
the transaction. All pending seeders are checked before seeding starts. Each
file's data and tracking record commit together. DDL, SET, embedded transaction
control, and other statements are refused. The selected database must contain
only InnoDB base tables; scripts and triggers must also target transactional
tables within this database.

Cleanup discovers tables from literal CREATE TABLE statements in **applied,
checksum-verified** migration files. It supports ordinary or backtick-quoted,
unqualified table names with explicit column definitions. It refuses prepared
SQL, CALL, renames, qualified CREATE names, CREATE ... LIKE, and CREATE ... AS
SELECT because ownership cannot safely be inferred. This includes legacy
projects whose UTF-8 migrations use PREPARE; those projects need their own
reviewed cleanup procedure. Migration execution itself still supports PREPARE.
Comments and strings cannot invent managed tables. An unmanaged foreign key
referencing a managed table also blocks cleanup. Foreign-key-check state is
restored even on failure.

## Tracking, concurrency, and recovery

The original tracking schema is preserved: schema_migrations and schema_seeders
contain id, filename, checksum (SHA-256 of original file bytes), and applied_at.
Tracking tables are initialized only for migrate, seed, and mark, under a MySQL
advisory lock. status, rollback, clean, and fresh do not create them.

The advisory-lock key remains Fares-database-manager:<database>, with a
ten-second acquisition timeout, so legacy and packaged commands exclude one
another. All commands release it in a finally block. Only run on a single
primary: these connection locks do not coordinate independent database servers.

Applied files must not be changed or deleted. Reruns skip their records.
Rollback selects records by descending applied id, preflights all selected
down files before executing any, and refuses while seeder history is non-empty.
fresh also verifies migration history and preflights every down file, including
unapplied migrations. Down scripts must tolerate absent objects if you need
fresh to work on an empty or partially applied schema.

**DDL and TRUNCATE are not transactionally reversible.** On migration, rollback,
fresh, or clean failure, previous statements may already have committed. The
tool never automatically marks a failed migration complete or retries it.
Inspect the actual schema, restore/reconcile it, and only then retry or mark
after manual verification. Down-file contents themselves are not checksummed.

The original API's migration files are not modified by this package. Audit
their down files before rollout; a package cannot repair incorrect SQL history.

## Development checks

```bash
composer validate --strict
composer test
composer test:package
```

composer test runs offline checks and never connects to a database.
composer test:package installs a copied package in a disposable Composer consumer
with custom vendor and binary directories; dependency downloads may need network
access. The consumer directory is removed afterward.

For integration tests, provide an explicitly disposable local MySQL server.
The runner ignores ordinary DB_* configuration, creates a random
dbman_test_<random> schema using CREATE DATABASE without IF NOT EXISTS, and drops
only that schema in finally. It never adopts or truncates an existing schema.
The test account needs CREATE/DROP DATABASE privileges.

```bash
DBMAN_TEST_ALLOW_CREATE=1 \
DBMAN_TEST_HOST=127.0.0.1 \
DBMAN_TEST_PORT=YOUR_DISPOSABLE_PORT \
DBMAN_TEST_USER=root \
composer test:integration
```

Set DBMAN_TEST_PASS through the environment if needed. The integration suite
covers read-only status, repeat execution, UTF-8, checksums/missing files,
transactional seeder failures, cleanup scope, foreign keys, rollback preflight,
mark, fresh, partial DDL failures, lock contention, and the actual CLI.

## Structure

- bin/dbman: Composer-aware CLI entry point.
- src/Application.php: argument validation, project selection, output and exit codes.
- src/Connection.php: isolated environment loading and mysqli session setup.
- src/DatabaseManager.php: migration, seeder, history, locking and teardown operations.
- src/SqlScript.php: the small SQL scanner used by validation and cleanup.

The existing Fares\Dbman namespace is retained from the initial package manifest.
There are no App\ imports or framework dependencies.
