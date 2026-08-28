# LFW Patterns Library

Libreria centrale di **block pattern** Gutenberg, distribuita ai siti WordPress dal plugin
**LFW Cloud Patterns** (v2.x).

Questa repo è la **fonte di verità**: ospita i file `.json` dei pattern nella cartella `patterns/`.
Il plugin li scarica e li registra con `register_block_pattern()`, così compaiono nell'inserter
di ogni sito sotto la categoria **LFW Patterns**.

> Stato: repository **ripartita da zero** il 2026-08-28. La cartella `patterns/` è vuota: i pattern
> verranno aggiunti uno per uno solo se conformi allo standard di authoring (vedi sotto). La
> cronologia git conserva i vecchi pattern rimossi.

---

## Come funziona

```
GitHub (questa repo)                     Sito WordPress (client)
  patterns/*.json  ──── raw.githubusercontent.com ────►  plugin LFW Cloud Patterns
  manifest.json (opz.)                                     └─ register_block_pattern()
```

- **Pull only**: i siti leggono, non scrivono. Repo **pubblica**, nessun token richiesto sui client.
- Il plugin elenca i file via `manifest.json` (se presente) o via API `git/trees` (1 chiamata),
  poi scarica ogni `.json` da `raw` (nessun rate limit).
- Sync automatico: all'attivazione del plugin, ogni giorno via WP-Cron, o col bottone
  **Sincronizza Pattern** nella barra admin.
- Config sul client (override in `wp-config.php`, opzionali):
  `LFW_PATTERNS_REPO` (`linkforweb1/lfw-patterns`), `LFW_PATTERNS_REF` (`main`),
  `LFW_PATTERNS_PATH` (`patterns`), `LFW_PATTERNS_TOKEN` (solo se la repo torna privata).

---

## Formato di un pattern

Un file `patterns/<slug>.json`. Sono accettati sia gli **export "Esporta come JSON"** dell'editor
(`{"__file":"wp_block","title","content",…}`) sia lo **schema pattern completo**:

```json
{
  "title": "Hero — CTA centrata",
  "slug": "lfw/hero-cta-centered",
  "description": "Sezione full-width con occhiello, titolo, testo e due bottoni.",
  "categories": ["lfw-hero", "call-to-action"],
  "keywords": ["hero", "cta", "banner"],
  "viewportWidth": 1400,
  "content": "<!-- wp:group ... /-->"
}
```

Il plugin usa `title` + `content` e, se presenti, propaga `slug`, `categories`, `keywords`,
`blockTypes`, `viewportWidth`, `description`, `inserter`.

---

## Standard di authoring (obbligatorio prima del commit)

Un pattern entra in libreria solo se è **portabile** tra temi e siti diversi. Checklist:

- [ ] **Solo blocchi core** (`core/*`). Niente blocchi di temi o plugin
      (`outermost/icon-block`, `kadence/*`, `generateblocks/*`, …). Per le icone: SVG inline in `core/html`.
- [ ] **Nessun `#hex` / `rgb()` grezzo sul testo.** Colori via slug di preset
      (`"backgroundColor":"base"`, `"textColor":"contrast"`) o ereditati. Hex grezzo ammesso solo
      su elementi **decorativi** (gradienti di sfondo, bordi), mai come unico veicolo di leggibilità.
- [ ] **Nessun `px`** su `fontSize` e su spaziature. Usa gli slug di scala
      (`small`…`xx-large`, `var:preset|spacing|30..80`), `rem`, o `clamp()` per titoli fluidi.
- [ ] **Nessuna immagine/font/video esterno.** `core/image` senza `src` (placeholder), oppure asset
      committati in `patterns/assets/`. Sempre `aspectRatio`, mai `height` fissa in px.
- [ ] **Larghezze** in `%` o via `align:"wide"|"full"` + `layout:"constrained"`. Niente `contentSize`
      fisso salvo motivo.
- [ ] **Contenuti dinamici** dove ha senso: `core/site-title`, `core/site-logo`, `core/navigation`,
      `core/query` + `core/post-*`, `core/search` prendono da soli i dati del sito ospite.
- [ ] Rimuovi `metadata.patternName` di altri temi e le classi di plugin di animazione (`agl …`).
- [ ] Niente `href`/ID/menu specifici di un sito.
- [ ] `slug` esplicito con namespace `lfw/`. `viewportWidth` per un'anteprima non schiacciata.
- [ ] Testato su Twenty Twenty-Five **+** un tema con palette propria **+** un tema senza palette.
- [ ] Round-trip pulito: `serialize_blocks(parse_blocks($content)) === $content`.

Pattern fortemente art-directed (hero illustrati, landing brand): ammessi ma marcati con categoria
`lfw-landing` e comunque **senza asset esterni** e con testo leggibile anche senza i colori grezzi.

---

## Aggiungere / aggiornare / rimuovere un pattern

- **Aggiungere**: crea `patterns/<slug>.json` conforme alla checklist → commit → sul sito, bottone
  **Sincronizza Pattern**.
- **Aggiornare**: sovrascrivi lo stesso file → commit → sincronizza.
- **Rimuovere**: cancella il file → commit → sincronizza. Il pattern sparisce dall'inserter;
  **le pagine già impaginate restano intatte** (il markup è copiato nel DB all'inserimento).
- Se aggiungi un `manifest.json` alla radice (`{"files":["patterns/a.json", …]}`), il plugin salta
  del tutto l'API di GitHub. Tienilo allineato ai file presenti (o generalo in CI).

---

## Struttura

```text
/
├── README.md
├── manifest.json          # opzionale: elenco esplicito dei pattern
└── patterns/
    ├── .gitkeep
    ├── assets/            # (opz.) immagini/font dei pattern self-contained
    └── <slug>.json
```
