# License server

A standalone application that issues and verifies licences for the panel. It
shares no code or database with the panel: the panel only keeps the Ed25519
public key and checks the signature on every response.

## Requirements

PHP 8.5 CLI/FPM with `sodium`, `pdo_sqlite` and `mbstring`, served by Nginx with
`public/` as the document root. No Composer needed.

## Quick install (Ubuntu)

```bash
sudo LICENSE_MODE=local bash deploy/install.sh    # same machine as the panel, 127.0.0.1:8090
sudo LICENSE_MODE=domain LICENSE_DOMAIN=license.example.com LICENSE_EMAIL=you@example.com bash deploy/install.sh
sudo LICENSE_MODE=ip LICENSE_PORT=8090 ALLOW_IPS="203.0.113.10" bash deploy/install.sh
```

Then, on the panel, run `sudo bash deploy/set-license.sh`.

## Manual setup

```bash
php bin/keygen.php                       # prints LICENSE_PUBLIC_KEY=... for every panel
php bin/issue.php standard 365 1         # MCH-XXXX-XXXX-XXXX-XXXX (plan, days, activations)
php bin/list.php                         # list issued licences
php bin/revoke.php MCH-XXXX-XXXX-XXXX-XXXX revoked
```

Environment variables (see `config.php`):

| Variable | Default | Notes |
|---|---|---|
| `LICENSE_DB_PATH` | `storage/licenses.sqlite` | keep it outside the web root |
| `LICENSE_PRIVATE_KEY` | `keys/private.key` | 64 raw bytes or base64, mode `0600` |
| `LICENSE_PUBLIC_KEY` | `keys/public.key` | reference only |
| `LICENSE_GRACE_DAYS` | `7` | the panel applies its own value |

## API

- `GET /api/v1/health` returns `{"ok":true,"time":"…"}`.
- `POST /api/v1/activate` returns `{payload, signature}`.
- `POST /api/v1/check` returns `{payload, signature}`.

Limits:

- 30 requests per minute per IP.
- JSON bodies of at most 8 KB.
- Responses never include internal details.

## Security

- Use HTTPS when the server is reachable from the internet.
- Keep the private key on this host only. Never put it in a repository, chat or logs.
- Back up `storage/` and `keys/`. If the private key is lost, the licences already
  issued can no longer be verified.
