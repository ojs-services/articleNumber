# Article Number Plugin for OJS

A generic OJS **3.3** plugin that manages a digital-first **Article Number**
(*elocation-id*) as metadata **separate from the page-range field** — the way
digital-first journals actually identify articles (e.g. *PLoS ONE 15(4):
e0231470*, *Electron. J. Combin. 27 (2020) P2.16*).

OJS has no dedicated field for this, so editors squeeze the value into the
`pages` field. That breaks downstream metadata: Google Scholar indexes a fake
`firstpage = lastpage`, Crossref expects a separate `<item_number>`, and
PMC/PubMed need an `<elocation-id>`. This plugin fills that gap end-to-end while
**never touching core data** — in particular it **never writes to `pages`**.

**Requirements:** OJS 3.3.x · PHP 7.4–8.1 · MySQL/MariaDB. Works on single- and
multi-journal installs, the default theme, and custom themes. OMP/OPS are out of
scope; 3.4 / 3.5 ports are planned.

## Design contract

- **Data name = `workNumber`.** The value is stored under PKP's internal core
  property name `workNumber` in `publication_settings` (versioned,
  per-publication). The user-visible label is **"Article Number"**. This
  alignment means that if OJS core ever ships the property natively, hand-off is
  zero-migration.
- **Read-only core.** The plugin only ever writes its own `workNumber`. It never
  modifies `pages` or any other core field.
- **Per-journal opt-in.** The feature is OFF by default; each journal enables it
  individually. When OFF, OJS behaviour is unchanged.
- **Injection, not forks.** Export integration (Crossref / JATS / Scholar, in
  later phases) attaches to existing output; export plugins are never forked.
- **Mutual exclusion is an export rule.** When an article number is present, the
  page range is suppressed in export output — both are never emitted together.

## What it does

| Area | Behaviour |
|---|---|
| Editor UI | "Article Number" field on the publication issue-entry form (after *Pages*), with usage guidance; published-coordinate changes are blocked. |
| Quick Submit | The same field is available in the Quick Submit import form. |
| Uniqueness | The same Article Number cannot be assigned to two articles in one journal; on an unpublished article this is blocked at save. Scope is a per-journal setting (journal-wide by default; per-issue optional). |
| Reader page | Shows "Article Number: X" on the article landing page (theme-independent); can be hidden per journal when the theme renders it itself. |
| Google Scholar | Suppresses fake `citation_firstpage`/`citation_lastpage` when an article number is set. |
| Crossref | Injects `<publisher_item><item_number item_number_type="article_number">` and drops `<pages>`; XSD-valid; no fork. |
| JATS | Replaces `<fpage>/<lpage>` with `<elocation-id>` (JATS4R-safe). |
| Citation | Style-aware mapping across the bundled CSL styles (e.g. APA "Article e298"); see *Known behavior & limitations*. |
| Migration | Back-fill `workNumber` from page-embedded numbers — from the settings panel (this journal) or the CLI tool (any/all journals). Derive only; `pages` is never written. |
| Generator | Optional, per-journal, default-off: suggests the next sequential number. |

## Status

Feature-complete and verified on OJS 3.3. The plugin has passed an independent
pre-release security and compatibility review (no Critical/High findings; PHP
7.4 / 8.1 clean; zero core-file changes; no export-plugin forks). See
[docs/QA-MATRIX.md](docs/QA-MATRIX.md) for the test matrix and
[docs/TECHNICAL-NARRATIVE.md](docs/TECHNICAL-NARRATIVE.md) for the design
rationale.

## Migration

Journals that historically stored article numbers in the `pages` field can
back-fill `workNumber`. The operation is a **derivation, not a move**: the source
`pages` value is read and copied — it is **never modified, in any mode**. Genuine
page ranges (e.g. `245-260`) are never auto-candidates; ambiguous values are
flagged for manual review; rollback removes only the values the tool itself
derived (manually entered Article Numbers are untouched).

**From the settings panel (this journal):** in the plugin's Settings, use
**Scan** to preview what would be converted (read-only), then **Apply** to copy
those numbers into the Article Number field, or **Undo** to remove them. Apply is
enabled only after a Scan, and is confirmation-gated.

**From the command line (any or all journals):**

```
php plugins/generic/articleNumber/tools/migrateArticleNumbers.php <dry-run|apply|rollback> [journalPath|all]
```

For large archives the panel locks **Apply** above a candidate threshold
(default 2000) and points to this CLI command, which is not subject to the
web-request timeout. **Scan** and **Undo** in the panel are never threshold-limited.

## Installation

1. Copy this folder to `plugins/generic/articleNumber/` in your OJS 3.3 install.
2. In OJS: **Settings → Website → Plugins → Generic Plugins**, enable
   *Article Number Plugin*.
3. Click **Settings** on the plugin row and tick *Enable the Article Number
   field for this journal*.

## Settings

- **Enable the Article Number field** — master per-journal switch (default off).
- **Show the Article Number in the article details block** (default on) — turn
  off when your theme displays the Article Number itself (e.g. Nivo, Atlas, Axis)
  so it isn't shown twice. Affects only the plugin's on-page item, not export or
  citation.
- **Uniqueness scope** — *journal-wide* (default, recommended) requires each
  Article Number to be unique across the whole journal, matching the
  continuous-publishing / PLoS model; *per-issue* only enforces uniqueness within
  the same issue, for journals that still number within volumes/issues.
- **Article Number Generator** (optional, default off) — suggests the next
  sequential number for new, unpublished articles.
- **Template** — pattern for the suggestion: `{:04d}` is a zero-padded counter
  (`e{:04d}` → e0001), `{n}` a plain counter. The suggestion never constrains the
  field — the editor can type any value (e.g. `P2.16`).

## Usage

1. On an article's **Publication → Issue** tab, enter the Article Number in the
   field below *Pages*. Leave *Pages* empty — when an article number is set it
   becomes the authoritative coordinate, and emitting both produces broken
   metadata downstream.
2. Once an article is **published**, its Article Number is locked (changing a
   deposited coordinate would break citation/indexing matches). Use the migration
   tool to back-fill published articles instead.

## Theme integration

The Article Number is shown on every theme by default (a details/sidebar item).
To place it **in your theme's own location — e.g. in place of the page range** —
use the `{article_number}` Smarty helper:

```smarty
{article_number publication=$publication assign="articleNumber"}
{if $articleNumber}Art. no. <b>{$articleNumber|escape}</b>
{elseif $publication->getData('pages')}p. <b>{$publication->getData('pages')|escape}</b>{/if}
```

See [docs/THEME-INTEGRATION.md](docs/THEME-INTEGRATION.md) for the full guide.

## Known behavior & limitations

1. **IEEE "Art. no." label** is applied only to the **primary on-page citation**.
   The AJAX style-switcher and the downloadable citation reflect the CSL
   `number`/`page` mapping, but not the label rewrite (OJS exposes no
   post-render citation hook).
2. **DOAJ and DataCite** exports have no discrete article-number/elocation
   field; the value is deliberately **never force-written** into a page field.
   This is a documented limitation — no data is corrupted.
3. **The JATS bridge** requires a JATS producer (e.g. the `jatsTemplate` plugin
   or a FullTextCreator) to be installed. The hook is canonical
   (`OAIMetadataFormat_JATS::findJats`); with no producer there is no JATS to
   amend.
4. **The generator does not pre-fill** the next number — it **suggests** it in
   the field's help text, because OJS re-syncs the field's reactive value from
   the publication. The suggestion remains a free string.
5. **Published-coordinate immutability:** the form prevents changing or adding a
   published article's Article Number. To move legacy published content into the
   correct field (only when `pages` genuinely holds a stand-in number), use the
   migration tool, not the form. The tool changes neither the value nor `pages`,
   and does **not** auto-redeposit to Crossref.
6. **The migration tool is a derivation:** `pages` is read-only in every mode;
   genuine page ranges (e.g. `245-260`) are never auto-candidates; rollback
   removes only the values the tool itself derived.
7. **"Show the Article Number in the article details block"** setting: journals
   whose theme already renders the Article Number (Nivo/Atlas/Axis) can turn
   this off to prevent a duplicate display. It affects only the plugin's own
   on-page card — **not** export, citation, or the Google Scholar fix.
8. **ABNT, Harvard and IEEE label the Article Number "p." (page)** — e.g.
   "p. e0500". The citation mapping is style-aware (since 1.5.0): styles that
   render the CSL `number` variable for `article-journal` (MLA) receive `number`
   only, and APA keeps its "Article e…" label. But ABNT/Harvard/IEEE never
   surface `number` for journal articles and force a "p." label/prefix on the
   `page` slot, so the number — while **visible and correct** — is shown with a
   page label. Removing it would require forking the bundled CSL files, which
   this plugin never does. Article-number label perfection in these styles is a
   separate, optional concern (cf. the IEEE "Art. no." note in item 1).
9. **RIS and BibTeX downloads carry the Article Number in a page field, not a
   canonical machine-readable one** (RIS → `SP`, BibTeX → `pages`). The value is
   present and correct, and the stored `pages` field never leaks. Writing it to
   BibTeX's canonical `eid` field would require forking the bundled `bibtex.csl`
   (not done — the plugin never forks CSL files). For RIS, writing to the `C7`
   ("article number") tag is technically achievable **without** a fork (via the
   `CitationStyleLanguage::citationDownloadDefaults` hook, redirecting the RIS
   template to the plugin's own copy), but `C7` is a de-facto convention with no
   guaranteed cross-tool round-trip; that uncertain gain against a permanent
   template-maintenance burden is why it is not implemented now. Canonical,
   standards-compliant article-number metadata is delivered through Crossref
   (`item_number`) and JATS (`elocation-id`). RIS `C7` may be revisited based on
   demand.

## License

GNU GPL v3. See `LICENSE`.

