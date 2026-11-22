# Sviluppare estensioni

La piattaforma non carica plugin arbitrari a runtime: un `eval` o un include da
una cartella scrivibile sarebbe un rischio inaccettabile su un pannello che
gestisce pagamenti. Le estensioni sono **moduli PHP versionati** che si integrano
nei punti di estensione qui sotto e vengono rilasciati con il codice.

## Punti di estensione stabili

| Punto | File | Come estendere |
|---|---|---|
| Gateway di pagamento | `src/Domain/Payments.php` | implementare `PaymentGateway`, registrare in `GatewayRegistry::all()` |
| Eventi di dominio | `src/Core/Queue.php` (`Events`) | `Events::listen('order.paid', fn)` in `Listeners::register()` |
| Job in coda | `src/Core/Queue.php` | classe con `handle(array $payload)` + `Queue::push()` |
| Comandi console | `src/Console/Kernel.php` | nuovo `case` in `run()` |
| Migrazioni | `database/migrations/` | file che riceve `Database` e applica DDL idempotente |
| Viste e asset | `resources/views`, `public/assets` | override nel rilascio o branding da impostazioni |
| Email | `src/Domain/Listeners.php` | nuovi listener che accodano `SendMailJob` |

Non modificare `src/Core/Schema.php` per un'estensione: aggiungere una migrazione.
Non aggiungere segreti in tabella: usare `.env` e le impostazioni solo per valori
non sensibili.

## Gateway di pagamento

```php
final class MyGateway implements \App\Domain\PaymentGateway
{
    public function __construct(private readonly array $config) {}

    public function code(): string { return 'mygateway'; }
    public function label(): string { return 'My Gateway'; }
    public function description(): string { return __('gateway_my_desc'); }
    public function isConfigured(): bool { return ($this->config['key'] ?? '') !== ''; }
    public function supportsRecurring(): bool { return false; }

    public function start(array $order, array $invoice, string $returnUrl): array
    {
        // chiamare l'API ufficiale, mai inventare endpoint
        return ['type' => 'redirect', 'url' => $redirect];
    }

    public function handleWebhook(\App\Core\Request $request): ?array
    {
        // verificare la firma PRIMA di leggere il payload
        return [
            'event_id' => $eventId,
            'type' => $eventType,
            'status' => 'succeeded',
            'order_id' => $orderId,
            'amount_cents' => $amount,
            'payment_id' => $paymentId,
        ];
    }

    public function refund(array $payment, int $amountCents): void
    {
        // chiamata di rimborso, throw ApiException in caso di errore
    }
}
```

Poi in `GatewayRegistry::all()` aggiungere l'istanza con la sua configurazione,
in `config/config.php` leggere le chiavi da `Env`, in `.env.example` documentarle,
e in `Installer::flags()` aggiungere la feature flag. Il gateway compare da solo
nel checkout se `isConfigured()` è vero.

Regole: importi in centesimi, `order_id` sempre verificato contro l'importo,
webhook idempotenti (il core lo fa con `webhook_events`), nessun dato carta sul
server.

## Eventi

```php
Events::listen('order.paid', static function (array $payload): void {
    // $payload['order_id']
});
```

Eventi emessi oggi: `order.created`, `order.paid`, `service.created`,
`service.installing`, `service.active`, `service.suspended`,
`service.terminated`, `service.failed`, `ticket.opened`, `ticket.replied`,
`job.failed`.

I listener girano nella richiesta o nel worker: non bloccare con chiamate lente,
accodare un job. Un'eccezione in un listener viene loggata e non annulla l'ordine.

## Job

```php
final class SyncDiscordRole implements \App\Core\Job
{
    public function handle(array $payload): void
    {
        // $payload['user_id']
    }
}

Queue::push(SyncDiscordRole::class, ['user_id' => $id], 0, 'default');
```

Il worker `bin/worker` processa `default` e `provisioning`. I job hanno
`max_attempts`, backoff e `failed_at`; gli errori finiscono in `jobs.last_error`
e nell'evento `job.failed`.

## Migrazioni

```php
// database/migrations/0002_add_discord_id.php
return static function (\App\Core\Database $db): void {
    $db->execute('ALTER TABLE users ADD COLUMN discord_id VARCHAR(32) NULL');
};
```

Il runner registra il file in `migrations` e non lo riesegue. Deve essere
idempotente o protetto da un controllo (`Schema::hasColumn` non esiste: usare
`PRAGMA table_info` su SQLite e `information_schema` su MariaDB, oppure
`ALTER TABLE ... ADD COLUMN IF NOT EXISTS` su MariaDB 11.8).

## Comandi console

Aggiungere un `case` in `Kernel::run()` e il comando in `help()`. Usare
`self::line()` per l'output, restituire `0`/`1`. I comandi lunghi vanno in un job,
non in un comando che blocca lo scheduler.

## Viste e branding

Il branding (nome, colore, footer email) è in **Admin → Impostazioni** e non
richiede codice. Per modifiche strutturali copiare la vista in
`resources/views/` e modificarla nel rilascio: non esistono override a caldo.
Il CSS è in `public/assets/app.css`, senza build step.

## Test di un'estensione

- Unit: funzioni pure con `tests/run.php`.
- Integrazione: usare il mock Pterodactyl in `tests/fixtures/` come modello per
  un server HTTP finto; non chiamare API reali nei test.
- Gateway: firmare un payload di test e verificare che `handleWebhook` accetti
  solo la firma corretta (vedi il test Stripe).
- Sempre `php tests/run.php` e `php tests/lang-check.php` prima del rilascio.

## Rilascio

1. versione in `config/config.php` (`app.version`);
2. migrazione nuova, mai modificare una già applicata;
3. documentazione in `docs/`;
4. test verdi e lint;
5. backup + `deploy/update.sh` in staging, poi produzione.

Le estensioni che richiedono modifiche al core vanno discusse: il core resta
piccolo e stabile, le integrazioni vivono nei punti di estensione.
