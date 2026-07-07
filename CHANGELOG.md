# Changelog

All notable changes to the **Article Number Plugin for OJS** are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/), and the
project aims to follow [Semantic Versioning](https://semver.org/).

Target platform: **OJS 3.3.x** (PHP 7.4–8.1). Ports to 3.4 / 3.5 are planned.

## [1.6.0] — 2026-06-30

First public release. A digital-first **Article Number** (*elocation-id*) for
OJS, stored separately from the page-range field and integrated end-to-end —
editor entry, reader display, Crossref, JATS, Google Scholar, and citations —
without ever touching core data or forking any export plugin.

### Editing
- "Article Number" field on the publication **Issue** form (after *Pages*) and in
  the **QuickSubmit** import form, both gated by a per-journal opt-in.
- **Uniqueness enforcement**: the same Article Number cannot be saved on two
  articles in one journal; duplicates on an unpublished article are blocked on
  save. Scope is configurable per journal — **journal-wide by default**
  (the continuous-publishing / PLoS model) or per-issue.
- Once an article is **published**, its Article Number is locked, so a deposited
  coordinate cannot be silently changed.

### Reader & metadata
- Shows the Article Number on the article page (theme-independent; can be hidden
  per journal when the active theme renders it itself).
- **Google Scholar**: suppresses the fake `citation_firstpage`/`citation_lastpage`
  pair when an Article Number is set.
- **Crossref**: injects `<publisher_item><item_number item_number_type="article_number">`
  and drops `<pages>`; validates against the official Crossref schema.
- **JATS**: replaces `<fpage>/<lpage>` with `<elocation-id>` (JATS4R-safe).
- **Citations**: style-aware mapping across the bundled CSL styles (e.g. APA
  "Article e…"), with documented limits for styles that have no native article-
  number slot. See the README.

### Migration
- Back-fill `workNumber` from page-field article numbers — from the **settings
  panel** (Scan → Apply → Undo, this journal) or the **CLI tool** (any/all
  journals). It is a derivation: `pages` is read-only in every mode, genuine page
  ranges are never auto-candidates, and Undo removes only tool-derived values.
- Large archives are routed to the CLI above a per-journal candidate threshold
  (default 2000); panel Scan and Undo are never limited.

### Tools & extensibility
- Optional, default-off **generator** that suggests the next sequential number
  (template-driven, per journal+issue) without ever constraining the field.
- `{article_number}` Smarty helper for theme developers
  (see [docs/THEME-INTEGRATION.md](docs/THEME-INTEGRATION.md)).

### Architecture
- Single OJS generic plugin: **zero core-file changes, no export-plugin forks**,
  hook-based integration only. Data is stored under PKP's internal property name
  `workNumber` for a zero-migration hand-off if OJS core ships the field natively.
- All migration, classification, and uniqueness logic lives in one
  `ArticleNumberService` (single source of truth).
- English / Turkish locale parity throughout.
- Passed an independent pre-release security and compatibility review with no
  Critical/High findings (PHP 7.4 / 8.1 clean). Full test matrix in
  [docs/QA-MATRIX.md](docs/QA-MATRIX.md); design rationale in
  [docs/TECHNICAL-NARRATIVE.md](docs/TECHNICAL-NARRATIVE.md).

---

## Development history (pre-release)

Condensed record of the phased build that led to 1.6.0.

### [1.5.0] — 2026-06-25 — Style-aware citations
- Fixed MLA printing the Article Number twice; reworked the citation mapping to
  be style-aware (native-`number` styles get `number` only, APA keeps its
  "Article e…" label, the rest carry the value in the page slot so the locator is
  never lost). No CSL file modified.

### [1.4.0] — 2026-06-18 — Uniqueness enforcement
- Added per-journal Article Number uniqueness (hard block on save for unpublished
  articles; migration reports duplicates instead of blocking) with a journal-wide
  / per-issue scope setting.

### [1.3.0] — 2026-06-13 — QuickSubmit support
- Added the Article Number field to the QuickSubmit form via hooks (no fork),
  persisting `workNumber` before publish and for queued submissions.

### [1.2.0] — 2026-06-08 — Details-block toggle
- Added the per-journal "Show the Article Number in the article details block"
  setting so themes that render it themselves don't show it twice.

### [1.1.0] — 2026-06-03 — Theme integration
- Added the `{article_number}` Smarty helper and the theme-developer guide.

### [1.0.0] — 2026-06-01 — Feature-complete core (OJS 3.3)
- Plugin skeleton; runtime `workNumber` schema injection persisted to
  `publication_settings`; per-journal opt-in (default off) with graceful
  hand-off if core ever provides the field.
- Editor field with mutual-exclusion guidance and published-coordinate lock.
- Frontend display + Google Scholar fix; Crossref injection (XSD-valid); JATS
  `elocation-id` bridge; CSL citation mapping.
- CLI migration tool (dry-run / apply / rollback; `pages` never written) and the
  optional sequential-number generator.
