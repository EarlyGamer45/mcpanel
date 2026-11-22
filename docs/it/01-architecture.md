# Architettura

## Obiettivo

Un pannello di hosting Minecraft che vende, incassa, eroga e rinnova server
senza intervento manuale, usando Pterodactyl solo come esecutore. La piattaforma
possiede il catalogo, i prezzi, i clienti, le fatture e lo stato del servizio;
Pterodactyl possiede i container, le risorse e le console.

## Perché PHP puro e senza Composer

Il piano ammetteva Laravel o framework equivalente. Qui la scelta è un MVC
minimale con autoloader a classmap perché:

- un solo runtime da installare su Ubuntu 26.04, nessuna supply chain npm/Composer;
- deploy = copia dei file + `php bin/console migrate`, nessun build step;
- PHP 8.5 è verificabile subito su ogni release LTS;
- il codice resta leggibile e le dipendenze critiche (crypto, PDF, HTTP) sono
  poche centinaia di righe scritte e testate nel progetto.

Il compromesso è la perdita dell'ecosistema Laravel (ORM, queue, validazione).
È compensato da `Database` con query preparate, `Schema` come unica fonte DDL,
`Queue` su tabella `jobs` e validazione esplicita nei controller.

## Struttura dei layer

```
public/index.php
  └─ src/Http/Kernel.php
       ├─ App::boot()        .env → Config → Logger → Translator → listeners
       ├─ Request::capture()
       ├─ middleware globali  session, CSRF, manutenzione, sicurezza
       ├─ Router              src/Http/routes.php
       │    └─ middleware di rotta  auth | guest | admin
       └─ Controller          src/Http/*
            ├─ Domain/*        logica di business e transazioni
            └─ View::render()  resources/views + lang/*
```

- **Core**: infrastruttura senza logica di prodotto (`App`, `Database`,
  `Schema`, `Http`, `Money`, `Crypto`, `Auth`, `Queue`, `Pdf`, `Mailer`).
- **Domain**: regole di prodotto. Ogni file raggruppa classi correlate
  (es. `Orders.php` contiene `OrderStateMachine`, `OrderService`, `RiskEngine`).
- **Http**: controller sottili; nessuna query SQL nei template.

## Ciclo di vita di una richiesta

1. `Kernel::handle()` avvia la sessione (`HttpOnly`, `SameSite=Lax`, `Secure` in
   produzione), applica gli header di sicurezza e verifica la manutenzione.
2. Le richieste mutative richiedono `_token` CSRF, salvo rotte `->stateless()`
   (webhook, API pubbliche, preventivo).
3. Il router risolve la rotta, esegue i middleware (`auth`, `guest`, `admin`) e
   chiama il controller.
4. Il controller usa i servizi di dominio; le transazioni sono gestite da
   `Database::transaction()`.
5. La risposta è HTML (view) o JSON; gli errori diventano `errors/error` con lo
   status corretto e vengono loggati in `storage/logs/app-YYYY-MM-DD.log`.

## Denaro

- Tutti gli importi sono **interi in centesimi**. Nessun float.
- I prezzi di listino sono **lordi IVA inclusa** per i consumatori: l'IVA è
  estratta con `Money::vatFromGross()` e la fattura mostra imponibile, imposta e
  totale.
- Arrotondamento half-up intero (`Money::divRound`), mai `round()` su float.
- La valuta è per installazione (`APP_CURRENCY`); il formato è centralizzato in
  `Money::format()`.
- Il prorata usa giorni interi (`Money::prorata`) con clamp al periodo.

## Macchine a stati

Esplicite e testate, con transizioni illegali che lanciano eccezione:

```
Ordine:   pending_payment → review → paid → provisioning → active
                        ↘ cancelled        ↘ refunded
Servizio: pending → installing → active ⇄ suspended → terminated
                                  ↘ failed → installing
```

Ogni cambio scrive nell'`audit_log` e, dove serve, emette eventi di dominio
(`Events`) che accodano email e job.

## Provisioning

`ProvisionServerJob` è idempotente grazie a `services.provision_key`:

1. crea/recupera l'utente Pterodactyl (email con blind index, password casuale);
2. seleziona il nodo (`NodeSelector`: least allocated, round robin o location);
3. crea il server con egg, docker image, variabili e allocation;
4. salva `pterodactyl_server_id`, IP, porta e stato `active`;
5. in caso di errore registra `last_error`, incrementa i tentativi e riprogramma
   con backoff; dopo N tentativi il servizio va in `failed` e l'admin vede
   l'avviso in dashboard.

`ProvisioningService::reconcile()` confronta i servizi con il pannello e segnala
drift (server spariti o risorse diverse). Non cancella mai dati: solo report.

## Coda e scheduler

- `jobs` è la coda (default + provisioning), con `reserved_at`, tentativi,
  backoff e `failed_at`. `bin/worker` è il processo systemd.
- `mcpanel-scheduler.timer` ogni 15 minuti esegue: controllo licenza, fatture di
  rinnovo, sospensioni, terminazioni, recupero carrelli, chiusura ticket, prune.
- Il worker e lo scheduler sono separati da PHP-FPM: un job lento non blocca il
  sito.

## Pagamenti

`PaymentGateway` è l'interfaccia; `GatewayRegistry` istanzia i driver abilitati
per feature flag e configurati. Stripe e PayPal usano le API ufficiali; il
bonifico è manuale con conferma admin; il wallet scala il saldo in transazione.

I webhook passano da `PaymentService::processWebhook()`: firma verificata nel
driver, evento registrato una sola volta in `webhook_events`, ordine marcato
pagato una sola volta, provisioning accodato. Un webhook duplicato risponde 200
senza effetti.

## Licenza

Il pannello chiama un server separato (`license-server/`) e verifica la firma
Ed25519 della risposta con la chiave pubblica nel `.env`. Se il server non
risponde, il pannello entra in grace period e continua a servire i clienti
esistenti; se `enforce_license` è attivo e la licenza è invalida, blocca solo le
nuove vendite.

## Limiti noti

- Nessun supporto multi-tenant: una installazione = un brand.
- Nessuna app mobile o API client privata: solo endpoint pubblici e webhook.
- Le email sono accodate ma l'invio dipende dal driver SMTP configurato.
- Il reconciler segnala, non ripara automaticamente, i drift Pterodactyl.
