<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Schema;

/**
 * Initial schema. Schema::install is idempotent (CREATE TABLE IF NOT EXISTS),
 * so this migration can safely run on an existing database during upgrades.
 */
return static function (Database $db): void {
    Schema::install($db);
};
