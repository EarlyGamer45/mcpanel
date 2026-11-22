# Guida all'installazione

## 1. Prerequisiti

Server Ubuntu 26.04 LTS (minimo 2 vCPU, 4 GB RAM per il pannello) e porta HTTP
raggiungibile (80, o quella scelta con `HTTP_PORT`). Con un dominio e HTTPS: DNS
già puntato all'IP del server e porte 80 e 443 aperte. Pterodactyl deve essere
già installato (anche su un altro host) con almeno un nodo e un egg Minecraft.

## 2. Installazione automatica

Caricate i file in `/var/www/mcpanel`, poi scegliete come i clienti
raggiungeranno il sito.

**Procedura guidata** (chiede dominio o IP, HTTPS, porta e conferma):

```bash
sudo bash /var/www/mcpanel/deploy/install.sh
```

**Dominio con HTTPS Let's Encrypt** (consigliato per vendere al pubblico):

```bash
sudo DOMAIN=panel.example.com \
     LETSENCRYPT_EMAIL=ops@example.com \
     ADMIN_PATH=ops-7f3a91 \
     bash /var/www/mcpanel/deploy/install.sh
```

**Indirizzo IP in HTTP** (prove, rete locale, VPN; l'IP è rilevato in automatico
se `SERVER_IP` manca):

```bash
sudo ADDRESS_MODE=ip SERVER_IP=203.0.113.10 \
     ADMIN_PATH=ops-7f3a91 \
     bash /var/www/mcpanel/deploy/install.sh
```

| Variabile | Valori | Default |
|---|---|---|
| `ADDRESS_MODE` | `domain`, `ip` | `domain` se c'è `DOMAIN`, `ip` se c'è `SERVER_IP`, altrimenti chiesto |
| `DOMAIN` | nome host | — |
| `SERVER_IP` | IPv4 o IPv6 | IP dell'interfaccia di uscita |
| `HTTP_PORT` | 1–65535 | `80` (es. `8080` se la porta 80 è già usata da un altro sito) |
| `HTTPS_MODE` | `letsencrypt`, `proxy`, `off` | `letsencrypt` con dominio, `off` con IP |
| `LETSENCRYPT_EMAIL` | email | obbligatoria con `letsencrypt` |
| `SKIP_TLS=1` | — | equivale a `HTTPS_MODE=proxy` (TLS su un load balancer esterno) |

Cosa cambia tra le modalità:

| | Dominio + HTTPS | Dominio senza HTTPS | Indirizzo IP |
|---|---|---|---|
| `APP_URL` | `https://dominio` | `http://dominio[:porta]` | `http://IP[:porta]` (IPv6 tra `[]`) |
| Cookie di sessione | `Secure` | `auto` | `auto` (non Secure su HTTP) |
| HSTS | sì | no | no |
| Webhook Stripe/PayPal | sì | no | no |
| Certificato | Let's Encrypt, rinnovo automatico | — | — |

In modalità IP password e dati dei clienti viaggiano **in chiaro** e i webhook
dei pagamenti online non arrivano (Stripe e PayPal li inviano solo a URL HTTPS):
i pagamenti con carta o PayPal restano in attesa, il bonifico con conferma
manuale funziona. Usatela per provare il sistema o su reti private. Let's
Encrypt richiede un dominio, quindi l'installer non offre HTTPS su IP.

Se il certificato non si ottiene (DNS non ancora propagato, porta 80 chiusa)
l'installazione termina comunque in HTTP, con `APP_URL` coerente, e indica il
comando per riprovare.

Lo script:

1. installa Nginx, MariaDB 11.8, PHP 8.5 FPM e le estensioni richieste;
2. crea database e utente con password casuale;
3. genera `APP_KEY` e `BLIND_INDEX_KEY`, scrive `.env` (permessi 640 root:www-data);
4. crea `storage/`, `bootstrap/cache` con permessi corretti;
5. esegue `php bin/console migrate`;
6. installa `mcpanel-worker.service` e `mcpanel-scheduler.timer`;
7. installa il vhost Nginx (`/etc/nginx/sites-available/mcpanel.conf`), apre la
   porta su `ufw` se attivo e, se richiesto, ottiene il certificato Let's Encrypt.

Al termine aprire l'indirizzo mostrato dallo script seguito da `/install`
(es. `https://panel.example.com/install` o `http://203.0.113.10/install`).

### Cambiare indirizzo dopo l'installazione

Per passare da IP a dominio con HTTPS (o il contrario, o cambiare porta) senza
reinstallare:

```bash
sudo DOMAIN=panel.example.com LETSENCRYPT_EMAIL=ops@example.com \
     bash /var/www/mcpanel/deploy/set-address.sh
```

Accetta le stesse variabili dell'installer (anche in modalità guidata), salva
una copia di `.env`, aggiorna `APP_URL`, `SESSION_SECURE` e `TRUST_PROXY`,
rigenera il vhost e ricarica i servizi. Dopo il cambio: aggiornare l'URL dei
webhook in Stripe/PayPal e riattivare la licenza se il server licenze segnala
`domain_mismatch` (la licenza è legata all'host). Lo stato attuale è visibile
nel pannello admin, **Diagnostica → Indirizzo e HTTPS**.

### Installazione manuale (senza script)

```bash
sudo apt install nginx mariadb-server php8.5-{cli,fpm,mysql,mbstring,xml,curl,zip,intl,gd}
sudo mysql -e "CREATE DATABASE mchosting CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER 'mchosting'@'127.0.0.1' IDENTIFIED BY 'PASSWORD';
  GRANT ALL ON mchosting.* TO 'mchosting'@'127.0.0.1';"
cd /var/www/mcpanel
cp .env.example .env
php bin/console key:generate
php bin/console key:generate --blind
# compilare DB_* e APP_URL in .env (http://IP o https://dominio, vedi sopra)
php bin/console migrate
# copiare deploy/nginx e deploy/systemd come nello script
```

## 3. Procedura guidata `/install`

Crea il super amministratore, il catalogo demo, i documenti legali segnaposto, la
knowledge base e le feature flag. È accessibile **solo** se non esistono utenti o
se manca `APP_KEY`. Al termine, cancellare o proteggere l'URL `/install` a livello
Nginx se si vuole essere certi che non venga rieseguita.

## 4. Configurazione Pterodactyl

Nel pannello Pterodactyl creare due chiavi API (Admin → Application API):

| Chiave | Permessi minimi |
|---|---|
| Application (`ptla_…`) | `users.read`, `users.write`, `nodes.read`, `allocations.read`, `allocations.write`, `servers.read`, `servers.write`, `nests.read`, `eggs.read` |
| Client (`ptlc_…`) | `websocket.connect`, `control.console`, `control.start`, `control.stop`, `control.restart`, `file.read` |

Nel `.env`:

```
PTERODACTYL_URL=https://ptero.example.com
PTERODACTYL_APP_KEY=ptla_...
PTERODACTYL_CLIENT_KEY=ptlc_...
PTERODACTYL_VERSION=            # vuoto = rilevamento automatico 1.11/1.12
```

Poi in **Admin → Prodotti** associare a ogni piano `nest_id`, `egg_id`, docker
image e le variabili (`SERVER_JARFILE`, `MC_VERSION`, …). In **Admin →
Diagnostica** verificare che tutti gli endpoint rispondano 200 e che i nodi
mappati abbiano allocazioni libere. Dettagli: [07-pterodactyl.md](07-pterodactyl.md).

## 5. Pagamenti

### Stripe

1. Dashboard Stripe → Developers → API keys → copiare la secret key.
2. Creare un webhook verso `https://panel.example.com/webhooks/stripe` con gli
   eventi `checkout.session.completed` e
   `checkout.session.async_payment_succeeded`.
3. Copiare il signing secret (`whsec_…`).

```
STRIPE_SECRET=sk_live_...
STRIPE_WEBHOOK_SECRET=whsec_...
STRIPE_MODE=live
```

### PayPal

Creare una app REST in developer.paypal.com, abilitare il webhook
`https://panel.example.com/webhooks/paypal` per `PAYMENT.CAPTURE.COMPLETED` e
`CHECKOUT.ORDER.APPROVED`.

```
PAYPAL_CLIENT_ID=...
PAYPAL_CLIENT_SECRET=...
PAYPAL_MODE=live
```

### Bonifico

```
BANK_IBAN=IT...
BANK_HOLDER="Ragione Sociale Srl"
BANK_BIC=...
```

Il bonifico resta `pending` finché un admin non lo conferma da **Admin → Ordini**.

## 6. Email

```
MAIL_DRIVER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@example.com
MAIL_FROM_NAME="Blockwave Hosting"
```

`MAIL_DRIVER=log` scrive in `storage/logs/mail-*.log` (solo sviluppo). Le email
sono accodate: senza `mcpanel-worker` non partono.

## 7. Licenza

Senza una licenza valida il pannello **blocca le nuove vendite** (carrello e
checkout) e le **personalizzazioni** (Aspetto, Prodotti, Add-on). I servizi già
attivi, i rinnovi e i ticket continuano a funzionare.

Server licenze sulla stessa macchina, nel caso più semplice:

```bash
cd /var/www/mcpanel/license-server && sudo bash deploy/install.sh   # scegli "local"
cd /var/www/license-server && sudo -u www-data php bin/issue.php standard 0 1
cd /var/www/mcpanel && sudo bash deploy/set-license.sh                # incolla la chiave MCH-...
```

Guida completa, compreso il server licenze su un'altra macchina:
[12-license-server.md](12-license-server.md).

## 8. Impostazioni post-installazione

In **Admin → Impostazioni**:

- dati aziendali, P.IVA, regime fiscale e aliquote;
- brand (nome, colore, footer email);
- giorni di preavviso rinnovo, grazia di pagamento, giorni prima della terminazione;
- soglia antifrode per la revisione manuale;
- 2FA obbligatoria per admin (`enforce_admin_2fa`);
- modalità manutenzione;
- `enforce_license` solo quando la licenza è attiva.

Cambiare `ADMIN_PATH` in un valore non indovinabile e aggiungere, se possibile,
`ADMIN_IP_ALLOWLIST` con gli IP dell'ufficio.

## 9. Verifica

```bash
curl -fsS https://panel.example.com/api/v1/health
systemctl status mcpanel-worker
systemctl list-timers mcpanel-scheduler.timer
tail -f /var/www/mcpanel/storage/logs/app-$(date +%F).log
```

Checklist minima prima di aprire le vendite:

- [ ] `/install` completato e admin creato
- [ ] diagnostica Pterodactyl verde, nodo con allocazioni libere
- [ ] un ordine di test pagato con Stripe e uno con bonifico
- [ ] server creato in Pterodactyl e visibile in console
- [ ] email di conferma ricevute
- [ ] fattura PDF scaricabile
- [ ] cron/timer attivo (fatture di rinnovo)
- [ ] backup notturno configurato e restore provato
- [ ] HTTPS forzato (non in modalità IP), HSTS aggiunto dopo aver verificato tutti i sottodomini

## 10. Aggiornamento

```bash
sudo bash deploy/update.sh
```

Lo script attiva la manutenzione, salva database e codice in
`/var/backups/mcpanel`, esegue `git pull` (se il deploy è un clone), migra,
pulisce la cache, riavvia worker e PHP-FPM, verifica l'healthcheck e disattiva la
manutenzione. In caso di fallimento stampa i comandi di rollback.

## 11. Disinstallazione

Fermare e disabilitare `mcpanel-worker` e `mcpanel-scheduler.timer`, rimuovere il
vhost Nginx, quindi eliminare database e directory. Le fatture devono essere
conservate secondo gli obblighi fiscali: esportarle prima.
