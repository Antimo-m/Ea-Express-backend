# Revisione Gestionale EA-Express — 2 ottobre 2026

Le modifiche sono implementate nei due progetti, Gestionale Laravel/Blade e portale React. La revisione estende i componenti condivisi e il lavoro già presente su tema e tracking. L'Admin può assegnarsi un ordine e completarne il flusso operativo mantenendo il proprio ruolo.

## 1. Pagine analizzate

Verificate mediante rendering autenticato e browser 21 pagine: Dashboard, ordini in ingresso, spedizioni in corso, storico, due dettagli ordine con stati differenti, prenotazione ritiro, tracking, ritiri raggruppati, listini, gestione Rider, Bilancio, Sospesi, Resoconti, report negozi, elenco messaggi, conversazione, Profilo, impostazioni, storico economico e segnalazioni destinatari.

Incassi, storni e movimenti sono sezioni del Bilancio nell'architettura attuale; statistiche e riepiloghi sono distribuiti tra Dashboard, Resoconti e report negozi. L'endpoint JSON `/movements` è verificato separatamente. Nel portale React è verificata anche la pagina Sospesi con i bundle compilati e le select controllate da React.

## 2. Problemi di spacing trovati e corretti

- Pulsante tariffa a contatto con i tempi del listino: spostato nell'intestazione flessibile della card, separata dai dati tariffari.
- Nome destinatario e badge troppo vicini: contenitore flex con wrapping, gap e nome completo su più righe.
- Intestazioni, azioni e dati nelle card troppo compressi: gap condivisi, griglie adattive e wrapping del testo.
- Link che racchiudevano altri elementi interattivi nelle righe ordine: struttura separata con link sul nome e azioni indipendenti.

Il layout non usa spostamenti fissi basati sulla lunghezza dei nomi.

## 3. Problemi di contrasto trovati e corretti

Uniformate superfici e colori di sidebar, card, tabelle contabili, Sospesi, intestazioni e righe tabella, form, input, pagination, filtri, pulsanti secondari, popup e modali. Rimossi sfondi bianchi residui e colori testuali incompatibili con il tema. Blu per azioni/selezioni, arancione per richiami del brand, superfici opache e bordi leggibili nei temi chiaro e scuro.

Il controllo automatizzato rileva 1.116/1.116 campioni testuali conformi in ciascun tema, con soglia 4,5:1 per testo normale e 3:1 per testo grande. Il campione include contenuti dei pannelli espandibili aperti; esclude superfici con gradienti, icone e contenuti nascosti. Questa misura supera il riferimento del 95% sul campione e non costituisce una certificazione dell'intera UI.

## 4. Componenti modificati

Layout e sidebar, righe ordine, badge affidabilità, card prezzo, workflow e selezione assegnatario, mappa ordine e barra GPS, campi form, pulsanti Blade/React, modali React, dialoghi Blade, filtri e selezione tipologia mittente. I pulsanti modifica sono matite blu senza testo; filtro, stampa, elimina, conferma, messaggi e invio usano le rispettive icone nel componente condiviso. Messaggi diventa un'azione con sola icona e nome accessibile.

## 5. Componenti UI creati o centralizzati

Il modulo `form-popovers.js` contiene una primitive popup comune e renderer per select, select con ricerca, suggerimenti con testo libero, calendario, orario, data/ora e mese. Sono condivisi attivazione, posizionamento, chiusura, focus e sincronizzazione del valore. Il medesimo modulo e i medesimi stili sono presenti nei due repository.

Riutilizzati ed estesi IconButton, badge e selettore tipologia esistenti. I nomi restano leggibili integralmente tramite wrapping, senza richiedere tooltip per recuperare testo troncato.

## 6. Librerie introdotte

Nessuna nuova dipendenza. Verificati i pacchetti installati: Laravel 13, PHP 8.5, Bootstrap 5, React 19 e Vite 8. I test browser usano Node e Chrome senza librerie aggiuntive.

## 7. Motivazione della scelta

Gestionale Blade e portale React richiedono un comportamento comune. Un modulo JavaScript senza dipendenze evita due implementazioni differenti e mantiene i controlli originali per invio dati, eventi React e validazione. Il browser Popover API fornisce il livello di visualizzazione superiore; CSS e posizionamento gestiscono il fallback. I bundle di produzione sono stati ricompilati con successo.

## 8. Date Picker

Calendario italiano con mese visualizzato, precedente/successivo, giorni della settimana, oggi, giorno selezionato e date disabilitate. Rispetta `min`, `max` e `step`. Frecce spostano di un giorno o una settimana, Home/End navigano nella settimana, PageUp/PageDown cambiano mese. È presente anche un selettore mese per i Resoconti.

## 9. Time Picker

Campi personalizzati ore/minuti con conferma esplicita e messaggio di validazione. Rispetta limiti e intervalli del campo originale; orari fuori intervallo o fuori step non sono confermabili. Il selettore data/ora valida il valore combinato. Restano operative le validazioni backend di prenotazione.

## 10. Select e Dropdown

Opzioni nel popup condiviso, selezione visibile, ricerca per elenchi oltre sette opzioni, frecce/Home/End/Enter, focus, opzioni disabilitate e gruppi disabilitati. Stato vuoto esplicito e supporto a caricamento tramite `aria-busy`. I suggerimenti datalist consentono anche testo libero.

Reset, inserimenti dinamici, attributi modificati e aggiornamenti dei dialoghi vengono sincronizzati. Per i form React sono verificati eventi `onChange`, valore controllato, etichette e filtri nell'URL dopo il rendering.

Nel passaggio successivo è stato corretto anche l'aggiornamento delle opzioni mentre il popup è già aperto: nuove etichette e opzioni disabilitate compaiono subito, preservando ricerca e focus. La ricerca viene aggiunta se l'elenco cresce oltre sette opzioni; un elenco svuotato mostra il messaggio dedicato. Il caricamento chiude il popup e disabilita il campo, così come lo stato di sola lettura.

## 11. Popup, overlay e z-index

Su desktop il popup è ancorato al campo e contenuto nel viewport. È montato nel dialogo più vicino o nel body e usa `showPopover()` per entrare nel top layer, evitando clipping e sovrapposizioni con card/sidebar/modali. I livelli CSS di fallback sono menu 1040, popup 1200 e tooltip 1300.

Su mobile il popup diventa un pannello inferiore con overlay e focus trattenuto nel pannello. Escape chiude il picker preservando il dialogo principale; alla chiusura il focus torna al campo. Tab su desktop consente di proseguire nel form. Sono definiti label, ruoli, `aria-expanded`, `aria-controls`, `aria-selected`, `aria-current`, `aria-invalid` e messaggi di stato. Gli stati hover/focus/selected/disabled/error/success usano colori e bordi coerenti.

## 12. Admin come assegnatario operativo

La selezione mostra Rider attivi e l'Admin corrente, identificato come «Tu (Admin)». Un altro Admin, un cliente o un utente inattivo non diventano assegnatari attraverso questa regola. Il ruolo dell'Admin non cambia.

L'Admin assegnato può accettare l'ordine, effettuare il ritiro, avviare e aggiornare il GPS e completare la consegna. Il GPS richiede sempre che l'utente sia l'assegnatario: l'accesso amministrativo all'ordine non consente di inviare posizioni al posto di un altro operatore.

## 13. Modifiche backend

- `OperationalAssignees` centralizza query e regola di validazione, riutilizzate nella select, nell'assegnazione e nell'accettazione ordine.
- La transazione ricontrolla l'assegnatario con lock prima di salvare.
- `User::canOperateDeliveries()` identifica Admin attivi e Rider attivi verificati, rispettando l'accesso Admin già esistente.
- Tracking, snapshot e permesso geolocalizzazione supportano l'Admin operativo.
- Riassegnazione e logout revocano la precedente sessione GPS; i controlli sui cambi di ruolo proteggono anche l'Admin con assegnazioni attive.

Esaminate policy, visibilità ordini, messaggi/notifiche, broadcast, stati e riepiloghi: le logiche esistenti basate su accesso Admin e `rider_id` sono compatibili e sono state mantenute. Non è stata aggiunta una migrazione per l'autoassegnazione.

## 14. Modifiche frontend

Stili comuni nei due progetti, attivazione globale dei picker su Blade e React, focus iniziale dei dialoghi sui controlli visibili, sincronizzazione dei campi compilati programmaticamente e protezione del picker aperto durante i refresh live. IconButton riutilizzato anche per messaggi e modifica prezzo.

I controlli nativi restano nascosti come sorgenti di dati e validazione; non aprono popup di sistema nell'interfaccia arricchita. Se JavaScript non parte, il form originale rimane il fallback.

## 15. Test responsive

21 pagine renderizzate a 375, 768 e 1440 px: 63 combinazioni pagina/viewport senza overflow orizzontale rilevato. Verificate geometricamente separazione tra badge e nome e tra matita prezzo e dati tariffari. Provato il pannello mobile a 375 px e il popup dentro una modale, inclusa chiusura con Escape e navigazione del focus.

## 16. Contenuti e test eseguiti

Fixture con Sara, Pierluigi Di Stefano, Centro Distribuzione Elettrodomestici Napoli Nord, Via Giovanni Battista Pergolesi 145, importo 125,50 €, testi lunghi nei tempi del listino e nelle descrizioni, molti ordini/paginazione, elenchi vuoti e opzioni disabilitate. La fixture dei picker contiene anche «In attesa di conferma amministratore».

Verifiche completate:

- `php artisan test --compact`: **439 test passati, 4.061 assertion**.
- `node --test tests/pickers.browser.test.mjs`: **12/12 passati**, nessuno saltato; comprende calendario, orario, data/ora, mese, select, aggiornamenti delle opzioni a popup aperto, React, modali, responsive e contrasto.
- Portale React: `npm test`, **20/20 passati**; `npm run lint` riuscito.
- `npm run build` riuscito in entrambi i repository.
- `vendor/bin/pint --dirty --format agent` eseguito; `git diff --check` riuscito nei due repository.

La copertura dedicata Admin include ciclo operativo/GPS, riassegnazione, logout, esclusione degli assegnatari non ammessi, divieto GPS per Admin non assegnato e protezione del cambio ruolo.

## 17. Problemi residui e limiti delle verifiche

Nessun errore nei test eseguiti. Le prove browser usano Chrome con viewport emulati: Safari, Firefox, telefoni fisici e lettori di schermo non sono stati verificati direttamente. Il GPS è coperto da test applicativi, non da un percorso reale su dispositivo. La misura di contrasto riguarda i campioni descritti, non ogni possibile stato o contenuto futuro.

I moduli condivisi sono duplicati identici nei due repository e dovranno rimanere sincronizzati. Il selettore orario gestisce ore e minuti, come richiesto dai form correnti; non è un componente per selezionare secondi. Il lavoro è locale, con build pronte; non è stato eseguito un deploy.
