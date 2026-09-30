# Revisione mirata dei cinque finding Aikido

Base verificata: `main`, commit `3b02384`, 30 settembre 2026. Il confronto riguarda il codice effettivamente presente, non gli snippet storici dello scanner. I test dei controlli esistenti sono stati eseguiti prima della correzione applicativa.

| Finding | Stato iniziale nel codice attuale | Modifica necessaria | Test aggiunti/versionati | Stato finale |
|---|---|---|---|---|
| 1. Manipolazione incasso | PARZIALMENTE RISOLTO | Controllare importo positivo e saldo esatto dopo il lock; valorizzare `paid_at` solo a totale completo | `PaymentIntegrityTest`, `PaymentConcurrencyTest`, controlli Rider e replay in `WorkflowSecurityTest` | FIX APPLICATO |
| 2. Accettazione prezzo rifiutato | GIÀ RISOLTO | Nessuna modifica alla logica; regressioni sulle proposte e sui ruoli | `WorkflowSecurityTest`, `ShippingQuoteSecurityTest` | GIÀ RISOLTO |
| 3. Peso/dimensioni falsificati | GIÀ RISOLTO | Nessuna modifica al calcolo; regressioni su misure e ricalcolo al checkout | `ShippingQuoteSecurityTest` | GIÀ RISOLTO |
| 4. Zona/strada e tariffa generica | GIÀ RISOLTO | Nessuna modifica al resolver; regressioni con tariffa generica 5 € e specifica 8 € | `ShippingQuoteSecurityTest` | GIÀ RISOLTO |
| 5. ETA fuori da Rescheduled | GIÀ RISOLTO | Nessuna modifica al workflow; regressioni HTTP, Action e percorsi alternativi | `WorkflowSecurityTest` | GIÀ RISOLTO |

## 1. Integrità degli incassi

Il precedente attacco del Rider non è più applicabile: la route richiede Admin e `PaymentController::store()` controlla sia l'attore iniziale sia l'utente riletto con lock nella transazione. I test riproducono richieste del Rider assegnato, anche con `role=admin`, importi e campi economici falsificati, e la chiamata diretta al controller senza middleware. Risultato: 403, nessun movimento e ordine non pagato.

Il gap dell'Admin era reale: prima del fix le richieste da 0 €, 6 € e 10,01 € su un ordine da 10 € ottenevano 200. Anche un ordine regionale a prezzo zero generava un movimento nullo. I quattro casi fallivano nei test prima della correzione e sono ora rifiutati.

La scelta del dominio conserva i due flussi esistenti:

- **Incasso diretto**: saldo completo positivo. Dopo i lock di attore, periodo contabile e ordine, si legge il prezzo approvato e si sommano i movimenti; l'importo deve coincidere con il residuo. Il controllo precedente che impedisce incassi diretti su movimenti netti preesistenti resta attivo. `paid_at` e `paid_by` dipendono dall'uguaglianza tra totale finale e prezzo.
- **Sospesi**: pagamenti parziali già supportati da `PendingAccountController::settle()`. I test dimostrano 6 € su 10 € con `paid_at=null`, rifiuto di zero/negativi/oltre-residuo, replay idempotente e saldo finale da 4 €. L'endpoint diretto non aggira un Sospeso attivo.

Le richieste respinte lasciano invariati ordine, versioni, movimenti e audit economici. Sono verificati anche storno, ripristino e nuova registrazione, senza alterare prezzo o costo vettore.

`PaymentConcurrencyTest` avvia due processi PHP indipendenti, con due Admin diversi, contro il kernel HTTP e un database SQLite temporaneo condiviso. Entrambi inviano la stessa versione dell'ordine: uno ottiene 200, l'altro 422; resta un solo movimento da 1.000 centesimi e una sola revisione dell'ordine. Il database temporaneo e i processi sono ripuliti anche in caso di errore.

Non sono stati aggiunti lock sulle singole PaymentEntry: i punti di scrittura individuati (`PaymentController`, `PendingAccountController`, `CorrectPendingSettlement`) acquisiscono già il lock sull'ordine, oltre al controllo contabile condiviso. Il test concorrente usa SQLite; non costituisce una prova delle specifiche modalità di locking di MySQL in produzione.

**File applicativo modificato:** `app/Http/Controllers/PaymentController.php`.

## 2. Proposte prezzo rifiutate

`TransitionOrder` consente Accepted solo agli Admin. Per il pricing attuale, `OrderPrice::assertApproved()` rifiuta stato pending/rejected, proposte ancora pendenti, ultima proposta non accettata e prezzo diverso dalla proposta accettata. Un `quoted_price_cents` rimasto valorizzato non annulla il rifiuto del Cliente.

I test coprono pending, rejected con quote ancora presente, proposta accepted con prezzo incoerente e tentativi Rider/Admin. Confrontano l'intero ordine prima/dopo, stato proposta e assenza di eventi/audit. Il percorso valido propone 8 € con quote precedente da 6 €, ottiene il consenso Cliente e mantiene 8 € durante accettazione e consegna.

Sono stati esaminati gli altri punti di scrittura di prezzo, stato prezzo e quote:

- `ShippingPriceController`: proposta solo Admin; risposta del Cliente proprietario; eccezione esplicita Admin per clienti senza account con accordo annotato; lock e versione; proposte consentite solo con `pricing_version=1` e ordine Received.
- `CreateOrder` e `CustomerOrderController`: dati validati e conferma tramite `CheckoutReview`; nessuna assegnazione del prezzo economico fornito come campo extra dal client.
- `CheckoutReview`: blocca pending/rejected anche nell'editing; verifica il prezzo accettato e impedisce di cambiare le condizioni di un ordine con proposte.
- `PaymentController`, `PendingAccountController`, `CorrectPendingSettlement` e avanzamento vettore: controllo del prezzo approvato prima delle operazioni economiche/di avanzamento pertinenti.
- `Order`: i campi economici critici e lo stato non sono liberamente assegnabili tramite `fill()`.

Il ramo di assegnazione prezzo degli ordini legacy resta preservato; l'endpoint delle proposte non è disponibile per quegli ordini. Nessun bypass riproducibile individuato nei percorsi esaminati.

**File applicativi modificati:** nessuno. Test in `WorkflowSecurityTest` e `ShippingQuoteSecurityTest`.

## 3. Peso, dimensioni e checkout

`ShippingQuote::measurements()` ricava peso totale e dimensione massima dai colli. `StoreOrderRequest`, normalizzazione del checkout e creazione dell'ordine riutilizzano quel calcolo. Le richieste manipolate dimostrano 100 kg reali dichiarati nei colli contro 1 kg top-level e dimensione 200 cm contro 10 cm top-level: la preview usa 100 kg/200 cm e non emette un token utilizzabile per la tariffa incompatibile.

Sono coperti anche conteggio colli incoerente, limite totale superato, dati modificati dopo preview, misure persistite e campi tariffari client ignorati. Tariffa disabilitata, capacità ridotta o prezzo modificato tra preview e conferma causano rifiuto senza creare un ordine. `CheckoutReview::confirm()` conserva decifratura, binding utente/ordine, scadenza, confronto dati normalizzati, lock e ricalcolo server-side.

La semantica dei limiti null è intenzionale: il modulo Listini indica «vuoto = nessun limite»; il comando `InitializeExternalRate` crea esplicitamente la tariffa generale configurabile; `ReviseShippingRate` preserva la distinzione della tariffa default. Nessuna nuova regola commerciale introdotta. I test dimostrano che misure mancanti sono accettate soltanto per la tariffa **default external senza entrambi i limiti**, mentre tariffe vincolate o specifiche restano indisponibili.

**File applicativi modificati:** nessuno. Test in `ShippingQuoteSecurityTest`.

## 4. Territorio, zona e strada

`PostalCodeResolver::canonical()` verifica Comune, CAP, provincia e regione sul dataset interno. Le quattro combinazioni incompatibili sono rifiutate al checkout e non creano ordini.

Con tariffa generica 5 € e specifica 8 €, la zona omessa o falsificata non seleziona 5 €: la specifica determinata dal CAP rimane applicata anche al salvataggio. Più zone incompatibili per lo stesso CAP rendono il preventivo indisponibile. In presenza di una tariffa Via Roma, strada omessa o Via Milano non provoca fallback: la tariffa è indisponibile; una corrispondenza normalizzata valida usa 8 €.

Il dataset non prova l'esistenza fisica dell'indirizzo o l'appartenenza autorevole di ogni strada a una zona commerciale. La chiusura dimostrata riguarda il precedente fallback alla generica; le configurazioni territoriali restano la fonte per le specifiche e quelle ambigue devono essere verificate manualmente. Non è stato inventato un database geografico aggiuntivo.

**File applicativi modificati:** nessuno. Test in `ShippingQuoteSecurityTest`.

## 5. ETA e transizioni

`UpdateOrderStatusRequest` e `TransitionOrder` validano entrambi `estimated_at`; l'Action lo assegna solo a Rescheduled e lo cancella negli altri passaggi validi. I test inviano Delivered, Cancelled e OutForDelivery con ETA futura sia tramite HTTP sia direttamente all'Action: errore di validazione, ordine invariato e nessun evento.

Rescheduled senza ETA è rifiutato; con ETA futura è consentito; il successivo OutForDelivery la elimina. `CarrierShipmentController` rifiuta il campo e `PickupScheduleController` passa solo i campi validati a `ReschedulePickup`: una ETA aggiunta alla richiesta di ritiro non viene salvata. `ShippingQuote::estimate()` modifica gli intervalli tariffari `estimated_delivery_from/to`, non `estimated_at`.

**File applicativi modificati:** nessuno. Test in `WorkflowSecurityTest`.

## Riproduzione

La suite mirata e l'infrastruttura minima sono incluse nel repository: quattro classi test, `tests/TestCase.php`, quattro factory e `phpunit.security.xml`. `.gitignore` permette soltanto questi file della precedente suite locale; gli altri test e dati operativi restano esclusi.

Con le dipendenze di sviluppo installate da `composer.lock`, PHP compatibile con esse, PDO SQLite e possibilità di avviare processi PHP:

```sh
composer install --no-interaction
vendor/bin/phpunit -c phpunit.security.xml
```

La configurazione forza ambiente testing, database SQLite in memoria, cache/sessioni/mail locali e broadcasting disabilitato; include una chiave pubblica esclusivamente di test. Non richiede `.env`, database di produzione, credenziali esterne, Node o build frontend. Il test concorrente usa esclusivamente un file temporaneo.

Risultato: **48 test, 316 asserzioni**, tutti superati anche in una copia pulita dei file destinati al repository, senza `.env` o asset compilati, con le dipendenze già installate copiate nella directory isolata. Nessun nuovo pacchetto o migrazione necessario. Formatting verificato con `vendor/bin/pint --dirty --format agent`.

La suite locale completa supera **412 test e 3.873 asserzioni** (`php artisan test --compact`). Nei test locali preesistenti `StaffCheckoutAndReceiptsTest` e `ShippingPhaseTest` sono state aggiornate le aspettative che ammettevano zero/importi diversi dal saldo; quei file restano esclusi da Git. Le nuove aspettative sono coperte autonomamente da `PaymentIntegrityTest`, incluso nella suite riproducibile.

Gli esiti di Aikido non sono stati modificati né una scansione remota dichiarata conclusa: occorre che lo scanner analizzi il commit pubblicato contenente questa correzione. Questi risultati riguardano i cinque vettori richiesti, non certificano l'assenza di qualsiasi altra vulnerabilità.
