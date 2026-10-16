<?php

declare(strict_types=1);

namespace Quissly\PrestaShop\Platform;

/**
 * The database, with the placeholders the ported classes were written with (CS-Cart's db_*
 * functions), run on PrestaShop's Db. Keeping the syntax keeps the tested queries unchanged.
 *
 *   ?:  the table prefix (_DB_PREFIX_)        ?i  an int            ?d  a float
 *   ?s  a quoted, escaped string              ?l  the same, for LIKE (the caller adds %)
 *   ?n  a list of ints: 1,2,3                 ?a  a list of quoted strings: 'a','b'
 *   ?u  column = value pairs for UPDATE SET   ?e  the same, for INSERT INTO t SET ...
 *   ?p  SQL passed as is (already safe)
 *
 * format() is pure (unit-tested); the rest calls PrestaShop.
 */
final class Db
{
    /**
     * @param list<mixed> $args
     * @param callable(string): string $escape escapes a string's content (no quotes)
     */
    public static function format(string $sql, array $args, string $prefix, callable $escape): string
    {
        $quote = static function ($value) use ($escape): string {
            return "'" . $escape((string) $value) . "'";
        };
        $pairs = static function (array $row) use ($quote): string {
            $set = [];
            foreach ($row as $column => $value) {
                $set[] = '`' . str_replace('`', '', (string) $column) . '` = ' . (is_int($value) ? (string) $value : $quote($value));
            }

            return implode(', ', $set);
        };

        $out = '';
        $length = strlen($sql);
        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            if ($char !== '?' || $i + 1 >= $length) {
                $out .= $char;
                continue;
            }
            $type = $sql[$i + 1];
            if ($type === ':') {
                $out .= $prefix;
                $i++;
                continue;
            }
            if (strpos('isdlnauep', $type) === false) {
                $out .= $char;
                continue;
            }
            if ($args === []) {
                throw new \InvalidArgumentException('Too few values for the query placeholders.');
            }
            $value = array_shift($args);
            $i++;
            switch ($type) {
                case 'i':
                    $out .= (string) (int) $value;
                    break;
                case 'd':
                    $out .= (string) (float) $value;
                    break;
                case 's':
                case 'l':
                    $out .= $quote($value);
                    break;
                case 'n':
                    $ints = array_map('intval', (array) $value);
                    $out .= $ints === [] ? 'NULL' : implode(',', $ints);
                    break;
                case 'a':
                    $strings = array_map($quote, (array) $value);
                    $out .= $strings === [] ? 'NULL' : implode(',', $strings);
                    break;
                case 'u':
                    $out .= $pairs((array) $value);
                    break;
                case 'e':
                    $out .= 'SET ' . $pairs((array) $value);
                    break;
                case 'p':
                    $out .= (string) $value;
                    break;
            }
        }

        return $out;
    }

    /** INSERT: the new id; anything else: the rows it changed. */
    public static function query(string $sql, ...$args): int
    {
        $db = \Db::getInstance();
        $query = self::build($sql, $args);
        if (!$db->execute($query, false)) {
            return 0;
        }

        return stripos(ltrim($query), 'INSERT') === 0 ? (int) $db->Insert_ID() : (int) $db->Affected_Rows();
    }

    /**
     * @return string|null the first column of the first row
     *
     * Not PrestaShop's getValue(): it always appends its own LIMIT 1, and a query that ends in
     * LIMIT already (the ported ones do) then fails as "LIMIT 1 LIMIT 1".
     */
    public static function field(string $sql, ...$args): ?string
    {
        $row = self::row($sql, ...$args);
        if ($row === []) {
            return null;
        }
        $value = reset($row);

        return $value === false || $value === null ? null : (string) $value;
    }

    /** @return list<string> the first column of every row */
    public static function fields(string $sql, ...$args): array
    {
        $out = [];
        foreach (self::all($sql, ...$args) as $row) {
            $out[] = (string) reset($row);
        }

        return $out;
    }

    /** @return list<array<string, string|null>> */
    public static function all(string $sql, ...$args): array
    {
        $rows = \Db::getInstance()->executeS(self::build($sql, $args), true, false);

        return is_array($rows) ? array_values($rows) : [];
    }

    /** @return array<string, string|null> the first row, [] when none */
    public static function row(string $sql, ...$args): array
    {
        $rows = self::all($sql, ...$args);

        return $rows[0] ?? [];
    }

    /**
     * @param array{0: string, 1: string} $columns key column, value column
     * @return array<string, string|null>
     */
    public static function hash(string $sql, array $columns, ...$args): array
    {
        $out = [];
        foreach (self::all($sql, ...$args) as $row) {
            $out[(string) $row[$columns[0]]] = $row[$columns[1]];
        }

        return $out;
    }

    public static function quote(string $sql, ...$args): string
    {
        return self::build($sql, $args);
    }

    /** @param list<mixed> $args */
    private static function build(string $sql, array $args): string
    {
        return self::format($sql, $args, _DB_PREFIX_, static function (string $value): string {
            return (string) \Db::getInstance()->escape($value, false);
        });
    }

    private function __construct()
    {
    }
}
