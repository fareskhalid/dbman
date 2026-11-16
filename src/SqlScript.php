<?php
declare(strict_types=1);

namespace Fares\Dbman;

use RuntimeException;

/** Small scanner for plain SQL files; not a general MySQL client/parser. */
final class SqlScript
{
    /** @return list<string> */
    public static function statements(string $sql): array
    {
        $statements = [];
        $statement = '';
        $length = strlen($sql);
        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';
            if ($char === "'" || $char === '"' || $char === chr(96)) {
                $quote = $char;
                $statement .= $char;
                $closed = false;
                while (++$i < $length) {
                    $char = $sql[$i];
                    $statement .= $char;
                    if ($char === '\\' && $quote !== chr(96)) {
                        if ($i + 1 < $length) {
                            $statement .= $sql[++$i];
                        }
                    } elseif ($char === $quote) {
                        if (($sql[$i + 1] ?? '') === $quote) {
                            $statement .= $sql[++$i];
                        } else {
                            $closed = true;
                            break;
                        }
                    }
                }
                if (!$closed) {
                    throw new RuntimeException('Unterminated SQL quote.');
                }
            } elseif ($char === '#' || ($char === '-' && $next === '-' && (!isset($sql[$i + 2]) || ord($sql[$i + 2]) <= 32))) {
                while ($i < $length && $sql[$i] !== "\n" && $sql[$i] !== "\r") {
                    $i++;
                }
                $statement .= ' ';
            } elseif ($char === '/' && $next === '*') {
                if (($sql[$i + 2] ?? '') === '!' || strtoupper(substr($sql, $i + 2, 2)) === 'M!') {
                    throw new RuntimeException('Executable SQL comments are unsupported. Use explicit SQL statements.');
                }
                $end = strpos($sql, '*/', $i + 2);
                if ($end === false) {
                    throw new RuntimeException('Unterminated SQL comment.');
                }
                $i = $end + 1;
                $statement .= ' ';
            } elseif ($char === ';') {
                if (trim($statement) !== '') {
                    $statements[] = trim($statement);
                }
                $statement = '';
            } else {
                $statement .= $char;
            }
        }
        if (trim($statement) !== '') {
            $statements[] = trim($statement);
        }
        foreach ($statements as $item) {
            if (preg_match('/^DELIMITER\b/i', $item)) {
                throw new RuntimeException('DELIMITER directives are unsupported. Use plain SQL statements.');
            }
        }
        return $statements;
    }

    public static function seederSql(string $sql): string
    {
        $statements = self::statements($sql);
        if ($statements !== [] && preg_match('/^START\s+TRANSACTION$/i', $statements[0])) {
            array_shift($statements);
            if ($statements === [] || strcasecmp($statements[array_key_last($statements)], 'COMMIT') !== 0) {
                throw new RuntimeException('A seeder START TRANSACTION wrapper must end with COMMIT.');
            }
            array_pop($statements);
        }
        if ($statements === []) {
            throw new RuntimeException('Seeder must contain at least one DML statement.');
        }
        foreach ($statements as $statement) {
            if (preg_match('/^(INSERT|UPDATE|DELETE|REPLACE)\s/i', $statement) !== 1) {
                throw new RuntimeException('Seeders support INSERT, UPDATE, DELETE and REPLACE only. Move DDL and session changes to migrations.');
            }
        }
        return implode(";\n", $statements) . ';';
    }

    /** @return list<string> */
    public static function createdTables(string $sql): array
    {
        $tables = [];
        foreach (self::statements($sql) as $statement) {
            if (preg_match('/^(PREPARE|EXECUTE|CALL|RENAME)\b/i', $statement)
                || preg_match('/^ALTER\s+TABLE\b[\s\S]*\bRENAME\b/i', $statement)) {
                throw new RuntimeException('clean cannot infer table ownership from dynamic SQL or renamed tables.');
            }
            if (preg_match('/^CREATE\s+TABLE\b/i', $statement) !== 1) {
                continue;
            }
            if (preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(\x60(?:[^\x60]|\x60\x60)+\x60|[a-zA-Z_][a-zA-Z0-9_$]*)\s*\(/i', $statement, $match) !== 1) {
                throw new RuntimeException('clean requires unqualified CREATE TABLE names and explicit column definitions.');
            }
            $table = str_starts_with($match[1], chr(96))
                ? str_replace('``', '`', substr($match[1], 1, -1))
                : $match[1];
            if (in_array(strtolower($table), ['schema_migrations', 'schema_seeders'], true)) {
                throw new RuntimeException('Migration SQL must not create dbman tracking tables.');
            }
            $tables[$table] = true;
        }
        $names = array_keys($tables);
        sort($names, SORT_STRING);
        return $names;
    }
}
