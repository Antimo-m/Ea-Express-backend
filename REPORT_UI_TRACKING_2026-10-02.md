# Revisione UI e Tracking — 2 ottobre 2026

## 1. File modificati in questa revisione

| Area | File |
| --- | --- |
| Design condiviso | `resources/css/experience.css`, `../Ea-express/src/styles/experience.css` |
| Controlli condivisi | `resources/views/components/ui/{icon-button,field,filters}.blade.php` |
| Affidabilità | `resources/views/components/orders/{recipient-name,recipient-risk,row,card}.blade.php`, `resources/views/dashboard/index.blade.php`, `resources/views/pickups/index.blade.php` |
| Listini | `resources/views/rates/index.blade.php`, `resources/js/rates.js` |
| Resoconti | `resources/views/reports/index.blade.php` |
| Accesso pubblico | `resources/views/welcome.blade.php`, `resources/views/auth/{login,forgot-password}.blade.php`, `resources/js/app.js` |
| Segnalazioni | `app/Http/Controllers/RecipientIncidentController.php`, `resources/views/recipient-incidents/index.blade.php` |
| Tracking | `app/Http/Controllers/{TrackingController,OrderController}.php`, `resources/views/orders/show.blade.php`, `resources/views/tracking/public.blade.php`, `resources/views/components/orders/timeline.blade.php`, `resources/js/live-workspace.js` |
| Verifiche | `tests/Feature/{WorkspaceRevisionTest,WorkspacePresentationTest}.php`, `tests/pickers.browser.test.mjs`, `.gitignore` |

Le altre modifiche già presenti nei due repository sono state preservate; questa tabella descrive gli interventi della nuova richiesta.

## 2. Componenti creati

`orders.recipient-name` centralizza nome, colore condizionale e badge di affidabilità. `orders.timeline` riutilizza la cronologia esistente dell'ordine con timestamp, operatore, note interne/pubbliche e ripianificazioni, senza creare nuovi eventi o duplicare la logica di transizione.

## 3. Componenti condivisi modificati

IconButton, Field, Filters, picker, righe ordine, card ordine e badge. IconButton espone nome accessibile, title e tooltip. Field identifica i campi temporali compatti; Filters supporta l'accento arancione richiesto dai Resoconti. Gli stili condivisi dei due repository sono sincronizzati.

## 4. Pagine aggiornate

Resoconti, Listini e relativi dialoghi, Dashboard, ordini in ingresso, spedizioni in corso, Storico, dettaglio ordine, destinatari non affidabili, Benvenuto, Login, recupero password e tracking pubblico. Le altre pagine ereditano la dimensione dei controlli condivisi. Il portale clienti eredita gli stili compatti; le informazioni interne di affidabilità restano riservate agli operatori.

## 5. Regola small button

Pulsanti standard, azioni con icona, navigazione secondaria, pulsanti popup/calendario, campi data/mese/ora, controlli della sidebar e delle modali adottano dimensioni compatte. Il riferimento è 34 px per le azioni e 32 px per i pulsanti nei picker, con larghezza proporzionata al contenuto. Etichette lunghe possono andare a capo e aumentare naturalmente l'altezza. Rimossa anche la larghezza piena del submit di accesso e recupero password.

Non è stato applicato un restringimento indiscriminato agli input testuali: indirizzi e descrizioni conservano lo spazio necessario.

## 6. Resoconti

Il mese di riferimento usa il campo compatto condiviso. Filtra e Ripristina hanno fondo arancione EA-Express e testo/icona scuri. La verifica browser conferma il selettore inferiore a 240 px e il colore arancione delle azioni.

## 7. Campi rimossi dal Listino

Rimossi realmente dal markup e dal JavaScript del form `max_weight_kg` e `max_dimension_cm`, senza nasconderli con CSS né inviare valori nulli al salvataggio.

Questi dati **non sono obsoleti nel backend**: `ShippingQuote` li utilizza per selezionare le tariffe fuori regione compatibili con i colli. Le colonne, le validazioni API, i dati salvati e lo storico sono conservati. La revisione di un listino dal nuovo form mantiene i limiti esistenti. Nessuna migrazione distruttiva.

Conservati i giorni lavorativi minimi/massimi perché determinano le stime di consegna, il vettore e il suo costo quando applicabili, oltre a località, area, CAP, prezzo, tempi e stato.

## 8. Cronologia modifiche Listino

Cronologia modifiche è affiancata a Tempi di consegna nello stesso contenitore flex, con wrapping su schermi stretti. Il controllo appare quando si modifica una tariffa esistente. Torna indietro è solo un'icona, con tooltip, title e aria-label; il salvataggio conserva il versionamento e le protezioni da aggiornamenti concorrenti.

## 9. Badge Non affidabile

Badge statico, senza summary, frecce, chevron o menu: fondo rosso pieno, testo bianco, padding ridotto e bordo a pillola. Il nome è rosso e il badge si trova sotto il nome. Conteggio e ultimo episodio restano disponibili come informazioni testuali, senza dover aprire un dropdown.

## 10. Uniformazione dell'affidabilità

Componente comune nelle righe di ordini, Storico e spedizioni, nelle card ordine, nei dati e nel riepilogo del dettaglio, nella Dashboard e nel registro segnalazioni. La tabella dei ritiri raggruppati usa lo stesso componente; la Dashboard Rider riutilizza le righe comuni. Nessuna query di rischio viene eseguita dai componenti Blade e nessun dato interno è aggiunto alle API clienti.

## 11. Benvenuto e Login

Superfici, colori, bordi, logo, tipografia e card di accesso coerenti con il gestionale, con layout desktop e mobile. Entry con accesso allo spazio operativo e descrizione delle funzioni reali. Login con etichetta Admin/Rider corretta, pulsante compatto, password visibile/nascosta, errori dei campi già esistenti e stato “Accesso in corso” che blocca submit duplicati. Recupero password eredita il medesimo design. Autenticazione, CSRF e validazioni restano invariati.

## 12. Destinatari non affidabili

Riepilogo con destinatari attivi, episodi attivi e destinatari ripristinati; ricerca per nome, telefono, comune o indirizzo; filtro Attive/Ripristinate/Tutto lo storico e paginazione che mantiene i filtri. Schede con nome/badge, telefono, date, indirizzi, riferimento ordine, collegamenti agli ordini e storico rettifiche.

Gli episodi sono leggibili direttamente; i form di rettifica sono separati dalle informazioni. Restano le azioni reali di correzione e rimozione motivata delle segnalazioni. Rimuovere l'ultimo episodio attivo ripristina l'affidabilità senza cancellare gli eventi storici o modificare l'ordine originale. Nessun dato artificiale o nuovo punteggio introdotto.

## 13. Causa del bug Tracking Cliente

Il dettaglio gestionale apriva la route pubblica `/track/{token}`, costruita nel layout pubblico. Quella pagina aveva un collegamento di ritorno fisso a `config('customer.frontend_url') + '/shipments'`, senza considerare chi l'aveva aperta. La configurazione predefinita del portale è `http://localhost:5173`: questo indirizzava anche Admin/Rider al server Vite del portale, fuori dal gestionale.

Il passaggio all'area pubblica era quindi causato dal link alla route pubblica, mentre il ritorno alla SPA diversa era causato dal collegamento fisso. Non è emersa una route Laravel duplicata o un fallback React da correggere per questo percorso.

## 14. Route coinvolte

Laravel: `/orders/{order}`, `/track/{token}`, `/dashboard` e `/`. Portale React: `/shipments`, dichiarata nel suo router. La route React rimane valida per il portale clienti e non viene trasformata in una route gestionale.

## 15. Soluzione routing

Il controller del tracking determina il rientro: Admin/Rider verso l'ordine se autorizzati, altrimenti Dashboard; cliente autenticato verso il proprio portale; visitatore anonimo verso l'entry EA-Express. Il controller consulta la policy dell'ordine prima di produrre il collegamento diretto. Il test include un Rider non assegnato.

Rimosso dal dettaglio il blocco “Tracking del cliente” e il suo link esterno. Nessun `history.back()`, redirect alla cieca o modifica dei fallback SPA. I link pubblici esistenti continuano a funzionare.

## 16. Nuova card Tracking

In fondo al dettaglio una sezione permanente con titolo Tracking, stato attuale, ultimo aggiornamento, Rider assegnato, pannello GPS e timeline ordine. La timeline è visibile senza frecce o click, ordinata dalla creazione agli eventi successivi, con paginazione dedicata `events_page` e area scrollabile per elenchi lunghi. Se non vi sono eventi, compare un messaggio esplicito.

Gli aggiornamenti realtime sostituiscono riepilogo e timeline preservando il pannello GPS e i dati eventualmente inseriti nei suoi campi.

## 17. Integrazione mappa live

Riutilizzato il pannello tracking esistente, i suoi endpoint autorizzati, gli aggiornamenti GPS e i controlli per i punti di ritiro/consegna. Spostato nella nuova card senza istanziarlo due volte. La timeline resta visibile anche se il GPS è assente o la richiesta delle posizioni fallisce.

Il pannello attuale gestisce stato e coordinate; il renderer geografico della mappa non è completato in questa revisione. La card è predisposta per ospitarlo e non dipende dal suo completamento.

## 18. Verifiche

Fixture con nomi e testi lunghi, destinatari realmente segnalati nel database di test, importi, molti ordini, episodi, cronologia e pagine di accesso. Browser su 375, 768, 1280 e 1440 px: 24 pagine, 96 combinazioni. Controllati overflow, badge sotto il nome, collisioni, controlli arancioni, altezza delle azioni, dialogo Listino, password e picker con tastiera.

Test dedicati per ritorno dal tracking (ospite/Admin/Rider non assegnato/cliente), visibilità timeline e badge statici, ricerca e ripristino segnalazioni, conservazione dei limiti delle tariffe fuori regione. Esiti:

- Suite backend completa: **443 test passati, 4.098 assertion**.
- Verifica dedicata finale, inclusa la tabella dei ritiri: **4 test passati, 36 assertion**.
- Browser: **13/13 passati**, nessun test saltato.
- Frontend: **20/20 passati**; lint riuscito.
- Build riuscite in entrambi i repository; Pint e `git diff --check` riusciti.
- Contrasto: **1.199/1.199 campioni testuali conformi per ciascun tema**, con le esclusioni descritte sotto.

Screenshot di Login nei due temi, Listino, Resoconti, registro destinatari e Tracking verificati visivamente; salvati fuori dai repository in `/private/tmp/ea-ui-revision-screenshots`.

## 19. Limiti residui

Browser verificato: Chrome, con viewport emulati. Safari, Firefox, dispositivi fisici e screen reader non verificati direttamente. Il contrasto automatizzato riguarda i campioni testuali rilevati, escludendo icone, gradienti e contenuti nascosti; non è una certificazione completa di accessibilità. GPS e autorizzazioni verificati applicativamente, senza un percorso reale su strada.

Nessuna nuova dipendenza o migrazione. Modifiche locali, senza deploy.
