# Guida cliente

## Creare l'account

Registrazione da `/register` con username, email e password (minimo 10
caratteri). L'email di verifica è facoltativa per acquistare ma necessaria per i
recuperi di password e le comunicazioni di servizio. La password è salvata con
Argon2id: nessuno, nemmeno l'assistenza, può leggerla.

## Attivare la 2FA

Da **Sicurezza** si attiva la 2FA TOTP: inquadrare il QR con l'app di
autenticazione (o inserire la chiave manuale), confermare il codice e salvare i
codici di recupero mostrati una sola volta.

## Scegliere e configurare un server

1. `/catalog` mostra i piani; `/server/{slug}` apre il configuratore.
2. Scegliere RAM, CPU, disco, software e versione: il prezzo si aggiorna subito.
3. Selezionare il ciclo di fatturazione e gli add-on.
4. **Aggiungi al carrello**, poi **Procedi al checkout**.

I prezzi sono IVA inclusa. L'IVA viene scorporata in fattura secondo il paese e
il regime fiscale (reverse charge per aziende UE con VIES valido, OSS per
consumatori UE, export fuori UE).

## Pagare

| Metodo | Comportamento |
|---|---|
| Carta (Stripe) | redirect sicuro; i dati carta non passano dal nostro server |
| PayPal | redirect a PayPal, conferma via webhook |
| Bonifico | istruzioni con IBAN e causale; attivazione dopo la conferma dell'admin |
| Wallet | scala subito il credito disponibile |

Il server parte automaticamente appena il pagamento è confermato. L'email con IP,
porta e accesso al pannello arriva a provisioning completato (in genere entro un
minuto). Se dopo 10 minuti non arriva, aprire un ticket indicando il numero
d'ordine.

## Gestire il server

Da **Dashboard → Servizi**:

- stato, IP e porta;
- **console live** con output del server e invio comandi (websocket del pannello);
- avvio, stop, riavvio e comando `say` (se il pannello è configurato);
- scadenza e rinnovo;
- richiesta di disdetta (il servizio resta attivo fino alla scadenza).

Per modificare RAM/CPU/disco aprire un ticket o usare l'upgrade dal servizio: il
prezzo viene ricalcolato con il prorata dei giorni rimanenti.

## Rinnovi e wallet

Il rinnovo genera una fattura con preavviso (default 7 giorni). Se il wallet ha
saldo sufficiente e l'addebito automatico è attivo, la fattura viene saldata da
sola. Altrimenti si paga dalla fattura. Dopo la scadenza c'è un periodo di grazia
(default 5 giorni) prima della sospensione; i dati restano per 14 giorni prima
della terminazione.

**Ricarica wallet**: dal menu Wallet si crea una richiesta di bonifico con un
importo; usare la causale indicata. L'admin accredita il saldo alla conferma.

## Ticket

Da **Ticket → Nuovo ticket** scegliere reparto (tecnico, fatturazione, vendite),
priorità e servizio collegato. La risposta media è indicata nella pagina. È
possibile allegare un file (PNG, JPG, WebP, PDF, TXT o ZIP fino a 5 MB) sia
all'apertura sia nelle risposte; i file si scaricano dal ticket e non sono mai
pubblici. Non inviare credenziali o dati di pagamento nei ticket.

## Sicurezza dell'account

- **2FA**: da Profilo → Sicurezza si abilita TOTP (Google Authenticator, Authy,
  1Password). Salvare i codici di recupero: non vengono mostrati di nuovo.
- **Sessioni**: l'uscita revoca la sessione corrente; cambiare password revoca
  le altre sessioni.
- **Email**: per cambiarla aprire un ticket (verifica manuale anti-frode).

## Privacy e dati

Da Profilo → Privacy:

- **Esporta i miei dati**: JSON con account, servizi, fatture e ticket.
- **Elimina account**: cancella i dati personali; le fatture restano conservate
  per obbligo fiscale e sono oscurate nei dati identificativi dove possibile.
  Prima di eliminare occorre disdire i servizi attivi.

## Problemi comuni

| Sintomo | Cosa fare |
|---|---|
| Il server non parte | controllare console e versione; aprire ticket con l'errore |
| Pagamento riuscito ma ordine non pagato | attendere 1-2 minuti (webhook), poi ticket con ID pagamento |
| Non arriva l'email | controllare spam; con `MAIL_DRIVER=log` in demo le email non partono |
| 2FA perso | usare un codice di recupero o contattare l'assistenza con documento |
| Fattura da modificare | aprire ticket fatturazione entro 14 giorni |
