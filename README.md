# LFW Patterns

Raccolta di **block pattern** Gutenberg in formato `.json`, distribuiti ai siti WordPress
dal plugin **LFW Cloud Patterns**.

```
patterns/<slug>.json   un pattern per file
manifest.json          elenco dei file — rigenerato in automatico dalla CI
bin/                   linter + generatore di manifest usati dalla CI
```

## Formato di un pattern

```json
{
  "slug": "lfw/hero-centered-cta",
  "title": "Hero — titolo centrato con due CTA",
  "description": "Sezione full-width: occhiello, titolo, testo e due pulsanti.",
  "categories": ["call-to-action", "banner"],
  "keywords": ["hero", "cta"],
  "viewportWidth": 1400,
  "content": "<!-- wp:group ... --> ... <!-- /wp:group -->"
}
```

Obbligatori: `slug` (con namespace `lfw/`) e `content`. Tutto il resto è opzionale;
il plugin propaga `title`, `categories`, `keywords`, `viewportWidth`, `description`.

## Regole

I pattern devono essere **portabili tra temi e siti diversi**:

- solo blocchi `core/*` (niente blocchi di temi o plugin), niente classi/attributi
  utility di framework (`tw-*`, `agl*` di Twentig);
- colori a **slug di preset** neutri (`base`, `contrast`, …), mai `#hex` grezzi sul
  testo né slug specifici di un tema (`accent-1..6`, `foreground`, `tertiary`);
- spaziature e dimensioni a **preset / `rem`**, mai `px`;
- **nessuna risorsa esterna**: le immagini usano un segnaposto SVG inline, sostituibile
  nell'editor con un clic;
- niente `href`/ID/menu legati a un sito specifico, niente `core/template-part`;
  `core/navigation` senza `ref` (adotta il menu del sito di destinazione).

Ogni push è **controllato dalla CI** (`bin/lint-patterns.php`): se un pattern viola le regole
il commit risulta rosso. Lo stesso controllo, con auto-fix, è nel plugin → *LFW Patterns →
Aggiungi da markup*.

## Come arrivano sui siti

Pull-only: il plugin legge `manifest.json` e scarica ogni `.json` da
`raw.githubusercontent.com`. Sincronizza all'attivazione, ogni giorno via WP-Cron, o a mano.
Rimuovere un file lo toglie dall'inserter dei siti; **le pagine già composte restano intatte**
(il markup viene copiato nel contenuto al momento dell'inserimento).

Repo **pubblica**, nessun token richiesto sui siti.
