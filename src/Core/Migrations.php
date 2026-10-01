<?php
declare(strict_types=1);

namespace Saqf\Core;

/**
 * Schema upgrades for existing installations. Each file in database/migrations is applied
 * once, in name order, and recorded in schema_migrations. New installations apply
 * database/schema.sql and then every migration, so both paths end at the same schema.
 */
final class Migrations
{
    public static function dir(): string
    {
        return SAQF_ROOT . '/database/migrations';
    }

    /** @return list<string> migration names not yet applied */
    public static function pending(): array
    {
        self::ensureTable();
        $applied = array_flip(Db::col('SELECT version FROM schema_migrations'));
        $out = [];
        foreach (glob(self::dir() . '/*.sql') ?: [] as $file) {
            $name = basename($file, '.sql');
            if (!isset($applied[$name])) {
                $out[] = $name;
            }
        }
        sort($out);
        return $out;
    }

    /** Applies every pending migration. @return list<string> applied names */
    public static function run(?callable $log = null): array
    {
        $done = [];
        foreach (self::pending() as $name) {
            $sql = (string) file_get_contents(self::dir() . '/' . $name . '.sql');
            foreach (self::statements($sql) as $statement) {
                Db::pdo()->exec($statement);
            }
            Db::insert('schema_migrations', ['version' => $name, 'applied_at' => date('Y-m-d H:i:s')]);
            Audit::asSystem(static fn() => Audit::record('system.migrated', 'system', $name, "Database migration $name applied"));
            $done[] = $name;
            if ($log) {
                $log($name);
            }
        }
        // New policy keys introduced by an upgrade get their defaults.
        Policy::seedDefaults();
        return $done;
    }

    /** Splits a migration file into statements (no semicolons inside string literals). */
    public static function statements(string $sql): array
    {
        $sql = (string) preg_replace('/^\s*--.*$/m', '', $sql);
        return array_values(array_filter(array_map('trim', explode(';', $sql))));
    }

    private static function ensureTable(): void
    {
        Db::pdo()->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(80) PRIMARY KEY, applied_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
}
