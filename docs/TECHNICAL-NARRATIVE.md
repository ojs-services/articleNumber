# Article Number for OJS — What, Why, and How

*A standards-compliant Article Number / elocation-id for Open Journal Systems,
stored separately from the page-range field, integrated end-to-end, and designed
to hand off cleanly to OJS core.*

## The problem

Digital-first journals don't number articles by page range. They identify each
article with an **article number** (a.k.a. *elocation-id*): `PLoS ONE 15(4):
e0231470`, `Electron. J. Combin. 27 (2020) P2.16`, `J. Vac. Sci. Technol. …
051101`. OJS has no dedicated field for this, so editors squeeze the value into
the **Pages** field. That single workaround corrupts metadata everywhere
downstream:

- **Google Scholar** indexes the value as both `citation_firstpage` and
  `citation_lastpage` — a fake one-page range, and a broken bibliographic record.
- **Crossref** expects the value in a separate `<item_number>`, not in `<pages>`;
  a page-field value is deposited in the wrong place.
- **PMC / PubMed** require either `fpage` or `elocation-id`; an article number
  buried in page text can't produce a valid `<elocation-id>`, risking rejection.
- **Citation styles** (APA 7, IEEE) format article numbers specially
  ("Article e298", "Art. no. e298"); a page-field value can't trigger that.

PKP has known about this since 2019 (issue #4695): core defined the property but
left export, citation, and guidance unfinished. This plugin completes the chain.

## The standards map (the source of truth)

| System | Correct carrier | Rule |
|---|---|---|
| **Crossref** | `<publisher_item><item_number item_number_type="article_number">…</item_number></publisher_item>` | Mutually exclusive with `<pages>`/`<first_page>`. |
| **JATS** (PMC, SciELO) | `<elocation-id>` | Replaces `<fpage>/<lpage>`; JATS4R errors if both are present. |
| **PubMed / PMC** | `elocation-id` | Either `fpage` or `elocation-id` must exist, or the article is rejected. |
| **Google Scholar** | *(no tag)* | Don't emit `citation_firstpage`/`lastpage` when an article number is set. |
| **CSL / citeproc** | `number` / locator, per style | Style-aware: APA renders "Article e298"; styles whose bundled CSL has no native article-number slot show the value in the locator position. |
| **DOAJ / DataCite** | *(no discrete field)* | Document the limitation; never force the value into a page field. |

## How it works (architecture)

A single OJS **generic plugin**, zero core-file changes, no export-plugin forks.

- **Data layer.** A `workNumber` string property is injected into the publication
  schema at runtime (`Schema::get::publication`). It persists automatically to
  `publication_settings` — versioned, per-publication, no new table.
- **Naming contract.** The value is stored under PKP's *internal core name*
  `workNumber` while the user-visible label is **"Article Number"**. If core ever
  ships the property, hand-off is zero-migration: the data is already in the
  right place under the right name.
- **Read-only core.** The plugin only ever writes its own `workNumber`. It never
  touches `pages` or any other core field — a hard architectural guarantee, not a
  convention.
- **Mutual exclusion is an export rule, not a storage rule.** Storage can hold
  both `pages` and `workNumber`; export picks one authoritative coordinate. When
  an article number is set, the page range is suppressed in Crossref, JATS, and
  Scholar output — they are never emitted together.
- **Injection, not forks.** Crossref output is post-processed via the export
  filter's own `Filter::execute` hook; JATS via `OAIMetadataFormat_JATS::findJats`;
  Scholar via the article-view hook (running after the googleScholar plugin);
  citation via the `CitationStyleLanguage::citation` hook. Each integration
  attaches to an existing extension point — nothing is forked.
- **Per-journal opt-in.** Off by default; each journal enables it. When off, OJS
  behaves exactly as before.

## Migration (derive, don't move)

Journals that historically stored article numbers in `pages` can back-fill
`workNumber` — from the plugin's **settings panel** (Scan → Apply → Undo, for the
current journal) or the bundled **CLI tool** (any or all journals). It is a
**derivation, not a move**: the source `pages` value is read and copied; it is
never modified.

- **Scan** / `dry-run` reports candidates and writes nothing.
- **Apply** writes only `workNumber` (plus a "derived" marker) for confident
  stand-ins (`e0231470`, `P2.16`, …). Genuine page ranges (`245-260`) are never
  auto-candidates; ambiguous values are flagged for manual review.
- **Undo** / `rollback` removes only the values the tool derived — manually
  entered numbers are never touched.

The panel is manager-gated and CSRF-protected, re-verifies the scan before
applying, and for large archives locks Apply above a candidate threshold and
points to the CLI (which is not subject to the web-request timeout). Because the
source is never altered, the operation is idempotent and reversible by
construction.

## Optional generator

A per-journal, default-off helper that **suggests** the next sequential number
(`e{:04d}` → e0001, e0002, …), scoped per journal+issue, with a same-issue
collision warning. It only ever suggests — the field stays a free string the
editor controls, and published articles are never offered a number.

## Future-proofing: graceful hand-off

If a future OJS core ships a native `workNumber`, the plugin detects it and skips
its own schema injection to avoid a clash. The data already lives under the
correct name in the correct table, so no migration is needed. The plugin can
then be retired — or kept to provide whatever core still leaves unfinished
(export, citation). Either way, the journal's data is safe.

## Scope

- Target: **OJS 3.3** (3.4 and 3.5 ports planned; the Crossref injection point
  changes with the 3.4 DOI/Registration-Agency rework).
- OMP/OPS are out of scope.

---

*Built read-only against core, standards-mapped, migration-safe, and
hand-off-ready — "always usable, or cleanly transferable."*
