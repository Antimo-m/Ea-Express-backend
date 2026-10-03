# Revisione destinatari e interfaccia EA-Express

Data: 2 ottobre 2026.

La lista destinatari è ora un accesso sintetico alla pagina personale. La revisione del dettaglio ordine e dei componenti condivisi rende le card più compatte, i dati identificabili e le azioni coerenti.

1. **Nuova pagina RecipientDetail.** Creata `resources/views/recipient-incidents/show.blade.php`, con informazioni personali e cronologia separata. È una pagina completa, raggiungibile cliccando la card della persona.

2. **Route dedicata.** `GET /recipient-incidents/{profile}`, nome `recipient-incidents.show`. Riutilizza autenticazione, verifica dell’account e accesso riservato Admin. Il binding recupera il profilo; ID non validi, profili assenti e profili senza episodi restituiscono 404. È presente il ritorno alla lista, anche per i destinatari ripristinati.

3. **Dati mostrati.** Nome completo, telefono, via, civico, CAP, località, provincia e affidabilità. I dati provengono dall’episodio più recente. I campi mancanti sono indicati come non disponibili. Nome e cognome non vengono separati artificialmente: il database conserva un unico nome completo.

4. **Cronologia ordini non ritirati.** La relazione `incidents` del profilo viene interrogata sul server, con ordini e identità cliente caricati anticipatamente. Ordinamento dal più recente, con ID come criterio secondario e paginazione di 20 episodi. Si mostrano data, riferimento ordine, cliente/negozio, destinatario e indirizzo registrati, motivo reale, stato attuale ordine, ultima rettifica e data di rimozione quando presenti. La cronologia include gli episodi rimossi e non include quelli di altre persone. “Apri ordine” e “Storico rettifiche” collegano alle pagine esistenti.

5. **Lista Destinatari non affidabili.** Gli episodi e i form non sono più ripetuti nella lista principale. Ogni card cliccabile mostra nome, località, affidabilità e ultimo episodio attivo quando disponibile. Restano ricerca, filtri, riepiloghi e paginazione.

6. **Telefono rimosso dalla lista.** Il numero compare soltanto nel dettaglio personale e nei relativi form. La ricerca esistente per telefono rimane disponibile.

7. **Posizione Modifica.** La matita è nell’header, a destra del nome, sia nella card personale sia nelle card degli episodi. Anche nelle card tariffa e sicurezza account l’azione di modifica è stata portata accanto al titolo. Il modale personale precisa che rettifica un singolo episodio, senza modificare in blocco gli ordini storici.

8. **Dettaglio spedizione.** Il riepilogo operativo usa un header compatto e dati distribuiti nello spazio orizzontale. Le informazioni della spedizione e la colonna operativa sono organizzate in due stack indipendenti su desktop, evitando spazi vuoti causati da righe condivise. Su tablet/mobile diventano una colonna.

9. **Card più compatte.** Riepilogo operativo, scheda ordine, assegnazione Rider, tariffa e azioni usano altezze naturali e padding contenuto. Rimosse anche le altezze minime superflue per metriche, card statistiche e tariffe tramite gli stili comuni.

10. **Nuova disposizione delle azioni.** Nel workflow il gruppo superiore destro contiene conferma, messaggi e annullamento/rifiuto quando consentiti. Conferma è verde con check; messaggi usa la sola icona; annullamento/rifiuto usa il cestino rosso senza testo esterno. Restano tooltip, aria-label e modali di conferma. Gli imprevisti ulteriori hanno una sezione secondaria ordinata. I pulsanti nell’header sono associati ai rispettivi form tramite `form`, mantenendo i payload originali.

11. **Pulsanti messaggi.** Rimosso il pulsante isolato dalla colonna operativa. Inserita l’icona nella card riepilogo Rider/ordine e accanto alla conferma nel workflow. Anche le card conversazione hanno l’accesso messaggi nell’header.

12. **Contatta cliente.** Eliminato il collegamento testuale “Contatta il cliente” dal riepilogo ordine. La stessa conversazione si apre tramite l’icona messaggi.

13. **Card Rider in arrivo.** La card operativa mostra chiaramente stato, destinatario/affidabilità, cliente/negozio, Rider, indirizzo di consegna e orario previsto quando registrato. La card assegnazione distingue Rider attuale e Rider da assegnare; il check nell’header invia il form esistente.

14. **Componenti condivisi.** Aggiornati `orders.card`, `orders.row`, `orders.workflow`, `orders.recipient-risk`, `ui.icon-button` e il motore `data-tables.js`. Aggiunti `orders.transition-action` per i modali delle transizioni e `recipient-incidents.correction` per i form di rettifica. Gli stili comuni `card-heading`, `card-actions`, `data-label` e `operation-facts` definiscono disposizione e leggibilità.

15. **StatusBadge.** Creato `ui.status-badge`, utilizzabile con lo stato ordine oppure con tono ed etichetta. Centralizza il markup dei badge. Gli stili comuni impongono larghezza adattata al contenuto, allineamento iniziale nelle griglie, padding ridotto, dimensione small, bordi coerenti e testo che può andare a capo entro lo spazio disponibile.

16. **Badge corretti.** Il componente è utilizzato in storico ordini, spedizioni in corso, dettaglio ordine, dashboard, ritiri, messaggi/notifiche, destinatari, sospesi, bilancio, listini, statistiche clienti, resoconti, impostazioni e tracking pubblico. La regola CSS condivisa copre anche i badge creati dinamicamente e quelli di conteggio.

17. **Etichette dei dati.** Le righe ordine identificano cliente, destinatario, riferimento, percorso, colli, data, fascia oraria, stato e Rider. Etichette aggiunte anche alle card dashboard, ritiri, conversazioni, utenti, tariffe, contanti, incassi, sospesi, statistiche e cronologia ordine. Le tabelle usano intestazioni desktop e label ricavate dalle stesse intestazioni quando diventano liste/card; anche le tabelle dichiarate statiche ricevono queste label.

18. **Responsive.** Card e azioni consentono wrapping ordinato e nomi lunghi senza overflow. Le card destinatario conservano la matita in alto a destra. Nelle tabelle statiche che diventano card, la colonna Azioni viene posizionata nell’angolo superiore destro. Testati 375, 768, 1280 e 1440 px con nomi come Pierluigi Francesco Di Stefano, Centro Distribuzione Elettrodomestici Napoli Nord e Giovanni Battista Esposito, oltre a indirizzi e descrizioni lunghi.

19. **Palette funzionale.** Blu per modifica e comunicazione; arancione per azioni operative, apertura ordine, filtri e nuove operazioni; verde per conferme e successi; rosso per eliminazione, annullamento e non affidabilità; neutro per storico e azioni secondarie. “Apri ordine” ha icona e bordo arancioni; “Storico rettifiche” ha icona cronologia e bordo neutro. Palette e contrasto sono compatibili con i temi chiaro e scuro esistenti.

20. **Verifiche effettuate.**
    - 74 test backend superati, 562 asserzioni complessive: RecipientDetailTest, OrderActionLayoutTest, WorkspaceRevisionTest, WorkspacePresentationTest, RiderAssignmentTest, AdminOperationalAssignmentTest, RiderTrackingTest, WorkflowSecurityTest e PaymentIntegrityTest.
    - Copertura della nuova pagina: autenticazione, accesso negato ai ruoli non autorizzati, ID inesistenti/non validi, profili vuoti, isolamento della cronologia, paginazione, output HTML escapato, rettifiche/rimozioni, audit e conservazione dei dati ordine.
    - 15 verifiche browser superate con Chrome: modali, conferma rimozione, focus, associazione dei pulsanti ai form, conservazione delle note, annullamento, controlli condivisi e responsive.
    - Nessuno sforamento orizzontale nelle pagine campionate a 375/768/1280/1440 px; nessun badge allargato rispetto al contenuto nei casi a riga singola.
    - Contrasto: 1477/1477 campioni di testo superano le soglie del test in ciascuno dei due temi. È una verifica sui contenuti campionati, non una certificazione dell’intero sito.
    - Build Vite completata, viste Blade compilate, Pint superato e `git diff --check` pulito.
    - Schermate desktop/tablet/mobile salvate in `/private/tmp/ea-recipient-ui` e controllate per dettaglio ordine e dettaglio destinatario.

## Continuità della logica applicativa

Nessuna migrazione o nuova dipendenza. Assegnazione Rider, tracking, ordini, messaggi, calcolo affidabilità, autorizzazioni e audit riutilizzano la logica esistente. Dopo una rettifica si torna al profilo corrente dell’episodio, anche se la correzione dell’identità lo ha collegato a un altro profilo. Le segnalazioni rimosse restano nella cronologia; il badge affidabilità considera solamente gli episodi attivi.
