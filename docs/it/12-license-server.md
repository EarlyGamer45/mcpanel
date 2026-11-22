# Guida: installare il server delle licenze

Il server delle licenze è un'applicazione separata che si trova nella cartella
`license-server/` del progetto. Emette le chiavi (`MCH-XXXX-XXXX-XXXX-XXXX`) e
risponde al pannello quando chiede se una licenza è valida.

Ogni risposta è firmata con una chiave privata Ed25519, che resta sul server
delle licenze. Il pannello conosce solo la chiave **pubblica** e con quella
controlla che la risposta sia autentica.

## Cosa succede senza licenza

| Funzione | Senza licenza valida |
|---|---|
| Nuovi ordini, carrello, checkout | **Bloccati** (i clienti vedono "ordini temporaneamente sospesi") |
| Admin → Aspetto, Prodotti, Add-on | **Bloccati** (pagine in sola lettura) |
| Grafica personalizzata del sito | **Non applicata**: il sito usa l'aspetto predefinito (le impostazioni salvate restano e tornano attive con la licenza) |
| Servizi già attivi, rinnovi, fatture, ticket, area clienti | Continuano a funzionare |
| Impostazioni → Licenza, Diagnostica | Sempre disponibili |

Gli stati della licenza sono questi:

- **Attiva:** tutto sbloccato.
- **In tolleranza:** il server delle licenze non risponde (rete giù, server
  spento). Tutto resta sbloccato per `LICENSE_GRACE_DAYS` giorni (default 7)
  dall'ultima verifica riuscita.
- **Non valida o scaduta:** la chiave è revocata, sospesa, scaduta,
  inesistente o in uso su un'altra installazione. Il blocco è immediato,
  senza tolleranza.

Il pannello verifica la licenza da solo ogni 15 minuti tramite lo scheduler. Puoi anche verificarla a mano
da **Admin → Impostazioni → Licenza → Verifica ora**.

---

## Scegli dove installarlo

| Modalità | Quando usarla | Indirizzo da dare al pannello |
|---|---|---|
| **local** | Server licenze sulla **stessa macchina** del pannello. Ascolta solo su `127.0.0.1`, non è raggiungibile da fuori. | `http://127.0.0.1:8090` |
| **domain** | Server **separato** con un dominio (es. `license.tuodominio.it`) e HTTPS gratuito Let's Encrypt. Consigliata se gestisci più pannelli o vendi licenze. | `https://license.tuodominio.it` |
| **ip** | Server **separato** senza dominio, raggiungibile come `http://IP:porta`, con accesso limitato agli IP dei pannelli. | `http://IP_SERVER:8090` |

Requisiti: Ubuntu Server 26.04 LTS. L'installer installa da solo Nginx, PHP 8.5
FPM e SQLite. Non serve MariaDB.

---

## A) Stessa macchina del pannello (local)

Sul server dove è già installato il pannello:

```bash
cd /var/www/mcpanel/license-server
sudo bash deploy/install.sh
```

Alla domanda scegli **1) local** e lascia la porta `8090` (o un'altra porta
libera). Lo script:

1. copia il server licenze in `/var/www/license-server`;
2. genera le chiavi di firma in `/var/www/license-server/keys/`;
3. crea il sito Nginx `mch-license` in ascolto **solo** su `127.0.0.1:8090`;
4. verifica che risponda e stampa `LICENSE_SERVER` e `LICENSE_PUBLIC_KEY`.

Crea la prima licenza (sempre come utente `www-data`):

```bash
cd /var/www/license-server
sudo -u www-data php bin/issue.php standard 0 1
# stampa ad esempio: MCH-83RS-RBGW-QZAQ-9PML
```

`standard 0 1` significa piano "standard", `0` giorni (nessuna scadenza) e 1
installazione. Per una licenza annuale usa `standard 365 1`.

Collega il pannello e attiva la chiave:

```bash
cd /var/www/mcpanel
sudo bash deploy/set-license.sh
```

Lo script trova da solo indirizzo e chiave pubblica del server licenze locale
(premi Invio per confermarli) e chiede la chiave da attivare. Alla fine mostra:

```
status:        active
sales:         open
customisation: allowed
```

## B) Server separato con dominio e HTTPS (domain)

**Sul server delle licenze:**

1. Crea un record DNS `A` (es. `license.tuodominio.it`) che punta all'IP del
   server, e apri le porte 80 e 443.
2. Copia la cartella `license-server/` sul server, per esempio così:

   ```bash
   scp -r license-server root@IP_SERVER_LICENZE:/root/
   ```

3. Installa:

   ```bash
   cd /root/license-server
   sudo bash deploy/install.sh
   # scegli 2) domain, inserisci il dominio e un'email per Let's Encrypt
   ```

4. Crea una licenza:

   ```bash
   cd /var/www/license-server
   sudo -u www-data php bin/issue.php standard 365 1
   ```

Copia le due righe stampate alla fine dell'installazione
(`LICENSE_SERVER=https://…` e `LICENSE_PUBLIC_KEY=…`).

**Sul server del pannello:**

```bash
cd /var/www/mcpanel
sudo bash deploy/set-license.sh
# incolla l'URL https://license.tuodominio.it, la chiave pubblica e la licenza
```

## C) Server separato solo con IP (ip)

Come il caso B, ma alla domanda scegli **3) ip**. Inserisci la porta (es.
`8090`) e gli IP pubblici dei pannelli che possono collegarsi, separati da
spazi. Tutti gli altri ricevono `403`. Se UFW è attivo, la porta viene aperta
solo per quegli IP.

Sul pannello usa `http://IP_SERVER_LICENZE:8090`.

> Le risposte sono firmate, quindi nessuno può falsificare una licenza valida.
> La chiave di licenza però viaggia in chiaro: su internet è meglio la
> modalità **domain** con HTTPS.

## Installazione senza domande

```bash
sudo LICENSE_MODE=local LICENSE_PORT=8090 bash deploy/install.sh
sudo LICENSE_MODE=domain LICENSE_DOMAIN=license.tuodominio.it LICENSE_EMAIL=tu@tuodominio.it bash deploy/install.sh
sudo LICENSE_MODE=ip LICENSE_PORT=8090 ALLOW_IPS="203.0.113.10 203.0.113.11" bash deploy/install.sh

# pannello
sudo LICENSE_SERVER=http://127.0.0.1:8090 LICENSE_KEY=MCH-XXXX-XXXX-XXXX-XXXX bash deploy/set-license.sh
```

## Attivare la licenza dal pannello web

Puoi saltare il passaggio della chiave in `set-license.sh` e attivarla dopo da
**Admin → Impostazioni → Licenza**: inserisci chiave e indirizzo del server e
premi **Attiva licenza**. La chiave pubblica invece va comunque nel `.env`,
tramite `set-license.sh` o a mano:

```
LICENSE_SERVER=http://127.0.0.1:8090
LICENSE_PUBLIC_KEY=<44 caratteri base64 stampati dall'installer>
LICENSE_GRACE_DAYS=7
```

Dopo una modifica manuale del `.env`: `sudo systemctl reload php8.5-fpm`.

---

## Gestire le licenze

Lancia sempre i comandi da `/var/www/license-server` come `www-data`:

```bash
sudo -u www-data php bin/issue.php standard 365 1                     # 1 anno, 1 installazione
sudo -u www-data php bin/issue.php premium 0 3 - "Cliente Rossi"       # senza scadenza, 3 installazioni
sudo -u www-data php bin/issue.php standard 365 1 panel.cliente.it     # valida solo per quel dominio/IP
sudo -u www-data php bin/list.php                                      # elenco licenze
sudo -u www-data php bin/list.php MCH-XXXX-XXXX-XXXX-XXXX              # dettaglio e installazioni
sudo -u www-data php bin/revoke.php MCH-XXXX-XXXX-XXXX-XXXX revoked    # revoca (blocco immediato)
sudo -u www-data php bin/revoke.php MCH-XXXX-XXXX-XXXX-XXXX suspended  # sospensione
sudo -u www-data php bin/revoke.php MCH-XXXX-XXXX-XXXX-XXXX active     # riattiva
```

Dal pannello:

```bash
sudo -u www-data php /var/www/mcpanel/bin/console license:status    # stato e cosa è bloccato
sudo -u www-data php /var/www/mcpanel/bin/console license:check     # verifica subito
sudo -u www-data php /var/www/mcpanel/bin/console license:activate MCH-XXXX-XXXX-XXXX-XXXX http://127.0.0.1:8090
```

**Spostare il pannello su un'altra macchina:** la licenza è legata
all'impronta dell'installazione (visibile in Impostazioni → Licenza). Se il
pannello cambia server, o se superi il numero di installazioni, ricevi
`activation_limit_reached`. In quel caso emetti una nuova licenza, oppure
crea la licenza con più installazioni.

## Backup (importante)

- `/var/www/license-server/keys/private.key`: senza questo file nessuna licenza
  può più essere verificata. Copialo offline, in un posto sicuro. **Non
  distribuirlo mai** e non metterlo nell'archivio del pannello.
- `/var/www/license-server/storage/licenses.sqlite`: l'elenco delle licenze
  emesse. Fai un backup regolare:
  `sqlite3 storage/licenses.sqlite ".backup /root/licenses-$(date +%F).sqlite"`

## Problemi frequenti

| Messaggio | Causa e soluzione |
|---|---|
| `LICENSE_PUBLIC_KEY mancante o non valida` | Esegui `sudo bash deploy/set-license.sh` sul pannello. |
| `Firma della licenza non valida` | La chiave pubblica nel `.env` del pannello non è quella del server licenze (per esempio dopo un `keygen --force`). Ricopiala. |
| `Connection failed … port 8090` | Il server licenze non risponde: `systemctl status nginx php8.5-fpm` e `curl http://127.0.0.1:8090/api/v1/health`. Nel frattempo il pannello resta "in tolleranza". |
| `nginx: bind() … Address already in use` durante l'installazione | Porta occupata: rilancia l'installer con un'altra porta (`LICENSE_PORT=8091`). |
| `invalid_license` | Chiave sbagliata o creata su un altro server licenze. |
| `domain_mismatch` | La licenza è stata emessa per un dominio/IP diverso da `APP_URL` del pannello. |
| `activation_limit_reached` | La licenza è già usata sul numero massimo di installazioni. |
| `rate_limited` | Troppe richieste dallo stesso IP: attendi un minuto. |
| `unable to open database file` lanciando `bin/issue.php` | L'hai eseguito come root: usa `sudo -u www-data php bin/…`. Se il file è già di root: `sudo chown -R www-data:www-data /var/www/license-server/storage`. |

## API (riferimento)

| Metodo | Percorso | Corpo | Risposta |
|---|---|---|---|
| GET | `/api/v1/health` | — | `{"ok":true,"time":"…"}` |
| POST | `/api/v1/activate` | `license`, `fingerprint`, `domain`, `version` | `{payload, signature}` |
| POST | `/api/v1/check` | `license`, `fingerprint`, `version` | `{payload, signature}` |

Errori: `404 invalid_license`, `403 license_expired|license_suspended|license_revoked`,
`409 fingerprint_mismatch|domain_mismatch|activation_limit_reached`,
`429 rate_limited`, `503 unavailable`.
