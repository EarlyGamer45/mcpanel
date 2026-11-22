<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Transaction-safe counters (invoice numbers, order numbers). Must be called
 * inside a database transaction: the row is locked with SELECT ... FOR UPDATE on
 * MariaDB so two concurrent requests can never receive the same number.
 */
final class Sequences
{
    public static function next(string $name): int
    {
        $db = App::db();
        $row = $db->selectOne('SELECT * FROM sequences WHERE name = :name' . $db->lockClause(), ['name' => $name]);
        if ($row === null) {
            try {
                $db->insert('sequences', ['name' => $name, 'value' => 1, 'updated_at' => Database::now()]);

                return 1;
            } catch (\PDOException $e) {
                if (!Schema::isBenign($e)) {
                    throw $e;
                }
                $row = $db->selectOne('SELECT * FROM sequences WHERE name = :name' . $db->lockClause(), ['name' => $name]);
            }
        }
        $value = (int) ($row['value'] ?? 0) + 1;
        $db->update('sequences', ['value' => $value, 'updated_at' => Database::now()], 'id = :id', ['id' => (int) $row['id']]);

        return $value;
    }
}
