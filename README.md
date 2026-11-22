# MCPanel – Minecraft Hosting Platform

> [!WARNING]
> **This is a VIBECODED project.** Most of the code was written with the help of an
> AI assistant and has not been professionally audited. It is published as-is for
> learning and self-hosting. **The author takes no responsibility for bugs, data loss,
> security issues, lost payments, downtime or any other problem caused by this
> software.** Read the code, test it on your own infrastructure and use it at your
> own risk. See [LICENSE](LICENSE).

A self-hosted billing and selling panel for Minecraft server hosting, built on top
of [Pterodactyl](https://pterodactyl.io). Customers pick a ready-made package or
build their own server, pay, and the server is created automatically on your
Pterodactyl nodes.

No framework and no Composer: plain PHP 8.5 (small MVC), MariaDB in production,
SQLite for demo and tests, Nginx + PHP-FPM, systemd. Pterodactyl itself is not
modified; the panel only talks to its official APIs.

## Tested on

**Ubuntu Server 26.04.1 LTS**: both the panel and the licence server have been
installed and tested on it and work correctly.

## Features

- **Catalogue**
  - Fixed packages with set RAM / vCPU / SSD and price (e.g. 2 GB / 1 vCPU / 5 GB SSD).
  - A "custom server" configurator: the customer picks CPU, RAM and SSD and pays
    for what they choose.
  - Optional expansion packs inside every package (+RAM, +vCPU, +SSD, CPU
    performance, daily backups and other services).
  - Everything is editable from the admin panel; prices are entered in euros and cents.
- **Checkout**
  - Cart, coupons, and monthly / bimonthly / semestral / yearly billing cycles.
  - VAT handling and a risk engine with manual review.
- **Payments**
  - Stripe Checkout, PayPal, manual bank transfer and a prepaid wallet.
  - Customers can request wallet top-ups and admins accept or reject them.
- **Provisioning**
  - Creates the Pterodactyl user and server: allocation, egg and variables.
  - Retries with backoff, compensation and reconciliation.
- **Lifecycle**
  - Renewal invoices, reminders, suspension with a grace period, termination and reactivation.
  - Upgrades and downgrades with proration.
- **Client area**
  - Services with a websocket console, PDF invoices, wallet and tickets.
  - Email and password change, TOTP 2FA, GDPR export and deletion.
- **Admin area**
  - Orders (any order can be deleted), services, tickets, customers (block / disable / delete).
  - Products, expansion packs, coupons, invoices and reports.
  - Nodes and locations, appearance and theme, feature flags, logs.
- **Languages**
  - English (default) and Italian. Add a language by copying `lang/en.php`.
- **Optional licence server**
  - A separate app included in `license-server/`, using Ed25519-signed responses.

## Requirements

| Environment | Requirements |
|---|---|
| Production | Ubuntu Server 26.04 LTS (tested on 26.04.1), PHP 8.5 FPM (`pdo_mysql`, `mbstring`, `curl`, `intl`, `zip`), MariaDB 11.x, Nginx |
| Development | PHP 8.5 CLI with `pdo_sqlite`, `openssl`, `sodium` |
| External | Pterodactyl Panel 1.11+ with Application API and Client API keys |

## Production install

```bash
git clone https://github.com/<you>/mcpanel.git
cd mcpanel

# guided install: asks for a domain (HTTPS) or an IP address (HTTP)
sudo bash deploy/install.sh

# or non-interactive
sudo DOMAIN=panel.example.com LETSENCRYPT_EMAIL=you@example.com bash deploy/install.sh
sudo ADDRESS_MODE=ip SERVER_IP=203.0.113.10 bash deploy/install.sh
```

The script installs the packages and creates the database and `.env`. It then
runs the migrations and sets up the systemd worker, the timers and the Nginx vhost.

After it finishes:

1. Open the address it prints and go to `/install` to create the administrator.
2. In `.env`, fill in the Pterodactyl URL and keys, Stripe/PayPal and SMTP.
3. Restart the services with `sudo systemctl restart mcpanel-worker php8.5-fpm`.

To update later, run `sudo bash deploy/update.sh`. It takes a backup, then runs the migrations and a health check.

> [!IMPORTANT]
> Emails and personal data are encrypted with `APP_KEY` and `BLIND_INDEX_KEY` in
> `.env`. The installer also keeps a copy of them in `/etc/mcpanel/keys.env`.
> **Never delete `.env`, and back up `/etc/mcpanel/keys.env`.** If the keys are lost,
> usernames and passwords still work, but stored emails can no longer be read.

## Licence server (optional)

When no valid licence is active, the panel blocks sales and customisation. You
can run your own licence server, either on the same machine or on a separate one:

```bash
sudo LICENSE_MODE=local bash license-server/deploy/install.sh   # same machine, 127.0.0.1:8090
sudo php /var/www/license-server/bin/issue.php standard 365 1   # prints MCH-XXXX-XXXX-XXXX-XXXX
sudo bash deploy/set-license.sh                                 # connects the panel and activates the key
```

You can also activate a key from the browser in **Admin → License**.
More details are in [license-server/README.md](license-server/README.md).

## Console commands

Run them from the panel folder as the web user:

```bash
sudo -u www-data php bin/console <command>
```

| Command | What it does |
|---|---|
| `user:list-admins` | List administrator accounts |
| `user:create <username> <email> <password>` | Create a super admin |
| `user:password <username\|email> <new-password> [--disable-2fa]` | Reset a password |
| `user:email <username\|email> <new-email>` | Set or change an account email |
| `user:delete <username\|email> --yes` | Delete an account |
| `license:activate <key>` / `license:check` / `license:status` | Licence management |
| `catalog:packages [--hide-others]` | Add the default packages, configurator and expansion packs |
| `migrate`, `about`, `queue:work`, `reconcile`, `prune` | Maintenance |

Users can log in with either their email or their username.

## Development

```bash
cp .env.example .env
php bin/console key:generate
php bin/console key:generate --blind
# set DB_DRIVER=sqlite and APP_URL=http://127.0.0.1:8080 in .env
php bin/console migrate
php bin/console db:seed          # demo data – never in production
php -S 127.0.0.1:8080 -t public
```

The demo customer is `demo@example.com` / `Demo12345!`. To create an admin, run
`php bin/console user:create admin admin@example.com 'Admin12345!'`.

### Tests

```bash
php tests/run.php            # SQLite + mocked Pterodactyl API
php tests/lang-check.php     # missing / duplicated translation keys
bash tests/deploy-address.sh # installer address handling (Linux)
```

## Project layout

```
bin/              console and queue worker
bootstrap/        classmap autoloader
config/           configuration read from .env
database/         migrations and demo seeder
deploy/           install.sh, update.sh, set-license.sh, Nginx and systemd files
docs/it/          detailed documentation (Italian, translations welcome)
lang/             en.php, it.php
license-server/   standalone licence server (Ed25519)
public/           web root
resources/views/  PHP templates
src/              Core (HTTP, DB, crypto, auth, queue), Domain, Http
storage/          logs, cache, invoices (not versioned)
tests/            dependency-free test suite
```

## Security notes

- There are no secrets in the repository. `.env` is ignored and keys are generated at install time.
- Passwords are hashed with Argon2id, and sensitive data is encrypted with AES-256-GCM.
- Every change is protected by CSRF, the CSP has no inline scripts, and logins and webhooks are rate limited.
- An audit log records admin actions.

None of this makes the project audited or production-certified; see the warning
at the top.

Please report security problems privately rather than in a public issue.

## Contributing

Issues and pull requests are welcome. Translating the documentation in `docs/it/`
into English would be especially helpful.

## License

[MIT](LICENSE), provided **"as is", without warranty of any kind**. The authors are
not liable for any claim, damages or other liability arising from the use of
this software.

Not affiliated with Mojang Studios, Microsoft or Pterodactyl. "Minecraft" is a
trademark of Mojang Studios.
