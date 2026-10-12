# Verifica dell’installazione — 12 ottobre 2026

## Controlli superati

- 58 libri con ISBN reale, copertina valida e fonte bibliografica; 8 desiderata esclusi dalla consistenza ordinaria.
- 22 articoli pubblici, tutti con immagine, crediti, abstract sintetico e collegamento alla fonte originale.
- 3 testate, 10 fascicoli e relative annate; 4 unità archivistiche pubbliche. Vecchi dati di prova rimossi dalle viste pubbliche.
- Nessuna segnatura `DEMO`; scaffali/mensole registrati, inventari ordinari e segnatura digitale `DIG-MAL`.
- Immagini/file presenti e validi sul server. 103 risorse HTTP controllate: nessun errore con le normali intestazioni del browser. Tutte le 12 tracce rispondono con `206 Partial Content` e `audio/mpeg`.
- PDF: lettore PDF.js chiaro self-hosted, senza CDN, in lightbox con sfondo chiaro, semitrasparente e sfocato. Verificati desktop, telefono (390 × 844) e orientamento orizzontale (844 × 390), margini laterali adattivi e pagina singola. Navigazione, zoom e ricerca funzionano (66 occorrenze di “anarchia” nel PDF Malatesta); Esc funziona anche con focus dentro il PDF, restituisce il focus al pulsante e libera le risorse del documento. Superati 67 controlli PHP e 7 verifiche HTTPS su MIME, accesso dallo stesso dominio e richieste parziali PDF (206); verificato anche il fallback nativo per un PDF da un secondo dominio senza CORS. Worker, font, WASM e traduzioni sono locali; tutti i 408 file del lettore sono tracciati e il motore è incluso negli archivi Git.
- Audio Malatesta riprodotto e messo in pausa, durata della prima parte 2033,88 secondi, nessun errore del player.
- Catalogo recente: 50 libri prima dei 22 articoli, ordinati prima della paginazione. Preferenza di questa installazione (`catalog.books_first=1`); gli ordinamenti espliciti per titolo, autore e data crescente restano misti.
- Titoli a 18 px dalla copertina, sotto l’ombra del libro; badge di disponibilità e wishlist sovrapposti in basso dentro la copertina. Nessuno spazio aggiunto tra immagine e titolo; verificati a 1512 px e a 390 px.
- Ricerca nel catalogo, navigazione testata/fascicolo/articolo, desiderata e club di lettura verificati nel browser.
- Vista mobile 390 × 844: larghezza del documento 390, nessuno scorrimento orizzontale nella sezione desiderata.
- OAI-PMH Identify/ListMetadataFormats, SRU explain/searchRetrieve e ResourceSync: HTTP 200, XML ben formato, namespace attesi, nessun errore nel campione. Controllo operativo, non una certificazione completa dei protocolli.
- Esportazioni MARCXML di un articolo e RIS di libro/articolo: HTTP 200, struttura XML/RIS riconosciuta.

## Errori trovati e stato

| Problema | Evidenza | Stato |
| --- | --- | --- |
| Scraping Pro confonde autore e altri crediti | ISBN 9788833020631: Andrea Brizioli è curatore, non l’autore principale. ISBN 9788833021973: Gomez e Senta firmano saggi aggiunti. | Dati della raccolta corretti dalle fonti elèuthera; logica generale del plugin ancora da correggere. |
| Scraping Pro restituisce paginazioni discordanti | ISBN 9788833020679: 329 importate, 392 dall’editore; altre discrepanze in sette schede. | Metadati delle edizioni elèuthera riallineati al catalogo editoriale. |
| Schema Archivi incompleto | Plugin 1.5.2 senza `archival_units.published`. | `ensureSchema()` del plugin eseguito con successo; da approfondire perché la migrazione non era partita durante l’aggiornamento. |
| Ruoli appiattiti nel campo autore dei desiderata | Alice Zaffaroni compariva nel campo autore accanto a Tomás Ibáñez pur avendo ruolo `traduttore`. | Allineati lista, precompilazione donazione e dettaglio API: il campo autore contiene solo `principale`/`co-autore`, ordinati. Il dettaglio API conserva tutti i contributori con ruolo distinto, come richiesto dalla [issue #237](https://github.com/fabiodalez-dev/Pinakes/issues/237). Persone e relazioni bibliografiche restano conservate. |
| Disponibilità del solo ebook fuorviante | Libro 144, 0 copie fisiche: “Non disponibile oggi” e prenotazione, pur con PDF/audio consultabili. | Corretto nell’app Android nella [PR #44](https://github.com/fabiodalez-dev/Pinakes-Android/pull/44), con azioni digitali mantenute e nessun prestito fisico per zero copie. La vista web del dettaglio resta da allineare. |

Verifica della patch: lint PHP, PHPStan livello 5, 63 controlli d’integrazione delle raccolte/API e 5 controlli sui faceti desiderata superati. Le suite sono state eseguite su un server MySQL locale separato con database usa e getta, senza modificare un database di installazione. Il caso bibliografico reale è stato controllato anche online con chiamate di sola lettura: lista e dettaglio riportano Tomás Ibáñez; Alice Zaffaroni è conservata nei contributori con ruolo `traduttore`. Il contratto OpenAPI documenta il nuovo campo.

Le correzioni Android sono raccolte nella [PR #44](https://github.com/fabiodalez-dev/Pinakes-Android/pull/44): disponibilità digitale, pulizia PDF in errore/cancellazione/chiusura, trasporto audio e PDF con verifica della policy HTTP anche sui redirect, e ruoli dei contributori localizzati. Superati 200 test unitari debug, 200 release e 22 test su un emulatore Android 15 dedicato; lint senza errori (101 avvisi) e build release con R8 riuscita. Nessun rilascio o merge eseguito.
