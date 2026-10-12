---
name: Pinakes, catalogo e spazio di gestione
description: Carta bianca, inchiostro caldo e interazioni tangibili, con accenti magenta misurati.
colors:
  primary: "#d70161"
  ink: "#1b1720"
  ink-secondary: "#2d2630"
  text: "#5d5662"
  muted: "#6b6470"
  page: "#ffffff"
  navigation: "#faf8f9"
  soft: "#f1edef"
  line: "#e2dce0"
typography:
  headline:
    fontFamily: "Fraunces, Georgia, serif"
    fontSize: "42px"
    fontWeight: 500
    lineHeight: 1.12
    letterSpacing: "-0.025em"
  body:
    fontFamily: "Geist, system-ui, sans-serif"
    fontSize: "15px"
    fontWeight: 400
    lineHeight: 1.55
  navigation:
    fontFamily: "Geist, system-ui, sans-serif"
    fontSize: "15px"
    fontWeight: 500
    lineHeight: 1.3
  navigation-context:
    fontFamily: "Geist, system-ui, sans-serif"
    fontSize: "12px"
    fontWeight: 400
    lineHeight: 1.4
  label:
    fontFamily: "Geist, system-ui, sans-serif"
    fontSize: "13px"
    fontWeight: 500
    lineHeight: 1.4
rounded:
  control: "12px"
  panel: "18px"
  pill: "999px"
spacing:
  compact: "8px"
  control: "12px"
  mobile-gutter: "16px"
  panel: "24px"
  desktop-gutter: "36px"
components:
  button-primary:
    backgroundColor: "{colors.ink}"
    textColor: "{colors.page}"
    rounded: "{rounded.control}"
    height: "44px"
    padding: "12px 20px"
  button-secondary:
    backgroundColor: "{colors.page}"
    textColor: "{colors.ink}"
    rounded: "{rounded.control}"
    height: "44px"
  navigation-current:
    backgroundColor: "{colors.page}"
    textColor: "{colors.ink}"
    rounded: "{rounded.control}"
    height: "60px"
  input:
    backgroundColor: "{colors.page}"
    textColor: "{colors.ink}"
    rounded: "{rounded.control}"
    height: "44px"
---

# Design System: Pinakes

## Overview

**Creative North Star: "La scaffalatura di Pinakes"**

Un bibliotecario lavora al banco, in una sala luminosa, e passa spesso fra catalogo, prestiti e plugin. Il lettore consulta lo stesso catalogo sul telefono. Le pagine restano bianche, come richiesto; la profondità nasce dai livelli, dalla tipografia e dal comportamento delle superfici.

Il riferimento concreto è il frontend pubblico esistente: `public/assets/pinakes-2026.css`, `interface-tokens.css`, `fonts/fonts.css` e `app/Views/frontend/layout.php`. Il pubblico usa copertine con dorso e ombre, Fraunces per le intestazioni, Geist per i controlli, pulsanti morbidi e piccoli accenti derivati dal tema. La gestione riprende questa identità con una densità adatta al lavoro. La stessa gerarchia deve rimanere riconoscibile passando fra tutte le pagine e le estensioni.

**Key Characteristics:**
- Pagina bianca, navigazione leggermente distinta, superfici sollevate solo quando sono interattive.
- Sidebar fissa di 280px, voci da 15px, contesto da 12px, scroll indipendente.
- Testata comune, azioni contestuali, filtri leggibili, contenuto operativo.
- Movimento breve su hover, focus, selezione e apertura del menu; nessuna attesa imposta dal caricamento della pagina.

## Colors

### Primary
Il magenta Pinakes identifica la sezione corrente, il focus, i piccoli indicatori e i dettagli di un'azione. Il colore effettivo continua a provenire dal tema e dalle coppie di contrasto di `ThemeColorizer`.

### Neutral
Inchiostro e neutri caldi sono quelli estratti dal frontend pubblico. La navigazione ha un tono carta quasi bianco; dati e pagine conservano il bianco. Il calendario rimane neutro.

**The Accent Rule.** Il magenta occupa piccole aree: un punto, un'icona, il bordo di focus, una breve etichetta. Mai una sidebar rosa o un calendario rosa.

## Typography

Fraunces firma il nome del prodotto e i titoli di pagina. Geist serve navigazione, testo, dati, filtri e azioni. I font sono locali.

Titoli di gestione da 42px (32px su telefono); titoli di sezione da 20px. Sidebar da 15px con descrizioni da 12px: niente testo operativo da 10–11px. Testo di contesto da 15px, controlli e tabelle da 13–14px. La prosa non supera 75ch.

**The Readable Navigation Rule.** La densità si ottiene organizzando il contenuto, mai rimpicciolendo le voci del menu.

## Elevation

Tre livelli: pagina bianca, navigazione su carta tenue, controllo o superficie interattiva sollevata. Il frontend pubblico offre il riferimento per copertine e ombre morbide. Le tabelle mantengono righe ferme; un pannello non interattivo non si solleva al passaggio del puntatore.

Ombra di controllo: `0 2px 3px rgb(27 23 32 / .04), 0 8px 18px -12px rgb(27 23 32 / .24)`. Ombra di navigazione: `0 1px 2px rgb(27 23 32 / .04), 0 6px 16px -10px rgb(27 23 32 / .18)`. Livelli sovrapposti: `0 16px 40px -16px rgb(27 23 32 / .25)`.

**The Purposeful Depth Rule.** L'ombra distingue un livello o risponde a un'interazione. Vietato cancellare ogni ombra e vietato aggiungerla a ogni riquadro.

## Components

### Navigation
Voci con icona da 18px, area minima da 60px e spazio per il contesto. La selezione poggia su una superficie bianca con un piccolo punto magenta. Una superficie condivisa si sposta verso la voce puntata o raggiunta da tastiera, poi torna alla selezione corrente. Movimento da 220ms con `cubic-bezier(.16,1,.3,1)`, interrotto immediatamente da una nuova destinazione. Se l'effetto non è disponibile, la selezione conserva il suo stile CSS; su touch non si attiva l'hover.

### Buttons
Primari in inchiostro, secondari bianchi con bordo. Angoli da 12px, pillole nelle azioni della testata come nel frontend pubblico. Ombra bassa, sollevamento di 1px su hover, pressione verso la posizione iniziale; nessuna rotazione, rimbalzo o pulsazione continua. Il focus è magenta e leggibile. Controlli disabilitati non si animano.

### Inputs / Fields
Altezza minima 44px, bordo distinguibile, etichetta visibile. Focus tramite bordo e anello; mai ridimensionare il campo. I filtri della cronologia hanno ricerca più ampia, selettori e reset contestuale.

Su mobile, le sezioni dei moduli conservano 16px di spazio interno. Un gruppo annidato usa titolo e spazio, senza un secondo riquadro. Ricerca e azione Dewey si impilano sotto 640px: il campo resta a tutta larghezza. Gli span di colonna si attivano solo quando esistono le rispettive colonne.

I campi multilinea su telefono e dispositivi touch hanno almeno 144px di altezza, testo da 16px e interlinea 1.5. Anche i campi con due righe dichiarate devono offrire spazio per leggere e scrivere; restano espandibili verticalmente.

Il padding comune dei campi conserva sempre lo spazio dedicato alle icone: 40px per la ricerca con icona a sinistra, almeno 56px quando un pulsante è incorporato a destra. Le icone decorative non intercettano i tocchi.

### Headers / Tables / Plugins
Tutte le viste operative usano `workspace-header.php` e lo stesso gutter. Le tabelle rimangono stabili, con header tenue, separatori leggeri e scroll locale su telefono. Le interfacce plugin ereditano lo stesso vocabolario. ID, route, campi, condizioni e handler esistenti devono restare disponibili.

Indietro occupa sempre la stessa posizione sopra il titolo, con una destinazione deterministica. Le azioni di salvataggio chiudono il modulo in una riga dedicata; su telefono diventano una colonna con etichette che possono andare a capo. Il pulsante flottante Torna su si ritira quando la riga di azioni è visibile. Le impostazioni hanno sottomenu nella sidebar e un menu Sezioni nella testata, organizzato in Biblioteca, Comunicazione e Sito e sistema. Le etichette restano su una riga; su mobile il menu ha una sola colonna con scroll interno. Sezione corrente, titolo, descrizione e URL restano sincronizzati; i collegamenti funzionano anche senza JavaScript.

Il CMS mostra direttamente i campi nella lingua configurata per il sito. Le traduzioni archiviate restano conservate durante il salvataggio. Ogni sezione ha una sola cornice: gruppi di campi, funzionalità, note e accordion interni si distinguono con spazio e separatori, senza riquadri annidati. La stessa regola vale per cookie, configurazioni avanzate, proposte di donazione e modali dei plugin. I bordi restano sui campi e sulle opzioni selezionabili. I dizionari Dewey si aprono nella lingua del sito e usano un selettore compatto per la manutenzione delle altre traduzioni. Sui dispositivi touch le azioni sono sempre visibili, con bersagli da 44px. Le modali lunghe dei plugin mantengono testata e azioni sullo schermo: scorrono solo i campi, anche in orientamento orizzontale.

### Dashboard / Books
Il riepilogo diventa una serie di accessi ai relativi elenchi, con feedback tangibile. Le ultime acquisizioni riprendono la forma di un libro del catalogo pubblico: copertina completa, dorso, ombra e lieve sollevamento del collegamento. Una copertina assente mostra titolo e autore, senza grandi rettangoli anonimi.

### Motion / Accessibility
Animare trasformazione e opacità, mai larghezza, altezza o margini della struttura. Feedback da 140–220ms. Il menu mobile mantiene focus, Escape e ripristino dello scroll. `prefers-reduced-motion: reduce` elimina gli spostamenti e conserva tutti i segnali di stato. Niente sequenze di ingresso su ogni pagina.

## Do's and Don'ts

### Do
- **Do** mantenere le pagine bianche, il calendario neutro e accenti magenta piccoli ma riconoscibili.
- **Do** usare i font e le proporzioni del frontend pubblico, verificando il risultato nel browser.
- **Do** rendere visibili hover, pressione, focus e selezione; mantenere tutte le funzioni esistenti.
- **Do** distinguere navigazione, contenuto e livelli sovrapposti con tono e profondità.

### Don't
- **Don't** usare “colori di azione diversi per ogni plugin”.
- **Don't** creare “pannelli annidati decorativi”.
- **Don't** lasciare “tabelle illeggibili su telefono” o “pulsanti senza etichetta accessibile”.
- **Don't** ridurre le voci della sidebar a 13px o le loro descrizioni a 11px.
- **Don't** appiattire tutti i livelli su bianco, né compensare con grandi superfici rosa.
- **Don't** usare bordi laterali colorati, gradienti sul testo, glassmorphism decorativo o movimento continuo.
