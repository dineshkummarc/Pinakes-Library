# Verifica dell’installazione — 12 ottobre 2026

## Controlli superati

- 58 libri con ISBN reale, copertina valida e fonte bibliografica; 8 desiderata esclusi dalla consistenza ordinaria.
- 22 articoli pubblici, tutti con immagine, crediti, abstract sintetico e collegamento alla fonte originale.
- 3 testate, 10 fascicoli e relative annate; 4 unità archivistiche pubbliche. Vecchi dati di prova rimossi dalle viste pubbliche.
- Nessuna segnatura `DEMO`; scaffali/mensole registrati, inventari ordinari e segnatura digitale `DIG-MAL`.
- Immagini/file presenti e validi sul server. 103 risorse HTTP controllate: nessun errore con le normali intestazioni del browser. Tutte le 12 tracce rispondono con `206 Partial Content` e `audio/mpeg`.
- PDF aperto e renderizzato nel lettore incorporato; audio Malatesta riprodotto e messo in pausa, durata della prima parte 2033,88 secondi, nessun errore del player.
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
| Traduttore mostrato come autore nei desiderata | Alice Zaffaroni compariva accanto a Tomás Ibáñez pur avendo ruolo `traduttore`. | Corrette `wanted()` e `donationPrefill()`: solo ruoli `principale`/`co-autore`, ordinati. Patch locale e online; sintassi PHP valida e controllo sul caso reale superato. |
| Disponibilità del solo ebook fuorviante | Libro 144, 0 copie fisiche: “Non disponibile oggi” e prenotazione, pur con PDF/audio consultabili. | Ancora aperto: distinguere disponibilità digitale e copie fisiche, senza inventare una copia. |

Il test locale `desiderata-search-facets.unit.php` non è eseguibile con le credenziali disponibili: il suo database usa e getta non può essere creato. Non è stato forzato su un database di installazione. Verifica della patch effettuata invece con lint PHP e chiamata di sola lettura al metodo reale `wanted()` sul record interessato.

Il disco locale ha esaurito temporaneamente lo spazio; sono stati rimossi soltanto PDF di ricerca scaricabili di nuovo. L’utente ha successivamente liberato spazio. Nessun contenuto dell’installazione online è stato perso.
