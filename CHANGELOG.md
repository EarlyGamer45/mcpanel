# Changelog

## 2.1.0 – Open source release

- Published under the MIT licence as a **vibecoded** project (see README).
- Generic branding: the default name is "MCPanel Hosting". Change it in Admin → Appearance.
- English is now the default language (Italian is still included).
- Demo and default catalogue data translated to English. The package slugs are now
  `package-2gb` … `package-16gb` and `custom-server`. Installs that already have
  the old Italian slugs are recognised by `catalog:packages`, so no duplicates are created.
- Detailed documentation moved to `docs/it/` (Italian).

## 2.0.0

- **Catalogue**
  - Fixed packages, a custom configurator (CPU / RAM / SSD) and optional
    expansion packs inside each package.
  - CPU is shown as vCPU (cores).
- **Admin**
  - Manage customers (block, disable, delete), accept wallet top-ups, delete
    any order, edit nodes and locations.
  - Activate the licence from the browser.
- **Accounts**
  - Customers and admins can change their email and password.
  - Login with email or username.
  - New console commands `user:create`, `user:password`, `user:email`,
    `user:delete`, `user:list-admins` and `catalog:packages`.
- **Licence**
  - Sales and customisation are blocked without a valid licence.
  - The licence server can run locally or on a separate machine.
- **Encryption keys**
  - The keys are copied to `/etc/mcpanel/keys.env` and reused on reinstall, so
    stored emails are no longer lost.

The full history of the Italian releases is in [docs/it/CHANGELOG.md](docs/it/CHANGELOG.md).
