# Novità

## Versione 2.0.0

### Installazione e indirizzo
- Installazione con indirizzo IP e HTTP, oppure con dominio e HTTPS (Let's Encrypt).
- `deploy/set-address.sh` per cambiare indirizzo dopo l'installazione.
- Correzioni all'installer: niente pacchetto `php8.5-sodium`, utente del database
  creato anche per `localhost`, `.env` sempre completo.

### Licenza
- Senza licenza valida vengono bloccati nuovi ordini, carrello e checkout, e le
  modifiche ad aspetto, prodotti e add-on. Il sito usa la grafica predefinita.
  Servizi attivi, rinnovi, fatture e ticket continuano a funzionare.
- Revoca o scadenza bloccano subito. Se il server licenze non è raggiungibile il
  pannello resta sbloccato per 7 giorni.
- Attivazione dal browser (Impostazioni → Licenza) con chiave pubblica letta in
  automatico dal server licenze sulla stessa macchina, oppure incollata nel
  modulo. Messaggi di errore comprensibili.
- Server licenze con installer (`license-server/deploy/install.sh`: local, domain, ip),
  `bin/list.php`, e `deploy/set-license.sh` sul pannello. Guida: `docs/12-license-server.md`.
- Dall'archivio sono stati tolti la chiave privata e il database del server licenze.

### Grafica e personalizzazione
- Grafica classica e semplice: tema chiaro predefinito, menu admin laterale.
- Admin → Aspetto: colori, tema chiaro/scuro/automatico, logo e favicon,
  carattere, angoli, spaziatura, sfondo, testi e sezioni della home, barra
  annunci, footer e CSS personalizzato.

### Pacchetti, configuratore e pacchetti di espansione
- CPU indicata in **vCPU (core)** ovunque: catalogo, configuratore, pannello admin.
- Pacchetti fissi pronti: 2 GB/1 vCPU/5 GB SSD 4,00 €, 4 GB/2 vCPU/5 GB 8,00 €,
  8 GB/4 vCPU/10 GB 14,00 €, 12 GB/6 vCPU/15 GB 20,00 €, 16 GB/8 vCPU/20 GB 26,00 €.
- «Server su misura»: il cliente sceglie CPU, RAM e SSD e paga solo quello che sceglie
  (2,00 €/GB RAM, 1,50 €/vCPU, 0,50 € ogni 5 GB SSD, tutto modificabile).
- Pacchetti di espansione: +RAM, +vCPU, +SSD (aumentano davvero le risorse del server),
  CPU Performance, backup giornalieri e servizi extra; si aggiungono anche ai pacchetti fissi.
- Le espansioni sono **dentro ogni pacchetto fisso** e sono **opzionali**: si spuntano
  direttamente nella scheda del pacchetto (o nella pagina d'ordine) e il prezzo si aggiorna.
  Il configuratore su misura offre solo i servizi extra (backup, ecc.), perché CPU, RAM e SSD
  si scelgono già con i cursori.
- Su un'installazione esistente: `php bin/console catalog:packages [--hide-others]`.

### Email e chiavi di cifratura
- Cambio email da Area clienti → Sicurezza (con la password attuale), anche per l'admin.
- Le chiavi di cifratura sono salvate anche in `/etc/mcpanel/keys.env`: reinstallando
  i file del pannello le email non si perdono più. L'installer si ferma se trova un
  database con account ma senza le chiavi originali.

### Piani fissi e configurabili
- Ogni prodotto è un **piano fisso** (RAM, CPU, disco e prezzo stabiliti
  dall'admin, nessun cursore) oppure **configurabile** (il cliente sceglie le risorse).
- Il catalogo mostra le due sezioni separate; titoli, descrizioni, ordine e
  visibilità si impostano in Admin → Prodotti → Visualizzazione del catalogo.
- Da Admin → Prodotti si crea, duplica ed elimina un prodotto, e si cambiano
  categoria e ordine. Le nuove installazioni includono tre piani fissi di esempio.

### Prezzi e catalogo
- Prezzi inseribili in euro con i centesimi (19,99 o 19.99).
- Prezzi delle risorse (RAM, CPU, disco) per prodotto, prodotti in evidenza
  con etichetta, pagina Add-on.

### Clienti e account
- Accesso con email **oppure nome utente**.
- Area clienti → Sicurezza: cambio password (disconnette gli altri dispositivi).
- Admin → Clienti → Gestisci: modifica dati, blocca, disattiva, riattiva,
  reimposta password (anche 2FA), accredita wallet, elimina account.
- Comandi `user:password`, `user:email`, `user:list-admins`, `user:delete`.

### Vendite e infrastruttura
- Admin → Ricariche wallet: accetta (anche con importo corretto) o rifiuta le
  ricariche dei clienti; email automatica al cliente.
- Admin → Ordini: eliminazione degli ordini non pagati (singola o multipla);
  le fatture numerate vengono annullate, mai cancellate.
- Admin → Nodi: modifica, aggiunta ed eliminazione di nodi e location,
  importazione da Pterodactyl.
