# Integrazione Pterodactyl

Pterodactyl resta l'unico proprietario di container, risorse e console. La
piattaforma usa **solo** le API ufficiali:

| API | Uso |
|---|---|
| Application API (`ptla_`) | utenti, nodi, allocazioni, server, nest, egg |
| Client API (`ptlc_`) | console, comandi power, websocket, file |

Nessuna patch al pannello, nessun plugin richiesto, nessun accesso al database
di Pterodactyl.

## Chiavi e permessi

Creare in Pterodactyl → Admin → Application API una chiave con:

```
users.read users.write
nodes.read
allocations.read allocations.write
servers.read servers.write
nests.read eggs.read
```

e una chiave Client con `websocket.connect`, `control.console`,
`control.start`, `control.stop`, `control.restart`, `file.read`.

Le chiavi stanno solo in `.env` (`PTERODACTYL_APP_KEY`, `PTERODACTYL_CLIENT_KEY`).
Non vanno mai nel database, nei log o nel repository. Ruotarle ogni 90 giorni.

## Versioni supportate

Pterodactyl 1.11 e 1.12. La piattaforma rileva la versione da `/api/application`
e la salva nella diagnostica; `PTERODACTYL_VERSION` forza il comportamento se il
rilevamento non è possibile. Le differenze usate sono minime: endpoint e formato
delle relazioni `variables` sono compatibili.

## Mapping prodotto ↔ egg

In **Admin → Prodotti** ogni piano deve avere:

| Campo | Significato |
|---|---|
| `nest_id` | nest Pterodactyl (es. Minecraft) |
| `egg_id` | egg (Paper, Forge, Vanilla, Bedrock…) |
| `docker_image` | immagine docker; vuoto = quella dell'egg |
| `startup` | comando di avvio; vuoto = quello dell'egg |
| `allowed_versions_json` | versioni selezionabili nel configuratore |
| `meta_json.software` | software predefinito (`paper`, `forge`, `fabric`…) |

Il payload di creazione (`ServerSpec::build`) usa:

- `limits.memory` = RAM scelta, `limits.disk` = disco, `limits.cpu` = CPU,
  `swap=0`, `oom_disabled=true`;
- `feature_limits`: 2 database, 3 backup, 2 allocazioni (modificabili nel codice);
- `allocation.default` = prima allocazione libera del nodo;
- `start_on_completion=true`.

### Variabili d'ambiente

Le variabili dell'egg vengono copiate con i loro default; poi:

- `MINECRAFT_VERSION`, `MC_VERSION` o `VERSION` (se presenti nell'egg) ricevono
  la versione scelta dal cliente;
- `SERVER_JARFILE` diventa `<software>.jar` (`server.jar` per vanilla);
- `BUILD_NUMBER` = `latest`;
- `SERVER_TYPE` = software scelto.

Se l'egg non espone queste variabili, la versione non viene forzata: si usa il
default dell'egg. Aggiungere le variabili nell'egg se serve selezionare la
versione per piano.

## Nodi e allocazioni

`nodes` mappa i nodi Pterodactyl (`pterodactyl_node_id`), la capacità
(`memory_mb`, `disk_mb`), la location e lo stato di manutenzione. `NodeSelector`
sceglie il nodo:

1. esclude nodi in manutenzione o non visibili;
2. filtra per location richiesta, se presente;
3. ordina per RAM allocata / capacità (least allocated) oppure round robin.

Prima di creare un server verifica che esista un'allocazione libera. Se non c'è,
il job fallisce con un errore chiaro e l'ordine resta pagato: l'admin aggiunge
allocazioni e riprova.

## Provisioning passo per passo

1. `order paid` → evento → `ProvisionServerJob` in coda `provisioning`.
2. Il job crea l'utente Pterodactyl (password casuale, email del cliente) o
   riusa quello esistente (`users.read` per email).
3. Seleziona nodo e allocazione.
4. Crea il server con `ServerSpec`.
5. Salva `pterodactyl_server_id`, `identifier`, IP e porta.
6. Porta il servizio a `active`, invia email con i dati di accesso.
7. Ogni passo è in `provisioning_logs`; gli errori HTTP sono salvati con status
   e body (senza segreti).

Idempotenza: `services.provision_key` è generata alla creazione dell'ordine e
inviata come `external_id`; se il job viene ritentato dopo un timeout, prima di
creare un nuovo server cerca quello con lo stesso `external_id`.

## Console e azioni

Le azioni power passano dalla Client API con le credenziali del cliente
(`PTERODACTYL_CLIENT_KEY` non viene mai esposta al browser). La console usa il
websocket del pannello: il browser si connette direttamente al websocket di
Pterodactyl con un token generato dal server; se il pannello non è raggiungibile
dal browser, mostrare un avviso e usare il link diretto.

## Riconciliazione

```bash
php bin/console reconcile
```

Confronta i servizi `active` con i server del pannello e riporta:

- server presenti nel pannello ma non in piattaforma (orfani);
- servizi senza `pterodactyl_server_id` (provisioning incompleto);
- differenze di RAM/CPU/disco (modifiche manuali);
- server sospesi nel pannello ma attivi in piattaforma.

Non modifica nulla: serve a decidere l'intervento. Eseguirlo dopo ogni incidente
del pannello o migrazione di nodo.

## Errori comuni

| Errore | Causa | Rimedio |
|---|---|---|
| 403 su `/api/application/servers` | permessi chiave insufficienti | aggiungere `servers.write` |
| 422 `allocation_id` | nessuna allocazione libera | aggiungere allocazioni al nodo |
| 404 egg | `egg_id` sbagliato o nest diverso | correggere il prodotto |
| 409 email già usata | utente esistente con email diversa | allineare l'utente o usare la stessa email |
| timeout | nodo/pannello lento | aumentare `PTERODACTYL_TIMEOUT`, il job ritenta |

## Test in staging

1. creare un ordine con bonifico e confermarlo;
2. seguire `storage/logs/app-*.log` e `provisioning_logs`;
3. verificare utente, server, allocazione, variabili e avvio nel pannello;
4. provare stop/start dalla client area;
5. sospendere e riattivare dalla admin;
6. eseguire `reconcile` e verificare che non segnali drift.
