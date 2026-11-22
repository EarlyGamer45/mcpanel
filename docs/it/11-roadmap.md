# Stato rispetto al piano

Ultimo aggiornamento: novembre 2026. Versione applicativa `0.1.0`.

## Riepilogo

| Area | Stato | Note |
|---|---|---|
| Catalogo, configuratore, carrello | Pronto | prezzi lordi, cicli, add-on, coupon |
| Checkout, ordini, fatture | Pronto | transazione unica, numerazione, PDF, IVA IT/UE/export |
| Pagamenti Stripe/PayPal | Pronto (codice) | firma e idempotenza testate; manca un test con account reali |
| Bonifico e wallet | Pronto | conferma admin, ledger append-only |
| Provisioning Pterodactyl | Pronto (codice) | idempotente, retry, log; da provare su staging reale |
| Rinnovi, sospensioni, terminazioni | Pronto | comandi + timer systemd |
| Client area | Pronto | servizi, fatture, wallet, ticket, profilo, 2FA, GDPR |
| Admin | Pronto | ordini, servizi, ticket, clienti, prodotti, coupon, impostazioni, log, diagnostica |
| Licenza Ed25519 | Pronto | server separato incluso e verificato end-to-end |
| Deploy Ubuntu | Pronto | install.sh/update.sh, Nginx, systemd |
| Coupon | Pronto | creazione, validità, limiti, prodotti, disattivazione |
| Guida plugin | Pronta | punti di estensione documentati in docs/13-plugins.md |
| Ticket staff | Pronto | lista con filtri, risposte pubbliche, note interne, chiusura |
| Allegati ticket | Pronto | upload 5 MB con MIME reale, storage fuori web root, download autorizzato |
| Report | Pronto | ricavi 12 mesi, MRR/ARPU/churn/conversione, prodotti, gateway, coupon, margine nodi |
| Contenuti | Pronto | editor KB IT/EN e comunicazioni con stato e scadenza |
| Fatture admin | Pronto | filtri per stato/cliente, PDF e export CSV con protezione formula injection |
| 2FA QR | Pronto | QR SVG generato in locale (libreria MIT inclusa), nessun servizio esterno |
| Console web | Pronto (codice) | websocket Pterodactyl, auth, output live, comandi, refresh token; da provare su panel reale |
| Documentazione | Pronta | architettura, DB, install, admin, client, API, sicurezza, DR |
| Test automatici | Buono | 21 test unitari/integrati, incluso provisioning e websocket contro mock Pterodactyl; nessun e2e browser |
| Internazionalizzazione | Pronta | IT/EN, 480+ chiavi allineate |

## Cosa è stato verificato in locale

- `php -l` su tutto il codice (nessun errore di sintassi).
- `migrate` e `db:seed` su SQLite.
- 21 test verdi (`tests/run.php`), incluso il ciclo di provisioning completo
  contro un mock HTTP delle Application API Pterodactyl e l'unwrap delle
  credenziali websocket della console.
- Flusso HTTP completo con curl: login, catalogo, carrello, checkout, ordine,
  fattura, bonifico, conferma admin, servizio in coda, PDF, pagine admin,
  coupon (creazione, sconto, disattivazione), ticket (apertura, risposta,
  nota interna, chiusura) e allegati (upload PNG, download, rifiuto di un
  file PHP camuffato da immagine).
- Licenza: chiavi Ed25519, attivazione, firma verificata, stato `active`.
- Invio email con driver `log` (template generati).

## Gap prima di un go-live commerciale

### Da fare subito

1. **Test con Pterodactyl reale**: creare un server su staging, verificare egg,
   allocazioni, variabili e console.
2. **Test gateway reali**: Stripe test mode e PayPal sandbox, inclusi webhook
   duplicati e rimborsi.
3. **SMTP reale**: SPF/DKIM/DMARC, verifica deliverability.
4. **MariaDB**: smoke test su 11.8 (SQLite non copre collation e lock).
5. **Fatturazione italiana**: validare il formato PDF e i dati fiscali con il
   commercialista; valutare integrazione SdI (non inclusa).
6. **Backup**: attivare lo script notturno e provare un restore completo.

### Funzioni parziali

Nessuna funzione del piano è rimasta incompleta lato codice. Restano solo
verifiche che richiedono infrastrutture esterne (Pterodactyl, gateway, SMTP,
MariaDB) elencate sopra.

La valuta è per installazione (`APP_CURRENCY`), come da architettura: non è un
carrello multi-valuta con tassi di cambio. La formattazione è centralizzata e
le fatture conservano la valuta usata.

### Fuori perimetro (come da piano)

- Pannello Pterodactyl custom o fork: non previsto, solo API.
- App mobile, API client pubblica, webhook in uscita verso terzi.
- Integrazione SdI, fatturazione elettronica estera, contabilità generale.
- Multi-tenant (più brand su una installazione).

## Prossimi passi suggeriti (in ordine)

1. Staging completo con Pterodactyl e gateway sandbox.
2. Hardening: fail2ban, monitoraggio (UptimeRobot + alert su coda); HSTS e
   header di sicurezza sono già emessi dall'applicazione.
3. Test e2e automatici (Playwright) sui flussi critici.
4. Ottimizzazione: indici su volumi reali, cache impostazioni, CDN asset.

## Cronologia

- **v0.1.0** — prima implementazione completa: catalogo, checkout, fatture,
  pagamenti, provisioning, client area, admin, licenza, deploy e documentazione.
