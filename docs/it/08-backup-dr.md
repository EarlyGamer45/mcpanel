# Backup e disaster recovery

## Cosa salvare

| Dato | Dove | Frequenza | Retention |
|---|---|---|---|
| Database (clienti, ordini, fatture, servizi) | MariaDB | ogni notte + binlog | 30 giorni notturni, 12 mesi mensili |
| `.env` e chiavi | server (root) | a ogni modifica | in password manager + copia offline |
| Fatture PDF | `storage/invoices/` | ogni notte | 10 anni (obbligo fiscale) |
| Log applicativi | `storage/logs/` | ogni notte | 90 giorni |
| File caricati dai ticket | `storage/` (fuori web root) | ogni notte | 90 giorni |
| Codice | git + release tar | a ogni deploy | ultime 10 release |
| Configurazione Nginx/systemd | `/etc` | a ogni modifica | 10 versioni |

I **server dei clienti** non sono in questo elenco: i dati Minecraft vivono nei
volumi Pterodactyl e vanno backuppati dal pannello Pterodactyl o dallo storage
dei nodi. La piattaforma non tocca mai quei file.

## Backup notturno

```bash
#!/usr/bin/env bash
set -euo pipefail
STAMP=$(date -u +%Y%m%d)
DEST=/var/backups/mcpanel
mkdir -p "$DEST"

# database
MYSQL_PWD="$DB_PASSWORD" mysqldump --single-transaction --quick --routines \
  -h 127.0.0.1 -u "$DB_USERNAME" "$DB_DATABASE" | gzip > "$DEST/db-$STAMP.sql.gz"

# file applicativi
tar -czf "$DEST/app-$STAMP.tar.gz" \
  -C /var/www/mcpanel storage/invoices storage/logs bootstrap/cache .env

# cifratura (opzionale ma consigliata)
gpg --encrypt --recipient ops@example.com --output "$DEST/db-$STAMP.sql.gz.gpg" "$DEST/db-$STAMP.sql.gz"
rm "$DEST/db-$STAMP.sql.gz"

# retention
find "$DEST" -type f -mtime +30 -delete
```

Salvare lo script in `/usr/local/sbin/mcpanel-backup` e schedulare con cron:

```cron
15 3 * * * root /usr/local/sbin/mcpanel-backup >> /var/log/mcpanel-backup.log 2>&1
```

Copiare i backup fuori dal server (S3 compatibile, Backblaze B2, rsync su host
diverso). Un backup sullo stesso disco non è un backup.

## Verifica del backup

Una volta al mese, su una macchina di staging:

```bash
gunzip -c db-YYYYMMDD.sql.gz | mysql -u root staging_mcpanel
cd /var/www/mcpanel && php bin/console migrate
php tests/run.php
```

Controllare che le ultime fatture e gli ultimi ordini siano presenti. Un backup
mai ripristinato non è verificato.

## RPO / RTO

| Scenario | RPO | RTO | Procedura |
|---|---|---|---|
| Corruzione dati applicativa | 24 h (o 5 min con binlog) | 1-2 h | restore ultimo dump + replay binlog |
| Perdita del server | 24 h | 2-4 h | nuovo host, install.sh, restore dump, DNS |
| Perdita del server licenze | nessuna (chiavi offline) | 1 h | ripristinare chiavi e SQLite, ripubblicare |
| Errore di deploy | 0 (release precedente) | 15 min | `deploy/update.sh` rollback |
| Compromissione | variabile | 4-8 h | isolare, ruotare chiavi, ripristinare da backup pulito |

## Procedura di ripristino completa

1. **Fermare** worker e scheduler sul vecchio host per evitare doppie fatture:
   `systemctl stop mcpanel-worker mcpanel-scheduler.timer`.
2. Preparare il nuovo host con `deploy/install.sh` (senza completare `/install`).
3. Ripristinare il database:
   `gunzip -c db.sql.gz | mysql -u mchosting -p mchosting`.
4. Ripristinare `.env` (stesse chiavi `APP_KEY`, `BLIND_INDEX_KEY`,
   `LICENSE_PUBLIC_KEY`) e i file in `storage/`.
5. Eseguire `php bin/console migrate` (idempotente).
6. Ruotare le chiavi Pterodactyl e Stripe/PayPal se c'è stato un sospetto
   accesso non autorizzato; aggiornare il webhook URL se cambia il dominio.
7. Avviare worker e scheduler, verificare `/api/v1/health`.
8. Test funzionale: login, una fattura PDF, un ordine di test, `reconcile`.

## Scenari di emergenza

### Database irraggiungibile

Il sito risponde 503 con pagina di errore; i server dei clienti continuano a
girare. Non riavviare i worker a vuoto: ripristinare il DB o il servizio MariaDB,
poi `systemctl restart mcpanel-worker`.

### Pterodactyl irraggiungibile

Il sito continua a vendere, ma il provisioning resta in coda. I job ritentano con
backoff. Quando il pannello torna, i job ripartono; usare
`php bin/console reconcile` per verificare i servizi creati a metà.

### Webhook persi

Stripe e PayPal ritentano per giorni. Se un pagamento risulta incassato ma
l'ordine è ancora `pending_payment`, verificare nella dashboard del gateway e
confermare manualmente dall'admin (bonifico) solo dopo aver controllato l'ID
pagamento. Non creare un secondo pagamento.

### Licenza non valida

Il pannello entra in grace period e continua a servire i clienti. Se
`enforce_license=true`, le nuove vendite si bloccano. Contattare il fornitore
della licenza o ripristinare `license_state` da backup.

## Sicurezza dei backup

- cifratura a riposo (GPG o storage cifrato);
- accesso limitato agli operatori;
- test di restore periodico;
- nessun backup nei repository git o nelle cartelle pubbliche;
- le chiavi di cifratura non stanno sullo stesso host dei backup.
