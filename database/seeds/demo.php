<?php

declare(strict_types=1);

/**
 * Demo seeder: catalog, legal pages, knowledge base, a demo customer with two
 * services, a paid invoice and a ticket. Safe to run multiple times.
 *
 *   php database/seeds/demo.php
 */

require dirname(__DIR__, 2) . '/bootstrap/autoload.php';

use App\Core\App;
use App\Domain\Installer;

App::boot(dirname(__DIR__, 2));

// Migrations are idempotent and required before seeding.
\App\Core\Schema::install(App::db());
Installer::defaults();
Installer::demos();

fwrite(STDOUT, "Demo data ready.\nCustomer: demo@example.com / Demo12345!\n");
