# Guida amministratore

Il pannello admin vive sotto `ADMIN_PATH` (default `/admin`). Richiede un utente
con ruolo `super_admin`, `admin`, `support` o `billing`; se `enforce_admin_2fa`
è attivo e l'utente non ha la 2FA, viene rediretto alla configurazione.

## Dashboard

Mostra ricavi del mese, nuovi clienti, ordini in attesa, servizi sospesi,
provisioning falliti, top-up da confermare, ultimi ordini e capacità dei nodi.
I provisioning falliti sono cliccabili e portano al servizio interessato.

## Fatture

**Admin → Fatture** elenca le fatture emesse, filtrabili per stato (in attesa,
pagata, scaduta, annullata) e per numero, cliente, email o contenuto dello
snapshot fiscale. Per ogni riga si scarica il PDF consegnato al cliente;
**Esporta CSV** produce l'intero elenco filtrato con importi decimali, valuta,
regime IVA e date, pronto per il commercialista.

L'export applica la stessa allowlist dei filtri e neutralizza le celle che
iniziano con `=`, `+`, `-`, `@` per evitare formula injection in Excel.

## Report

**Admin → Report** aggrega i dati commerciali:

- ricavi incassati degli ultimi 12 mesi (fatture `paid`, per mese);
- MRR, ARPU sui clienti attivi, churn a 30 giorni e conversione ordini → pagati;
- nuovi ordini per prodotto (i rinnovi non sono inclusi);
- incassi per gateway e sconto concesso per coupon;
- margine per nodo: ricavo mensile normalizzato dei servizi attivi meno
  `monthly_cost_cents` del nodo.

Churn e conversione usano una finestra mobile di 30 giorni: sono indicatori
operativi, non sostitutivi di un sistema contabile.

## Contenuti

**Admin → Contenuti** gestisce articoli della knowledge base e comunicazioni:

- articolo: titolo, categoria, lingua (IT/EN), slug generato o esplicito, testo
  e stato bozza/pubblicato; lo slug duplicato riceve un suffisso numerico;
- comunicazione: titolo, testo, livello (`info`, `success`, `warning`,
  `danger`), scadenza opzionale e visibilità. Le comunicazioni pubblicate
  compaiono in home, dashboard cliente e pagina stato.

Le modifiche sono registrate nell'audit log. Per ritirare un contenuto usare
**Ritira/Nascondi**, non serve eliminarlo.

## Ordini e pagamenti manuali

**Admin → Ordini** elenca gli ordini con stato, totale e cliente. Un ordine con
bonifico resta `pending_payment` finché:

1. il bonifico arriva sul conto;
2. l'operatore apre l'ordine e clicca **Conferma pagamento**.

La conferma registra un pagamento manuale e marca l'ordine pagato: parte la
fattura (già emessa) e il provisioning. È idempotente: una doppia conferma non
crea doppi pagamenti.

> Verificare sempre la causale (`ORD-2026-00001`) e l'importo prima di
> confermare. Per storni usare una nota di credito, non cancellare la fattura.

## Servizi

**Admin → Servizi** con ricerca per cliente/stato. Azioni disponibili:

| Azione | Effetto |
|---|---|
| Sospendi | stato `suspended`, il server viene fermato nel pannello, email al cliente |
| Riattiva | torna `active`, il server viene avviato |
| Termina | stato `terminated`, server rimosso dopo la retention dati |
| Riprova provisioning | riaccoda il job con la stessa `provision_key` |
| Applica risorse | invia al pannello RAM/CPU/disco correnti (upgrade) |
| Estendi | sposta la scadenza e registra l'evento |

La terminazione è irreversibile lato pannello: verificare che non ci siano
fatture aperte e che il cliente sia stato avvisato.

## Ticket

**Admin → Ticket** elenca le richieste con filtro per stato e reparto. Aprendo un
ticket si vede la conversazione completa e si può:

- rispondere al cliente (il ticket passa a `waiting_customer` e viene registrata
  la prima risposta per le statistiche);
- aggiungere una **nota interna** (visibile solo allo staff, non genera email);
- **chiudere** il ticket;
- allegare un file alla risposta (PNG/JPEG/WebP/PDF/TXT/ZIP, max 5 MB) e
  scaricare quelli inviati dal cliente.

La chiusura non cancella i messaggi. Il cliente vede solo i messaggi pubblici:
le note interne sono filtrate lato server. Usare le note interne per verifiche
su pagamenti, sospetti abusi e coordinamento tra operatori.

## Clienti e wallet

**Admin → Clienti** ricerca per email, username o ID. Da qui si può:

- vedere servizi, fatture e ticket del cliente;
- **accreditare il wallet** con un importo e una causale (ledger append-only,
  tracciato nell'audit log). Usare per rimborsi o compensazioni, non per
  aggirare la fatturazione.

## Catalogo

**Admin → Prodotti** modifica nome, descrizione, visibilità, prezzi per ciclo,
opzioni risorse, add-on e mapping Pterodactyl. I prezzi sono in **centesimi**
(es. `1599` = 15,99 €). I prezzi di listino sono lordi IVA inclusa.

Dopo la modifica di un piano già venduto, i servizi esistenti mantengono il
prezzo congelato in `services.price_cents`: il nuovo listino vale solo per i
nuovi ordini.

## Impostazioni

- **Brand**: nome, colore primario, footer email, dati aziendali.
- **Fiscalità**: paese del venditore, aliquote, regime (reverse charge/OSS).
- **Automazioni**: giorni di preavviso, grazia, giorni prima della terminazione,
  soglia antifrode, addebito automatico dal wallet.
- **Sicurezza**: 2FA admin obbligatoria, manutenzione, `enforce_license`.
- **Gateway**: abilitazione Stripe/PayPal/bonifico/wallet tramite feature flag.

Le feature flag spente nascondono la funzione anche se il codice è presente.

## Log e diagnostica

- **Log**: audit log delle azioni, email inviate, webhook ricevuti, job falliti.
- **Diagnostica**: stato licenza, coda, endpoint Pterodactyl con latenza, nodi
  mappati, link all'healthcheck pubblico.

Se un webhook non compare, controllare la firma e il signing secret. Se un job
fallisce più volte, il servizio va in `failed`: leggere `last_error` e
`provisioning_logs`, correggere la causa (egg, allocazioni, permessi API) e
premere **Riprova provisioning**.

## Comandi operativi

```bash
php bin/console renewals       # emette le fatture di rinnovo
php bin/console suspend        # sospende i servizi scaduti oltre la grazia
php bin/console terminate      # termina dopo il periodo di conservazione
php bin/console reconcile      # confronta servizi e Pterodactyl
php bin/console queue:work     # processa la coda (il worker systemd lo fa già)
php bin/console license:check  # verifica la licenza
php bin/console about          # versione, PHP, database, installazione
```

Lo scheduler systemd esegue questi comandi ogni 15 minuti; eseguirli a mano solo
per recuperare un ritardo o fare manutenzione.
