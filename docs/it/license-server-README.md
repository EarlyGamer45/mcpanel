# License server

Applicazione autonoma per emettere e verificare le licenze della piattaforma.
Non condivide codice né database con il pannello: il pannello conserva solo la
chiave pubblica Ed25519 e verifica ogni risposta firmata.

## Requisiti

PHP 8.5 CLI/FPM con `sodium`, `pdo_sqlite`, `mbstring`. Nginx o Apache con
document root `public/`. Non serve Composer.

## Setup

```bash
php bin/keygen.php
# stampa LICENSE_PUBLIC_KEY=... da copiare in ogni pannello

php bin/issue.php standard 365 1
# MCH-XXXX-XXXX-XXXX-XXXX  (piano standard, 365 giorni, 1 attivazione)

php bin/revoke.php MCH-XXXX-XXXX-XXXX-XXXX revoked
```

Configurazione via variabili d'ambiente (vedi `config.php`):

| Variabile | Default | Note |
|---|---|---|
| `LICENSE_DB_PATH` | `storage/licenses.sqlite` | fuori dalla web root |
| `LICENSE_PRIVATE_KEY` | `keys/private.key` | 64 byte raw o base64, `0600` |
| `LICENSE_PUBLIC_KEY` | `keys/public.key` | solo per riferimento |
| `LICENSE_GRACE_DAYS` | `7` | il pannello applica il proprio valore |

## Nginx

```nginx
server {
    listen 443 ssl http2;
    server_name license.example.com;
    root /var/www/license-server/public;
    index index.php;

    location / { try_files $uri /index.php?$query_string; }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.5-fpm.sock;
    }
    location ~ /\.(?!well-known) { deny all; }
    add_header Strict-Transport-Security "max-age=31536000" always;
    add_header X-Content-Type-Options nosniff always;
}
```

La web root deve essere `public/`: `keys/`, `storage/` e `src/` non devono essere
raggiungibili via HTTP. Impostare i permessi: `chown -R www-data:www-data
storage`, `chmod 600 keys/private.key`.

## API

- `GET /api/v1/health` → `{"ok":true,"time":"…"}`
- `POST /api/v1/activate` → `{payload, signature}`
- `POST /api/v1/check` → `{payload, signature}`

Rate limit 30 richieste/minuto per IP, body JSON massimo 8 KB, risposte senza
dettagli interni. Il payload è firmato con Ed25519 sul JSON canonico; il pannello
rifiuta firme non valide e fingerprint diversi.

## Test locale

```bash
php bin/keygen.php
php bin/issue.php standard 30 1
php -S 127.0.0.1:8081 -t public
curl -s localhost:8081/api/v1/health
```

Per provare l'attivazione serve un fingerprint: il pannello lo calcola e lo invia.
In locale si può usare `MCH-…` con `php bin/console license:check` dopo aver
configurato `LICENSE_SERVER`, `LICENSE_KEY` e `LICENSE_PUBLIC_KEY`.

## Sicurezza

- HTTPS obbligatorio, HSTS.
- Chiave privata solo su questo host, mai in chat, log o repository.
- Backup cifrato di `storage/` e `keys/`; se la chiave privata è persa le licenze
  emesse non sono più verificabili.
- Ruotare la chiave solo distribuendo prima ai pannelli una release che accetta
  la nuova chiave pubblica.
