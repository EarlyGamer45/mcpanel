# Test e verifica

## Test automatici

```bash
tools/php/php.exe tests/run.php
```

Esegue 21 test senza dipendenze, su SQLite in memoria (più un mock HTTP locale
per Pterodactyl):

| Area | Cosa verifica |
|---|---|
| Money | formattazione, arrotondamento half-up, IVA da lordo, prorata |
| Vat | domestico, reverse charge, OSS, export |
| PriceCalculator | risorse, add-on, cicli, clamp, scala del prezzo annuale |
| Coupons | sconto percentuale e fisso, tipo sconosciuto |
| State machine | transizioni valide e rifiuto di quelle illegali (ordine e servizio) |
| TOTP | generazione, verifica, URI otpauth, codice errato |
| Hasher | Argon2id verifica corretta e rifiuto password errata |
| PDF | header `%PDF-1.4`, trailer `%%EOF`, font Helvetica |
| Stripe | webhook firmato accettato, firma falsa rifiutata |
| PayPal | conversione centesimi ↔ stringa decimale |
| Schema | 40+ tabelle create su SQLite, install idempotente |
| Settings | tipi scalari preservati nel JSON |
| Provisioning (mock Pterodactyl) | utente e server creati, limiti e variabili corretti, retry che aggiorna senza duplicare, checkInstall, reconcile, suspend, delete |

I test usano un database `:memory:` e non toccano i dati demo.

### Mock Pterodactyl

`tests/fixtures/pterodactyl-router.php` è un server HTTP che implementa gli
endpoint Application API usati dalla piattaforma con le stesse forme JSON del
Panel 1.11/1.12. Il test lo avvia con `proc_open` su `127.0.0.1:8093`, esegue
l'intero ciclo di provisioning e spegne il processo alla fine. Richiede solo
PHP CLI e una porta locale libera.

Il mock ha anche `/__state` e `/__reset` per ispezionare contatori e payload
inviato: è il modello da copiare per testare altri panel o gateway.

## Lint

```bash
find src resources lang database public -name '*.php' -print0 \
  | xargs -0 -n1 tools/php/php.exe -l
tools/php/php.exe -l bin/console
tools/php/php.exe -l bin/worker
```

Il progetto non usa Composer, quindi non ci sono PHPStan/PHPUnit. La suite è
volutamente minimale: per aggiungere un test basta una funzione `test('nome',
function () { ... })` in `tests/run.php`.

## Installer: dominio o IP, HTTP o HTTPS

```bash
bash tests/deploy-address.sh                          # parte pura, ovunque
sudo DEPLOY_TEST_NGINX=1 bash tests/deploy-address.sh # + vhost reali con nginx -t
```

Copre la validazione di dominio, IPv4/IPv6 e porta, `APP_URL`, cookie e
`TRUST_PROXY` per ogni combinazione, la scrittura di `.env`, l'URL di
healthcheck di `update.sh` e (con `DEPLOY_TEST_NGINX=1`, solo su una VM di
prova: scrive in `/etc/nginx`) i vhost generati, compresa la sostituzione del
vhost creato dal vecchio installer.

## Traduzioni

```bash
tools/php/php.exe tests/lang-check.php
```

Confronta le chiavi usate nei template/controller con `lang/it.php` e
`lang/en.php` e segnala duplicati. Le chiavi dinamiche (`tier_`, `dept_`, `tx_`)
sono attese come mancanti perché composte a runtime.

## Verifica manuale del flusso (demo locale)

```bash
tools/php/php.exe bin/console migrate
tools/php/php.exe bin/console db:seed
tools/php/php.exe -S 127.0.0.1:8080 -t public
```

Poi, con due sessioni (cliente demo e admin):

1. login cliente → catalogo → configura → carrello → checkout;
2. pagamento con bonifico → istruzioni con IBAN e causale;
3. login admin → ordini → conferma pagamento;
4. il servizio passa a `pending`/`queued`; con Pterodactyl configurato diventa
   `active` e arriva l'email (con `MAIL_DRIVER=log` finisce in
   `storage/logs/mail-*.log`);
5. fattura PDF scaricabile dal cliente;
6. wallet: ricarica, conferma admin, saldo aggiornato;
7. ticket: apertura, risposta, chiusura;
8. 2FA: attivazione, login con codice, disattivazione.

## Cosa non è coperto

- Nessun test end-to-end automatico (Playwright/Selenium): il flusso HTTP è
  stato verificato manualmente con curl e, in locale, nel browser.
- Nessun test di carico: per un go-live usare k6/ab su catalogo e checkout.
- Le chiamate reali a Pterodactyl/Stripe/PayPal non sono testate in CI: servono
  credenziali di staging e webhook firmati.
- Le email SMTP reali non sono testate: il driver `log` verifica solo la
  generazione del messaggio.
- SQLite non riproduce tutte le differenze di MariaDB (collation, lock, tipi):
  eseguire una smoke test su MariaDB prima del deploy.

## Smoke test staging (MariaDB + Pterodactyl)

```bash
php bin/console migrate
php bin/console user:create admin admin@example.com 'PasswordLunga!'
php -S 127.0.0.1:8080 -t public
curl -fsS http://127.0.0.1:8080/api/v1/health | jq
```

Poi creare un ordine reale con bonifico, confermarlo, verificare il server nel
pannello Pterodactyl e la mail. Eseguire `php bin/console reconcile` e controllare
che non segnali drift.

## Convenzioni per nuovi test

- Un comportamento per test, nome in inglese, messaggio di fallimento esplicito.
- Niente rete: mockare i gateway o testare solo le funzioni pure.
- Se serve il DB, usare `new Database(['driver' => 'sqlite', 'sqlite_path' => ':memory:'])`
  e `Schema::install()`.
- Non inserire segreti nei test.
