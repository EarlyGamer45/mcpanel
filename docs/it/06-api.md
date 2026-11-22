# API

L'API pubblica è in sola lettura e non richiede autenticazione. Non esiste una
API client privata: le azioni dei clienti passano dal sito con sessione e CSRF.

Specifica OpenAPI: [openapi.yaml](openapi.yaml).

## Endpoint

### `GET /api/v1/health`

Healthcheck per monitoraggio (UptimeRobot, Prometheus blackbox, ecc.).
Risponde `200` se il database è raggiungibile, `503` altrimenti.

```json
{
  "status": "ok",
  "time": "2026-11-16T20:40:00+00:00",
  "checks": {
    "database": true,
    "queue_pending": 0,
    "queue_failed": 0,
    "license": "active",
    "pterodactyl_configured": true,
    "version": "0.1.0"
  }
}
```

Non espone segreti né dettagli degli errori.

### `GET /api/v1/catalog`

Catalogo pubblico, cache 60 secondi.

```json
{
  "currency": "EUR",
  "products": [
    {
      "id": 1,
      "name": "Stone",
      "slug": "stone",
      "category": "Minecraft",
      "tier": "budget",
      "description": "…",
      "prices_cents": {"monthly": 1599, "annual": 15990},
      "min_ram_mb": 1024,
      "max_ram_mb": 4096
    }
  ]
}
```

Gli importi sono **sempre in centesimi**.

### `POST /configurator/quote`

Anteprima prezzo usata dal configuratore (stateless, nessun dato personale).
Accetta `product_id`, `ram_mb`, `cpu_percent`, `disk_mb`, `cycle`, `addons[]`,
`coupon` e restituisce totale, sconti e righe.

```json
{
  "total": "16,50 €",
  "total_cents": 1650,
  "base": "10,00 €",
  "discount": "0,00 €",
  "lines": [{"label": "RAM 2 GB", "amount": "2,00 €"}],
  "cycle_label": "mensile"
}
```

## Webhook

### `POST /webhooks/stripe`

Verifica `Stripe-Signature` (HMAC-SHA256, tolleranza 300 s) con
`STRIPE_WEBHOOK_SECRET`. Eventi gestiti: `checkout.session.completed`,
`checkout.session.async_payment_succeeded` con `payment_status=paid`.
Idempotenza tramite `webhook_events(gateway, external_id)`.

### `POST /webhooks/paypal`

Verifica la firma PayPal (headers `PAYPAL-TRANSMISSION-*`) tramite l'endpoint
ufficiale di verifica. Eventi: `PAYMENT.CAPTURE.COMPLETED`,
`CHECKOUT.ORDER.APPROVED`. Risponde 200 anche per eventi ignorati, così PayPal
non ritenta all'infinito.

In caso di firma non valida: `400` e nessun effetto sull'ordine.

## Licenza (server separato)

Sezione diversa, documentata in [12-license-server.md](12-license-server.md):
`POST /api/v1/activate`, `POST /api/v1/check`, `GET /api/v1/health`.

## Convenzioni

- Content-Type JSON, UTF-8.
- Nessun endpoint accetta importi inviati dal client: i prezzi sono sempre
  ricalcolati dal server.
- Gli errori hanno forma `{"error": "codice"}` senza stack trace.
- Rate limiting: login e reset hanno lockout; i webhook sono protetti dalla
  firma; l'API pubblica è cacheata e può essere messa dietro rate limit Nginx.
