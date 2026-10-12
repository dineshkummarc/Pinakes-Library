# Verifica dei controlli admin — 12 ottobre 2026

La #471 uniforma intestazioni, percorsi di navigazione, pulsanti e controlli delle tabelle attraverso `workspace-header.php` e `workspace-ui.css`. La verifica riguarda anche i plugin installati, non soltanto le pagine segnalate.

## Correzioni

- Intestazioni: stessa posizione, font, margini e riga di navigazione. Il pulsante Indietro conserva il collegamento contestuale e riceve padding 10 × 18 px e altezza minima 44 px.
- Breadcrumb: migrati tutti i 68 percorsi esistenti alla stessa lista testuale Home / genitore / pagina corrente, con separatore CSS e `aria-current`. Quelli di Prestiti e dettaglio Genere sono ora dentro l'intestazione. Il componente aggiunge il percorso alle pagine che ne erano prive.
- Pulsanti: utility e classi semantiche (`btn-primary`, `btn-secondary`, `ui-button`, `bc-btn`) condividono dimensioni, font e padding. Le azioni dell'intestazione sono pillole; nei form il raggio è 12 px. I menu conservano righe piene, senza assumere la forma dei pulsanti.
- Azioni a icona: normalizzati 74 controlli nelle viste, comprese le tabelle costruite in JavaScript e le finestre dei plugin. Controlli quadrati da 44 px; tooltip e nomi accessibili conservati o aggiunti. I chip rimovibili dei filtri conservano il proprio comportamento compatto.
- Articoli: tab autonome/spoglio con padding condiviso, altezza 44 px e stato selezionato visibile; collegamenti e filtri mantenuti.
- Lingue: tabella leggibile, barra di completamento nativa di larghezza stabile, percentuale e numero di chiavi separati, stati distinguibili e azioni coerenti. Nessuna modifica al salvataggio o alla gestione delle lingue.
- Arricchimento: lo switch ha una propria geometria, separata dai pulsanti. La maniglia resta dentro il tracciato; area di attivazione 52 × 44 px. Checkbox e radio mantengono l'aspetto nativo e il focus visibile.
- Scanner: il campo codice può restringersi, il pulsante Scansiona no; icona e testo rimangono allineati. Il nome accessibile è presente anche quando il testo viene nascosto su telefono.
- Tabelle: 24 px di spazio sopra riepilogo/paginazione, pulsanti da 44 px, stato corrente evidente e disposizione flessibile su telefono. Regole comuni per DataTables e paginazione lato server.
- Sidebar: etichette nere nella modalità chiara, stesso font e peso per voci principali e plugin (500 normale, 600 selezionata); sottovoci con rientro esterno ridotto da 26 a 12 px e padding interno ridotto.

## Verifica eseguita

Inventario completo dei 97 template staff che usano il componente condiviso, incluse le viste annidate di Archivi, Book Club e FRBR. Nessun breadcrumb precedente è rimasto esterno o con un altro formato.

Nel browser reale:

- Tutte le 38 destinazioni della sidebar, incluse le 12 sezioni delle impostazioni, a 1512 px e a 390 px.
- Altre 38 URL di schede e form, comprese creazione/modifica, importazioni, CMS e plugin, su desktop.
- 10 form rappresentativi a 390 px e 10 pagine con tabelle, scanner e impostazioni a 320 px.
- Una sola intestazione e un solo breadcrumb con pagina corrente per ogni URL controllata; nessuno scorrimento orizzontale del documento. Le tabelle larghe mantengono lo scorrimento dentro il proprio contenitore.
- Titoli allineati a x=316 px e font 42 px in tutte le destinazioni desktop della sidebar; 32 px su telefono.
- Passaggio alla pagina 2 della tabella Editori: riepilogo aggiornato, comandi da 44 px, raggio 12 px, spazio sopra il riepilogo superiore a 24 px.
- Scansiona: centro dell'icona e della scritta allineati; stessa famiglia di controlli in creazione prestito e restituzione rapida.
- Desiderata: font 15 px e peso 600 nello stato selezionato, identici alla voce Libri selezionata. Etichette di entrambe nere nella modalità chiara.

Build frontend con Node 22 ed ESLint superati. Asset compilati aggiornati; una seconda build ha prodotto gli stessi hash per tutti gli asset tracciati. PHPStan completo: nessun errore. Lint PHP su tutte le viste modificate e suite `frontend-partials.unit.php` (73 controlli comportamentali) e `frontend-layout-variants.unit.php` superati. `workspace-controls-2026.spec.js` aggiunge la regressione CI sulle destinazioni reali della sidebar a desktop/telefono, scanner, paginazione funzionante, switch, azioni Lingue e navigazione delle tab Articoli. La policy CI include automaticamente la nuova suite negli shard E2E.

Le verifiche visive sono di sola lettura: nessun form salvato, nessuna lingua o impostazione modificata, nessun prestito registrato e nessun accesso alla fotocamera. Non costituiscono una prova di ogni possibile combinazione di dati o permessi. Nessun merge eseguito.

## Findings successivi alla revisione

Le regole condivise di layout dei pulsanti ora hanno specificità zero: le utility `hidden`, `md:inline-flex`, `justify-between` e `text-left` conservano il comportamento previsto. Verificati Prestiti a 390 px (nessun duplicato) e desktop, il ciclo Modifica/Annulla del dettaglio Genere e l'allineamento della sezione Formato CSV Dettagliato. I toggle delle chiavi API di core e Discogs mantengono la trasformazione di centratura anche in hover; quello core riceve il nome accessibile già tradotto «Mostra/nascondi chiave». Misurati 44 × 44 px e stesso centro verticale dell'input prima e durante hover. Nessuna configurazione salvata.
