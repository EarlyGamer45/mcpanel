# Sicurezza

## Modello di minaccia

Avversari considerati: cliente malevolo, bot che prova credenziali, furto di
credenziali di un admin, webhook falsi, manomissione di un pagamento, accesso
fisico al server, dipendenza compromessa. Non è nel modello un attaccante con
root sull'host (a quel punto tutto è perso).

## OWASP Top 10 (2021) — stato

| Voce | Contromisure | Stato |
|---|---|---|
| A01 Broken access control | middleware `auth`/`admin` su ogni rotta, query sempre filtrate per `user_id`, ruoli verificati | OK |
| A02 Cryptographic failures | Argon2id, AES-256-GCM per dati fiscali, blind index HMAC, TLS obbligatorio | OK |
| A03 Injection | solo prepared statement, `e()` su ogni output, nessun `eval`, CSP restrittiva (inline solo per gli `onclick` esistenti) | OK |
| A04 Insecure design | state machine esplicite, idempotenza pagamenti/provisioning, importi solo server-side | OK |
| A05 Security misconfiguration | header di sicurezza, `APP_DEBUG=false`, errori generici, vhost che nega `.env`/`storage` | OK |
| A06 Vulnerable components | nessuna dipendenza PHP/Composer; unica libreria JS vendorizzata `qrcode-generator` (MIT); PHP e MariaDB da repo Ubuntu | OK |
| A07 Auth failures | lockout progressivo, 2FA TOTP, sessioni server-side, revoca, reset a token monouso | OK |
| A08 Integrity failures | webhook firmati, licenza Ed25519, deploy da git, checksum dei backup | OK |
| A09 Logging failures | audit log, log applicativi, eventi di sicurezza (login fallito, CSRF) | OK |
| A10 SSRF | le uniche chiamate esterne sono verso URL configurati (Pterodactyl, gateway, licenza) | OK |

## Segreti

- `.env` non è nel repository, permessi `640 root:www-data`.
- `APP_KEY` cifra i dati sensibili; `BLIND_INDEX_KEY` è separata: ruotarla
  richiede reindicizzazione, non farlo senza procedura.
- Chiavi Pterodactyl, Stripe, PayPal, SMTP: solo `.env`, mai log o database.
- La chiave privata della licenza vive solo sul server licenze, `0600`.
- Rotazione: Pterodactyl ogni 90 giorni, gateway ogni 180, licenza solo con
  rilascio che accetta la nuova chiave pubblica.

## Sessioni e autenticazione

- Cookie `HttpOnly`, `SameSite=Lax`, `Secure` in produzione, nome configurabile.
- Sessioni server-side in `user_sessions`: logout, cambio password e 2FA
  revocano le altre sessioni.
- Login: Argon2id (64 MiB, 4 iterazioni), messaggio generico, rate limit per
  IP+email, lockout temporaneo dopo 5 tentativi.
- 2FA TOTP con finestra ±1, codici di recupero monouso; obbligatoria per admin
  se `enforce_admin_2fa` è attiva.
- Reset password: token casuale 32 byte, salvato come hash, scadenza 1 ora,
  monouso, risposta identica per email esistenti e non.

## Autorizzazione

- `Auth::user()` letto dalla sessione, mai da parametri.
- Ogni query client filtra per `user_id`; gli ID di altri utenti restituiscono
  404, non 403 (nessuna enumerazione).
- Admin: ruolo in whitelist, path oscurabile, allowlist IP opzionale, 2FA.
- Le azioni admin sono nell'audit log con attore, IP e dettagli.

## Pagamenti

- Gli importi non arrivano mai dal client: il server ricalcola il carrello.
- Stripe: firma HMAC con tolleranza 300 s, `payment_status=paid`, importo
  confrontato con l'ordine.
- PayPal: verifica della firma via endpoint ufficiale, controllo importo/valuta.
- Idempotenza: `webhook_events` univoco, `OrderService::markPaid` controlla lo
  stato corrente.
- Nessun dato carta transita o viene salvato.

## Provisioning

- Le chiamate a Pterodactyl usano solo HTTPS e chiavi con permessi minimi.
- `external_id` idempotente evita server duplicati sui retry.
- Le password degli utenti Pterodactyl sono casuali e non vengono loggate.
- Gli errori salvano status e body ma non gli header di autorizzazione.

## Upload e input

- Allegati ticket: dimensione massima, MIME verificato, nome sanificato, file
  fuori dalla document root, download solo al proprietario o allo staff.
- Nessun upload di eseguibili o SVG non sanificati.
- Validazione lunghezze e tipi su ogni campo; `filter_var` per le email.

## Header e TLS

Inviati dall'applicazione e da Nginx:

```
Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline';
  img-src 'self' data:; script-src 'self' 'unsafe-inline'; connect-src 'self' wss:;
  object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: geolocation=(), microphone=(), camera=()
```

HSTS viene inviato solo quando la richiesta è davvero HTTPS; `X-Forwarded-Proto`
è considerato solo con `TRUST_PROXY=true` (load balancer fidato), così un client
non può simulare HTTPS su un'installazione in HTTP. Nginx può aggiungerlo solo
dopo aver verificato tutti i sottodomini.

Installazione per indirizzo IP (`ADDRESS_MODE=ip`): il sito è in HTTP, il cookie
di sessione usa `SESSION_SECURE=auto` (non Secure su HTTP, altrimenti il login
non funzionerebbe) e credenziali e dati personali viaggiano in chiaro. Non è
adatta alla vendita al pubblico: la diagnostica admin lo segnala. Con un
Pterodactyl in HTTP la CSP aggiunge `ws:` per la console. `connect-src wss:` serve alla console del server; `script-src`
include `'unsafe-inline'` per i gestori `onclick` esistenti.

## GDPR

- Base giuridica: contratto e obbligo legale per le fatture.
- Dati minimi: email, nome, indirizzo di fatturazione, log di accesso.
- Export JSON e cancellazione account dalla client area; le fatture sono
  conservate e oscurate per obbligo fiscale.
- Retention: log 90 giorni, fatture 10 anni, ticket 24 mesi, carrelli 30 giorni.
- Sub-processor: Pterodactyl host, gateway di pagamento, provider SMTP, server
  licenze. Elencarli nella privacy policy.

## Checklist prima del go-live

- [ ] `APP_DEBUG=false`, `APP_ENV=production`, `SESSION_SECURE=true`
- [ ] Dominio con HTTPS (non modalità IP); Diagnostica → Indirizzo e HTTPS senza avvisi
- [ ] `ADMIN_PATH` non indovinabile, allowlist IP se possibile
- [ ] 2FA admin attiva e testata
- [ ] HTTPS con redirect, HSTS dopo verifica
- [ ] `.env` 640, `storage/` 750, nessun file scrivibile da web
- [ ] `deploy/install.sh` ha negato l'accesso a `src/`, `config/`, `storage/`
- [ ] chiavi Pterodactyl/gateway ruotate rispetto al staging
- [ ] backup cifrato e restore provato
- [ ] `php tests/run.php` verde
- [ ] log di sicurezza monitorati (login falliti, CSRF, webhook invalidi)
- [ ] piano di risposta a incidenti condiviso con il team
