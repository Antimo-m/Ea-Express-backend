# Information design dei filtri del Gestionale — 3 ottobre 2026

Intervento limitato al Gestionale Laravel. Le modifiche già presenti nel workspace sono state mantenute. Nessuna dipendenza aggiunta.

## Inventario e classificazione

L’inventario comprende tutte le toolbar GET di filtraggio del Gestionale. I GET di stampa etichette sono azioni e rimangono separati. Dashboard, tracking, messaggi e impostazioni non espongono ulteriori toolbar di ricerca nei template attuali.

| Pagina | Filtri presenti prima | Primary dopo | Secondary dopo | Redundant / Remove |
| --- | --- | --- | --- | --- |
| In entrata | Servizio, ricerca, zona, urgenza, tipo mittente | Ricerca, zona, urgenza | Servizio, tipo mittente | Nessun filtro funzionale eliminato; stato implicito “ricevuto”, quindi nessuna select aggiunta |
| Spedizioni in corso | Servizio, ricerca, zona, urgenza, tipo mittente | Ricerca, stato, rider | Zona, servizio, urgenza, tipo mittente | Servizio e tipo mittente rimossi dalla riga primaria, conservati nel pannello |
| Storico | Servizio, ricerca, zona, urgenza, Dal, Al, stato, tipo mittente | Ricerca, periodo, stato, cliente | Rider, zona, servizio, urgenza, tipo mittente | Date sempre esposte rimosse: accesso tramite Periodo |
| Ritiri raggruppati | Oggi, Domani, Arretrati separati dalla toolbar; data | Viste temporali e data | Nessuno | Area temporale separata eliminata e incorporata nella stessa toolbar |
| Bilancio | Preset, Dal, Al, cliente | Periodo e cliente | Nessuno | Dal/Al sempre visibili rimossi; nessuna select Anno separata |
| Statistiche Clienti | Ricerca, preset, Dal, Al, ordinamento, direzione ordinamento, stato | Ricerca, periodo, stato | Tipo mittente, ordinamento, direzione ordinamento | Date sempre esposte e ordinamenti primari rimossi; funzioni conservate |
| Resoconti | Mese | Mese | Nessuno | Nessun filtro aggiunto; trigger mese ridimensionato |
| Listini | Ricerca, area, stato per amministratore | Ricerca, stato, area | Nessuno | Nessuno; lo stato rimane riservato all’amministratore |
| Sospesi | Ricerca, cliente, stato, ordinamento; direzione sotto i KPI; eventuale ID da link contestuale | Ricerca, stato, cliente | Direzione e ordinamento | Navigazione direzione separata eliminata; ID contestuale mantenuto e visibile come chip |
| Destinatari non affidabili | Ricerca, stato delle segnalazioni, sotto i KPI | Ricerca, segnalazioni | Nessuno | Nessuna zona aggiunta: la ricerca esistente copre il comune e lo stato è necessario per i ripristinati |
| Rider per Zona | Data; Per Rider/Per Zona vicino alle liste | Data e raggruppamento | Nessuno | Raggruppamento isolato eliminato: ora accanto alla data |
| RiderActivityDetail | Data; zona contestuale in una spiegazione separata | Data | Zona contestuale mantenuta come chip rimovibile | Link per rimuovere la zona spostato nella filter area tramite chip |

Cliente e rider negli elenchi ordini sono ora anche filtri effettivi della query, limitata dalla visibilità dell’utente. La ricerca ordini comprende riferimento, negozio, destinatario e nome dell’account; zona comprende sia comune sia zona operativa salvata. Non sono state cambiate le regole contabili o di calcolo statistico.

## Composizione per pagina

Reset è sempre l’ultima azione della toolbar. La lente “Applica filtri” precede il reset su desktop e tablet. “Altri filtri” mostra il numero di filtri secondari attivi. Su mobile la finestra riunisce primari e secondari e ha Azzera/Applica; la ricerca resta fuori. I periodi centrali di analisi e le date operative restano visibili.

| Pagina | Desktop 1440 / 1280 | Tablet 1024 / 768 | Mobile 375 | Motivazione UX |
| --- | --- | --- | --- | --- |
| In entrata | Una riga: ricerca → zona → urgenza → altri → applica → reset | Ricerca a larghezza disponibile sopra; zona e urgenza sotto, azioni a destra | Ricerca, poi Filtri con conteggio e reset; zona/urgenza/servizio/tipo mittente nel pannello | Individuare rapidamente richieste da gestire per territorio e priorità |
| Spedizioni in corso | Una riga: ricerca → stato → rider → altri → applica → reset; stampa nell’intestazione | Ricerca sopra; stato/rider e azioni sotto | Ricerca e Filtri; stato/rider prima dei secondari nel pannello | Stato indica il passo operativo; rider individua il responsabile |
| Storico | 1440: una riga; 1280: ricerca sopra, Periodo/Stato/Cliente e azioni sotto | Ricerca sopra, periodo/stato/cliente sotto; intervallo aperto disposto in una colonna nel gruppo su tablet stretto | Ricerca e Filtri; Periodo e relativo intervallo prima di stato e cliente | Il recupero di ordini chiusi parte dall’identità e dal periodo, poi dall’esito e dall’account |
| Ritiri raggruppati | Preset temporali in un gruppo; data/applica/reset nel gruppo successivo | Stessa gerarchia, data compatta | Preset, data e azioni su righe deliberate | Oggi/Domani/Arretrati sono viste operative; la data è un accesso diretto all’agenda |
| Bilancio | Preset/Personalizzato e intervallo corrente sopra; cliente/applica/reset nel gruppo sotto | Stessi due gruppi, con Dal/Al visibili soltanto aprendo Personalizzato | Preset, Personalizzato e periodo corrente visibili; Cliente nel pannello Filtri | Il periodo determina il significato degli importi e precede la selezione del cliente |
| Statistiche Clienti | Ricerca a sinistra e gruppo periodo a destra; stato e azioni nella riga successiva | Ricerca, periodo, poi stato/azioni: tre righe esplicite | Ricerca; periodo visibile; stato, tipo mittente e ordinamenti nel pannello | Ricerca dell’account e finestra di analisi sono distinguibili; l’ordinamento non compete con la selezione dati |
| Resoconti | Mese compatto, applica, reset adiacenti | Stessa riga compatta | Mese visibile; applica/reset nella riga successiva | Un solo mese di riferimento, senza filtri cliente non supportati dalla pagina |
| Listini | Ricerca → stato → area → applica → reset | Ricerca sopra; stato/area e azioni sotto | Ricerca; stato/area nel pannello Filtri | Lo stato distingue tariffe operative e archivio, l’area restringe la copertura |
| Sospesi | Ricerca → stato → cliente → altri → applica → reset, prima dei KPI | Ricerca sopra; stato/cliente e azioni sotto | Ricerca; stato/cliente/direzione/ordinamento nel pannello | Prima il saldo aperto o saldato, poi l’account; direzione e ordinamento sono raffinamenti |
| Destinatari non affidabili | Ricerca → segnalazioni → applica → reset, prima dei KPI | 1024: una riga; 768: ricerca sopra e segnalazioni/azioni sotto | Ricerca e Filtri prima dei KPI; stato nel pannello | Ricerca immediata della persona, con distinzione essenziale fra segnalazioni attive e ripristinate |
| Rider per Zona | Data → Per Rider/Per Zona → applica → reset, prima di KPI e mappa | Stessa sequenza compatta | Data, raggruppamento, azioni su righe definite; nessuna altezza aggiuntiva introdotta dalla larghezza della data | La giornata è centrale; il raggruppamento controlla la lettura della medesima attività |
| RiderActivityDetail | Data/applica/reset in un gruppo; eventuale chip zona sotto | Stessa struttura | Data e azioni in due righe; zona rimovibile nella stessa area | Il rider è già definito dalla pagina; non serve un altro selettore rider |

## Sistema condiviso

- `ui.filters`: slot ricerca e periodo, gruppo primario, slot secondari, azioni finali, chip e finestra dedicata. Varianti semantiche `data`, `analysis`, `date`.
- `ui.filter-select`: selezione con etichetta esplicita sul trigger, valore di default e classe dimensionale.
- `ui.filter-period`: preset, Personalizzato, intervallo richiudibile e riepilogo delle date analizzate; conservazione di anno/mese storico durante l’applicazione di altri filtri.
- `ui.period-options`: preset adiacenti; Anno attivo anche per un anno precedente. Cambio periodo conserva filtri dati e azzera tutte le paginazioni indipendenti.
- `ui.searchable-select`: classe Account condivisa; rider più stretto; ricerca nelle opzioni esistente conservata.
- `filter-toolbar.js`: pannello desktop ancorato e finestra modale mobile; spostamento degli stessi controlli, senza duplicare nomi/valori; Apply conserva tutti i filtri, Escape/chiusura ripristinano la bozza; ritorno del focus, navigazione da tastiera e popup calendario dentro la finestra.
- `form-popovers.js`: trigger espliciti “Stato: Tutti”, “Cliente: Tutti”, “Data: …” soltanto nelle toolbar; comportamento dei form conservato.
- `experience.css`: composizioni a griglia e breakpoint intenzionali, con gruppi senza wrap casuale.

## Dimensioni e gerarchia

| Tipologia | Standard |
| --- | --- |
| Altezza input, picker, pulsanti, applica/reset | 38 px |
| Search desktop | 260–320 px; intera riga quando previsto dai breakpoint |
| Stato | 150 px; 130 px su tablet stretto |
| Cliente / Account | 220 px; 200 px su tablet stretto |
| Rider | 180 px; 160 px sui breakpoint più stretti |
| Zona / Area / Servizio | 180 px |
| Data operativa | 160 px |
| Dal / Al | 150 px ciascuno; una colonna nel gruppo Storico su tablet stretto |
| Mese | 220 px |
| Preset e Personalizzato | Larghezza del contenuto |
| Reset / Applica con icona | Cerchio di 38 px |
| Spaziatura | 4 px fra preset; 8 px fra controlli; 16 px fra gruppi; 12 px fra righe; 24 px prima del contenuto |

Ordine globale: Ricerca → Periodo → Stato → Cliente → Rider → Zona → Servizio/Tipologia → Ordinamento → Altri filtri → Applica → Reset. Urgenza è primaria in In entrata per la sua utilità operativa. Le classi dimensionali sono condivise, senza larghezze copiate nei template delle pagine.

Le etichette rimangono accessibili ai lettori di schermo. Le etichette sopra il controllo sono visibili nel pannello, dove i campi costituiscono un vero form. I valori lunghi hanno ellissi nel trigger e testo completo nell’aria-label e nel menu.

## Stato, URL e paginazione

I form restano GET. Il cambio filtri ricomincia dalla prima pagina; i paginator conservano le query string. I filtri non sono memorizzati in uno stato separato dalle URL. Refresh e navigazione browser ripristinano i valori renderizzati dal server.

I chip sono mostrati soltanto per filtri applicati diversi dai default. Ogni chip elimina il proprio parametro e i parametri di paginazione, conservando gli altri filtri. Il periodo personalizzato è un unico chip che rimuove Dal/Al insieme e il modo `custom`, evitando intervalli incompleti. I preset temporali non generano chip di date nascoste.

La paginazione resta separata sotto le liste. La stampa dei risultati in corso utilizza gli stessi filtri effettivi della lista, compresi rider, cliente e servizio.

## Verifiche e problemi corretti

Render server delle vere view Blade, asset di produzione e JavaScript reali in Chrome headless; dati di test realistici e lunghi generati dalla suite Laravel con database isolato. La verifica non usa un account o un database di produzione.

Verificate tutte le dodici pagine della tabella a **1440, 1280, 1024, 768 e 375 px**, incluse apertura dei filtri mobile, gerarchia dei controlli e altezze reali. Sono disponibili 60 screenshot di pagina e cinque tavole di confronto, oltre ai render del pannello filtri.

Problemi rilevati durante la revisione e corretti:

1. Ricerca dopo il servizio negli ordini e troppi controlli primari: ordine corretto, secondari raccolti.
2. Reset prima di Applica: reset ora ultima azione.
3. Bilancio/Statistiche con date sempre esposte: intervallo dietro Personalizzato, periodo analizzato sempre leggibile.
4. Selettore mese troppo largo: dimensione condivisa 220 px e label breve “Mese”.
5. Spazio verticale mobile fra data e raggruppamento Rider: rimossa l’interpretazione della larghezza flex come altezza della colonna.
6. Direzione Sospesi sotto i KPI: spostata fra i secondari.
7. Ricerca Destinatari dopo i KPI: toolbar portata immediatamente sotto l’intestazione.
8. Stampa etichette in una card sotto i filtri: azione nell’intestazione; risultati filtrati come semplice didascalia.
9. Perdita dell’anno precedente nelle Statistiche cambiando altro filtro: anno conservato; disabilitato quando si sceglie un periodo personalizzato.
10. Rimozione di una sola data da un intervallo custom: chip unico e rimozione atomica dell’intervallo.
11. Picker date con etichette operative troppo lunghe: label “Data”, valore e icona in un controllo compatto.
12. Larghezze desktop ereditate dai campi nel pannello mobile: controlli del pannello portati a tutta larghezza.
13. Toolbar con pochi controlli su due righe a 1280 pur avendo spazio: composizione specifica su una riga; Storico conserva il passaggio intenzionale a due righe.

Controlli eseguiti:

- Build Vite, compilazione/cache Blade, controllo sintassi JavaScript, `git diff --check`, Laravel Pint.
- **16 test Laravel passati, 161 assertions**, sulle suite filtri/query, presentazione, periodi annuali, interfaccia operativa, revisione e azioni ordini.
- **22 test browser passati** (incluso il test contenitore); altezza comune 38 px; ricerca prima dei controlli; reset ultimo; geometrie alle cinque larghezze; popup e calendario; Escape, focus, cancellazione bozza e payload Applica; badge e chip di filtri attivi; mantenimento dell’anno storico; eliminazione del periodo custom; ricerca nelle select con 5, 50 e 200 account; contenuti e rider numerosi; assenza di overflow.
- Contrasto: tutti i 2624 campioni di testo analizzati soddisfano le soglie nei temi chiaro e scuro.

## Render di revisione

Le tavole includono le dodici pagine nella stessa sequenza della tabella; i file originali permettono una revisione alla risoluzione completa.

- [Desktop 1440](/private/tmp/ea-filter-screenshots/review-1440.png)
- [Laptop 1280](/private/tmp/ea-filter-screenshots/review-1280.png)
- [Tablet landscape 1024](/private/tmp/ea-filter-screenshots/review-1024.png)
- [Tablet 768](/private/tmp/ea-filter-screenshots/review-768.png)
- [Mobile 375](/private/tmp/ea-filter-screenshots/review-375.png)
- [Pannello filtri mobile](/private/tmp/ea-filter-screenshots/filter-panel-375.png)
- [Filtri attivi mobile](/private/tmp/ea-filter-screenshots/filters-active-375.png)
- [Pannello filtri desktop](/private/tmp/ea-filter-screenshots/filter-panel-1440.png)

Comando riproducibile: `EA_UI_SCREENSHOTS=/private/tmp/ea-filter-screenshots node --test tests/pickers.browser.test.mjs` dopo `npm run build`. La cartella screenshot deve esistere.
