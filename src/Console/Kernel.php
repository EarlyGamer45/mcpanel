<?php

declare(strict_types=1);

namespace App\Console;

use App\Core\App;
use App\Core\Database;
use App\Core\Json;
use App\Core\Queue;
use App\Core\Schema;
use App\Domain\Automation;
use App\Domain\Installer;
use App\Domain\LicenseClient;
use App\Domain\ProvisioningService;

final class Kernel
{
    /** @param list<string> $args */
    public static function run(string $command, array $args): int
    {
        try {
            return match ($command) {
                'key:generate' => self::keyGenerate($args),
                'migrate' => self::migrate(),
                'db:seed' => self::seed(),
                'user:create' => self::createUser($args),
                'user:password' => self::resetPassword($args),
                'user:list-admins' => self::listAdmins(),
                'user:delete' => self::deleteUser($args),
                'user:email' => self::changeEmail($args),
                'license:check' => self::licenseCheck(),
                'license:activate' => self::licenseActivate($args),
                'license:status' => self::licenseStatus(),
                'catalog:packages' => self::catalogPackages($args),
                'renewals' => self::counted('renewal invoices', Automation::renewInvoices()),
                'suspend' => self::counted('suspended services', Automation::suspendOverdue()),
                'terminate' => self::counted('terminated services', Automation::terminateExpired()),
                'carts:recover' => self::counted('cart reminders', Automation::recoverCarts()),
                'tickets:close' => self::counted('closed tickets', Automation::closeInactiveTickets()),
                'prune' => self::prune(),
                'queue:work' => self::queueWork($args),
                'reconcile' => self::reconcile(),
                'schema:dump' => self::schemaDump(),
                'about' => self::about(),
                default => self::help(),
            };
        } catch (\Throwable $e) {
            fwrite(STDERR, '[error] ' . $e->getMessage() . PHP_EOL);
            if ((bool) config('app.debug', false)) {
                fwrite(STDERR, $e->getTraceAsString() . PHP_EOL);
            }

            return 1;
        }
    }

    /** @param list<string> $args */
    private static function keyGenerate(array $args): int
    {
        $blind = in_array('--blind', $args, true) || in_array('blind', $args, true);
        $file = App::path('.env');
        if (!is_file($file)) {
            if (is_file(App::path('.env.example'))) {
                copy(App::path('.env.example'), $file);
                self::line('Created .env from .env.example');
            } else {
                file_put_contents($file, '');
            }
        }
        $content = (string) file_get_contents($file);
        if ($blind) {
            $value = base64_encode(random_bytes(32));
            $content = preg_replace('/^BLIND_INDEX_KEY=.*$/m', 'BLIND_INDEX_KEY=' . $value, $content, 1) ?? $content;
            if (!str_contains($content, 'BLIND_INDEX_KEY=')) {
                $content .= PHP_EOL . 'BLIND_INDEX_KEY=' . $value . PHP_EOL;
            }
            self::line('BLIND_INDEX_KEY generated.');
        } else {
            $value = base64_encode(random_bytes(32));
            $content = preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=' . $value, $content, 1) ?? $content;
            if (!str_contains($content, 'APP_KEY=')) {
                $content .= PHP_EOL . 'APP_KEY=' . $value . PHP_EOL;
            }
            self::line('APP_KEY generated.');
        }
        file_put_contents($file, $content);

        return 0;
    }

    private static function migrate(): int
    {
        $db = App::db();
        Schema::install($db);
        $ran = [];
        foreach ($db->select('SELECT migration FROM migrations') as $row) {
            $ran[(string) $row['migration']] = true;
        }
        $batch = (int) $db->value('SELECT COALESCE(MAX(batch),0) FROM migrations') + 1;
        $files = glob(App::path('database/migrations/*.php')) ?: [];
        sort($files);
        foreach ($files as $file) {
            $name = basename($file);
            if (isset($ran[$name])) {
                continue;
            }
            $migration = require $file;
            if (is_callable($migration)) {
                $migration($db);
            }
            $db->insert('migrations', ['migration' => $name, 'batch' => $batch, 'ran_at' => Database::now()]);
            self::line('migrated: ' . $name);
        }
        self::line('Schema ready (' . $db->driver() . ').');

        return 0;
    }

    private static function seed(): int
    {
        Installer::defaults();
        Installer::demos();
        self::line('Demo data ready. Login: demo@example.com / Demo12345!');

        return 0;
    }

    /** @param list<string> $args */
    private static function createUser(array $args): int
    {
        if (count($args) < 3) {
            self::line('Usage: php bin/console user:create <username> <email> <password>');

            return 1;
        }
        Installer::defaults();
        $user = Installer::admin($args[0], $args[1], $args[2]);
        self::line('Admin created: #' . $user['id'] . ' ' . $user['username']);

        return 0;
    }

    /** @param list<string> $args */
    private static function resetPassword(array $args): int
    {
        $disable2fa = in_array('--disable-2fa', $args, true);
        $args = array_values(array_filter($args, static fn (string $arg): bool => $arg !== '--disable-2fa'));
        $who = trim($args[0] ?? '');
        if ($who === '') {
            self::line('Usage: php bin/console user:password <username|email> [new-password] [--disable-2fa]');

            return 1;
        }
        $db = App::db();
        $user = str_contains($who, '@')
            ? \App\Domain\UserRepository::findByEmail($who)
            : $db->selectOne('SELECT * FROM users WHERE username = :u', ['u' => $who]);
        if ($user === null) {
            self::line('User not found: ' . $who . '  (php bin/console user:list-admins shows the admin usernames)');

            return 1;
        }
        $password = $args[1] ?? '';
        $generated = $password === '';
        if ($generated) {
            $password = substr(strtr(base64_encode(random_bytes(18)), '+/', 'Kx'), 0, 20);
        }
        if (strlen($password) < 10) {
            self::line('The password must be at least 10 characters long.');

            return 1;
        }
        $id = (int) $user['id'];
        \App\Domain\UserRepository::setPassword($id, $password);
        $update = ['failed_logins' => 0, 'locked_until' => null];
        if ($disable2fa) {
            $update['totp_enabled'] = 0;
            $update['totp_secret_enc'] = null;
        }
        $db->update('users', $update, 'id = :id', ['id' => $id]);
        // Sign out every open session of this account.
        $db->execute('UPDATE user_sessions SET revoked_at = :now WHERE user_id = :id AND revoked_at IS NULL', ['now' => Database::now(), 'id' => $id]);
        \App\Core\Audit::log('console.password_reset', 'user', $id, ['disable_2fa' => $disable2fa]);

        self::line('Password updated for #' . $id . ' ' . (string) $user['username'] . ($disable2fa ? ' (2FA disabled)' : ''));
        if ($generated) {
            self::line('New password: ' . $password);
        }

        return 0;
    }

    /** @param list<string> $args */
    private static function deleteUser(array $args): int
    {
        $confirmed = in_array('--yes', $args, true);
        $who = trim((string) (array_values(array_filter($args, static fn (string $arg): bool => !str_starts_with($arg, '--')))[0] ?? ''));
        if ($who === '') {
            self::line('Usage: php bin/console user:delete <username|email> --yes');

            return 1;
        }
        $db = App::db();
        $user = str_contains($who, '@')
            ? \App\Domain\UserRepository::findByEmail($who)
            : $db->selectOne('SELECT * FROM users WHERE username = :u', ['u' => $who]);
        if ($user === null || ($user['status'] ?? '') === 'deleted') {
            self::line('User not found: ' . $who);

            return 1;
        }
        $id = (int) $user['id'];
        if (($user['role'] ?? 'customer') !== 'customer') {
            $admins = (int) $db->value("SELECT COUNT(*) FROM users WHERE role <> 'customer' AND status <> 'deleted' AND id <> :id", ['id' => $id]);
            if ($admins === 0) {
                self::line('Refused: this is the last administrator. Create another one first (user:create).');

                return 1;
            }
        }
        $active = (int) $db->value(
            'SELECT COUNT(*) FROM services WHERE user_id = :user AND status IN (:active, :suspended)',
            ['user' => $id, 'active' => 'active', 'suspended' => 'suspended']
        );
        if ($active > 0) {
            self::line('Refused: the account still has ' . $active . ' active or suspended service(s). Cancel or terminate them first.');

            return 1;
        }
        if (!$confirmed) {
            self::line('About to delete #' . $id . ' ' . (string) $user['username'] . ' (' . (string) $user['role'] . '). Personal data is erased, invoices are kept.');
            self::line('Run again with --yes to confirm.');

            return 1;
        }
        $db->execute('UPDATE user_sessions SET revoked_at = :now WHERE user_id = :id AND revoked_at IS NULL', ['now' => Database::now(), 'id' => $id]);
        \App\Domain\UserRepository::anonymise($id);
        \App\Core\Audit::log('console.user_deleted', 'user', $id, []);
        self::line('Account #' . $id . ' deleted (anonymised as deleted-' . $id . ').');

        return 0;
    }

    /** @param list<string> $args */
    private static function changeEmail(array $args): int
    {
        $who = trim($args[0] ?? '');
        $email = mb_strtolower(trim($args[1] ?? ''));
        if ($who === '' || $email === '') {
            self::line('Usage: php bin/console user:email <username|email> <new-email>');

            return 1;
        }
        $db = App::db();
        $user = str_contains($who, '@')
            ? \App\Domain\UserRepository::findByEmail($who)
            : $db->selectOne('SELECT * FROM users WHERE username = :u', ['u' => $who]);
        if ($user === null || ($user['status'] ?? '') === 'deleted') {
            self::line('User not found: ' . $who);

            return 1;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            self::line('Invalid e-mail address: ' . $email);

            return 1;
        }
        $other = \App\Domain\UserRepository::findByEmail($email);
        if ($other !== null && (int) $other['id'] !== (int) $user['id']) {
            self::line('Refused: ' . $email . ' is already used by account #' . (int) $other['id'] . ' ' . (string) $other['username'] . '.');

            return 1;
        }
        $old = \App\Domain\UserRepository::present($user)['email'];
        \App\Domain\UserRepository::changeEmail((int) $user['id'], $email);
        \App\Core\Audit::log('console.email_changed', 'user', (int) $user['id'], []);
        self::line('E-mail for #' . (int) $user['id'] . ' ' . (string) $user['username'] . ': ' . ($old !== '' ? $old : '-') . ' -> ' . $email);
        self::line('The account now signs in with ' . $email . ' (the password is unchanged).');

        return 0;
    }

    private static function listAdmins(): int
    {
        $rows = App::db()->select("SELECT id, username, role, totp_enabled, locked_until FROM users WHERE role <> 'customer' AND status <> 'deleted' ORDER BY id ASC");
        if ($rows === []) {
            self::line('No administrators. Create one: php bin/console user:create <username> <email> <password>');

            return 0;
        }
        foreach ($rows as $row) {
            self::line(sprintf('#%-4d %-20s %-12s 2FA:%s%s', $row['id'], $row['username'], $row['role'], (int) $row['totp_enabled'] === 1 ? 'on ' : 'off', !empty($row['locked_until']) ? '  locked until ' . $row['locked_until'] : ''));
        }

        return 0;
    }

    private static function licenseCheck(): int
    {
        self::line(Json::encode(LicenseClient::check(true)));
        self::line('status: ' . LicenseClient::status());

        return LicenseClient::isValid() ? 0 : 2;
    }

    /** @param list<string> $args */
    private static function licenseActivate(array $args): int
    {
        if (($args[0] ?? '') === '') {
            self::line('Usage: php bin/console license:activate <key> [server-url]');

            return 1;
        }
        $payload = LicenseClient::activate($args[0], $args[1] ?? null);
        self::line('Licence activated: ' . (string) ($payload['plan'] ?? '') . ', status ' . LicenseClient::status()
            . (!empty($payload['expires_at']) ? ', expires ' . (string) $payload['expires_at'] : ', no expiry'));

        return 0;
    }

    /** @param list<string> $args */
    private static function catalogPackages(array $args): int
    {
        $result = \App\Domain\Installer::packages(in_array('--hide-others', $args, true));
        self::line('Products added: ' . $result['products'] . ', expansion packs added: ' . $result['packs']
            . ($result['hidden'] > 0 ? ', other products hidden: ' . $result['hidden'] : ''));
        self::line('Edit prices and resources in Admin -> Products and Admin -> Expansion packs.');

        return 0;
    }

    private static function licenseStatus(): int
    {
        self::line('status:        ' . LicenseClient::status());
        self::line('server:        ' . (LicenseClient::serverUrl() !== '' ? LicenseClient::serverUrl() : '-'));
        self::line('public key:    ' . (LicenseClient::hasPublicKey() ? 'configured' : 'MISSING (LICENSE_PUBLIC_KEY)'));
        self::line('sales:         ' . (LicenseClient::allowsSales() ? 'open' : 'BLOCKED'));
        self::line('customisation: ' . (LicenseClient::allowsCustomization() ? 'allowed' : 'BLOCKED'));
        self::line('fingerprint:   ' . \App\Core\App::fingerprint());

        return LicenseClient::isValid() ? 0 : 2;
    }

    private static function counted(string $label, int $count): int
    {
        self::line($count . ' ' . $label . '.');

        return 0;
    }

    private static function prune(): int
    {
        self::line(Json::encode(Automation::prune()));

        return 0;
    }

    /** @param list<string> $args */
    private static function queueWork(array $args): int
    {
        $queue = 'default';
        $once = false;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--queue=')) {
                $queue = substr($arg, 8);
            }
            $once = $once || $arg === '--once';
        }
        if ($once) {
            $worked = Queue::runNext($queue) ? 1 : 0;
        } else {
            self::line('Worker started on queue "' . $queue . '". Ctrl+C to stop.');
            $worked = Queue::work($queue, 0);
        }
        self::line('Processed jobs: ' . $worked);

        return 0;
    }

    private static function reconcile(): int
    {
        self::line(Json::encode(ProvisioningService::reconcile()));

        return 0;
    }

    private static function schemaDump(): int
    {
        $sql = "-- MariaDB schema generated from App\\Core\\Schema\n\n"
            . implode(";\n\n", Schema::ddl('mysql')) . ";\n";
        file_put_contents(App::path('database/schema.mariadb.sql'), $sql);
        self::line('Written database/schema.mariadb.sql');

        return 0;
    }

    private static function about(): int
    {
        self::line('Minecraft Hosting Platform ' . App::version());
        self::line('PHP ' . PHP_VERSION . ' · DB ' . App::db()->driver());
        self::line('Installed: ' . (App::isInstalled() ? 'yes' : 'no'));

        return 0;
    }

    private static function help(): int
    {
        self::line('Minecraft Hosting Platform ' . App::version());
        self::line('Commands:');
        foreach ([
            'key:generate [--blind]' => 'Generate APP_KEY / BLIND_INDEX_KEY',
            'migrate' => 'Create or update the database schema',
            'db:seed' => 'Seed defaults and demo data',
            'user:create <username> <email> <password>' => 'Create a super admin',
            'user:password <username|email> [password] [--disable-2fa]' => 'Reset a password (random if omitted), unlock the account',
            'user:list-admins' => 'List administrator accounts',
            'user:delete <username|email> --yes' => 'Delete (anonymise) an account; invoices are kept',
            'user:email <username|email> <new-email>' => 'Assign a new e-mail address to an account',
            'renewals' => 'Issue renewal invoices',
            'suspend' => 'Suspend overdue services',
            'terminate' => 'Terminate services after the cooling period',
            'carts:recover' => 'Send abandoned cart emails',
            'tickets:close' => 'Close inactive tickets',
            'prune' => 'Delete expired logs and jobs',
            'queue:work [--queue=default] [--once]' => 'Run queue workers',
            'reconcile' => 'Compare services with Pterodactyl',
            'license:activate <key> [server-url]' => 'Activate a license key',
            'license:check' => 'Verify the license now',
            'catalog:packages [--hide-others]' => 'Add the ready-made packages, the configurator and the expansion packs',
            'license:status' => 'Show the license status and what it unlocks',
            'schema:dump' => 'Write the MariaDB DDL to database/schema.mariadb.sql',
            'about' => 'Show environment information',
        ] as $command => $description) {
            self::line(sprintf('  %-45s %s', $command, $description));
        }

        return 0;
    }

    private static function line(string $message): void
    {
        fwrite(STDOUT, $message . PHP_EOL);
    }
}
