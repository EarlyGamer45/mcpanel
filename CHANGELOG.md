# Changelog

## 1.0.0 – First public release

First open source release of MCPanel, published under the MIT licence as a
**vibecoded** project (see README). Tested on Ubuntu Server 26.04.1 LTS: the
panel and the licence server both work.

### Catalogue and sales
- Fixed packages with set RAM, vCPU, SSD and price.
- "Custom server" configurator: the customer picks CPU, RAM and SSD and the
  price follows the choice.
- Optional expansion packs inside each package: +RAM, +vCPU, +SSD, CPU
  performance, daily backups and extra services.
- CPU shown as vCPU (cores); prices entered in euros and cents.
- Cart, coupons, monthly / bimonthly / semestral / yearly billing cycles, VAT.

### Payments and billing
- Stripe Checkout, PayPal, bank transfer and prepaid wallet.
- Wallet top-up requests, accepted or rejected by the admin.
- PDF invoices, renewal invoices, reminders, suspension, termination.

### Provisioning
- Automatic server creation on Pterodactyl (user, allocation, egg, variables),
  retries, reconciliation, nodes and locations editable from the panel.

### Client area
- Services with console, invoices, wallet, tickets.
- Change email and password, TOTP 2FA, login with email or username.

### Admin area
- Orders (any order can be deleted), services, tickets.
- Customers: block, disable, delete, reset password.
- Products, expansion packs, coupons, reports, appearance, feature flags, logs.
- Licence activation from the browser.

### Console
- `user:create`, `user:password`, `user:email`, `user:delete`, `user:list-admins`.
- `license:activate`, `license:check`, `license:status`, `catalog:packages`.

### Licence server
- Standalone app with Ed25519-signed responses; runs on the same machine or a
  separate one. Without a valid licence the panel blocks sales and customisation.

### Install and security
- `deploy/install.sh` (domain + HTTPS or IP + HTTP), `deploy/update.sh`.
- Encryption keys kept in `/etc/mcpanel/keys.env` so reinstalls do not lose emails.
- Argon2id passwords, AES-256-GCM encryption, CSRF, CSP, rate limiting, audit log.
- English by default, Italian included.

The history of the private development builds is in [docs/it/CHANGELOG.md](docs/it/CHANGELOG.md) (Italian).
