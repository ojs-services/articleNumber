# Article Number Plugin — QA Results Matrix

Verified against a live OJS **3.3.0.x** installation (PHP 7.4–8.2, MySQL/MariaDB)
using a demo journal. "CLI" = a deterministic harness run through the OJS
bootstrap; "Browser" = the live management / reader UI; "XSD" = validation
against the official schema. All test scaffolding (temporary DOIs, Article
Numbers, draft versions) was created with a seed → verify → restore cycle and
removed afterwards; the `pages` field was never written by any test.

## Definition of Done — status

| # | Requirement | Result |
|---|---|---|
| 1 | Per-journal enable/disable; OFF leaves OJS unchanged | ✅ |
| 2 | `workNumber` stored under the correct name in `publication_settings`, versioned | ✅ |
| 3 | Editor field + mutual-exclusion guidance + usage help | ✅ |
| 4 | Frontend display + Google Scholar meta fix | ✅ |
| 5 | Crossref injection, XSD-valid, export plugin not forked | ✅ |
| 6 | JATS `elocation-id` (JATS4R-safe); style-aware CSL citations | ✅ |
| 7 | Migration dry-run / apply / rollback; `pages` never changed | ✅ |
| 8 | Graceful hand-off check present | ✅ |
| 9 | README + technical narrative + CHANGELOG + QA matrix | ✅ |
| 10 | Zero core-file changes; no export-plugin forks | ✅ |

## Test matrix

| ID | Area | Test | Method | Result |
|---|---|---|---|---|
| 1.1 | Schema | `workNumber` injected into publication schema (string, nullable) | CLI | ✅ |
| 1.2 | Schema | `pages` left untouched by schema injection | CLI | ✅ |
| 1.3 | Schema | Graceful hand-off: native `workNumber` not clobbered | CLI | ✅ |
| 1.4 | Schema | Per-journal opt-in save/round-trip persists | CLI + Browser | ✅ |
| 1.5 | Schema | Plugin enables with no fatal; settings modal renders (en/tr) | Browser | ✅ |
| 2.1 | Editor | "Article Number" field appears after `pages`, only when enabled | Browser | ✅ |
| 2.2 | Editor | Field saves; value is version-scoped (new version only) | Browser + DB | ✅ |
| 2.3 | Editor | `pages` bit-identical after save (mutual-exclusion is guidance only) | DB | ✅ |
| 2.4 | Editor | Published-coordinate change blocked via `Publication::validate` | CLI | ✅ |
| 2.5 | Editor | Edit without a `workNumber` key is not interfered with | CLI | ✅ |
| 3.1 | Frontend | Article Number shown on the article page (default and custom themes) | Browser / HTML | ✅ |
| 3.2 | Frontend | `citation_firstpage`/`citation_lastpage` suppressed when `workNumber` set | HTML source | ✅ |
| 3.3 | Frontend | Regression: page-based article keeps firstpage/lastpage | HTML source | ✅ |
| 4.1 | Crossref | `<publisher_item><item_number item_number_type="article_number">` injected | CLI | ✅ |
| 4.2 | Crossref | `<pages>` suppressed when `workNumber` set | CLI | ✅ |
| 4.3 | Crossref | Output valid against the official Crossref schema | XSD | ✅ |
| 4.4 | Crossref | Regression: page-based article unchanged, also XSD-valid | XSD | ✅ |
| 4.5 | Crossref | Crossref export plugin not forked/modified | review | ✅ |
| 5.1 | JATS | `<fpage>/<lpage>` replaced with `<elocation-id>` | CLI | ✅ |
| 5.2 | JATS | JATS4R: no `elocation-id` ↔ `fpage/lpage` coexistence | CLI | ✅ |
| 5.3 | Citation | APA renders "…, 1(1), Article e0231470." | citeproc | ✅ |
| 5.4 | Citation | Style-aware mapping across the 10 bundled CSL styles | citeproc + Browser | ✅ |
| 5.5 | Citation | Regression: no `workNumber` → normal page citation (all styles) | citeproc | ✅ |
| 6.1 | Migration | Classifier: `e0231470` / `P2.16` / `051101` → derive | CLI | ✅ |
| 6.2 | Migration | Classifier: real ranges (`245-260`) → never auto-candidate | CLI | ✅ |
| 6.3 | Migration | dry-run writes nothing | CLI + DB | ✅ |
| 6.4 | Migration | apply writes only `workNumber`; `pages` bit-identical | CLI + DB | ✅ |
| 6.5 | Migration | apply idempotent (skips already-numbered) | CLI | ✅ |
| 6.6 | Migration | rollback removes only tool-derived values; manual ones kept | CLI + DB | ✅ |
| 6.7 | Generator | Template `e{:04d}` → e0001, `{n}` → plain counter | CLI | ✅ |
| 6.8 | Generator | Sequence scoped per journal + issue (max + 1) | CLI | ✅ |
| 6.9 | Generator | Suggestion surfaced (help text) for unnumbered/unpublished | Browser | ✅ |
| 6.10 | Generator | No suggestion for published articles; generator default OFF | Browser | ✅ |
| 7.1 | Uniqueness | Duplicate Article Number blocked on save (unpublished) | CLI + Browser | ✅ |
| 7.2 | Uniqueness | Journal-wide vs per-issue scope honoured; own-submission excluded | CLI | ✅ |
| 7.3 | Uniqueness | Cross-journal isolation (one journal's value not seen by another) | CLI | ✅ |
| 8.1 | QuickSubmit | Field present, read, and persisted via QuickSubmit (no fork) | Browser + DB | ✅ |
| 9.1 | Panel migration | Scan (read-only) / Apply / Undo from the settings panel | Browser + DB | ✅ |
| 9.2 | Panel migration | Manager-gated + CSRF; Apply re-verifies a scan signature | Browser | ✅ |
| 9.3 | Panel migration | Candidate threshold locks Apply (panel) and points to the CLI | Browser | ✅ |
| L.1 | Locale | Parity en_US / tr_TR | review | ✅ |
| L.2 | Core | Zero core-file changes; no export-plugin forks | review | ✅ |

**Result: all rows pass.** No known failing cases for the OJS 3.3 target.

## Known limitations (documented, by design)

- **DOAJ / DataCite** exports have no discrete article-number/elocation field; the
  value is intentionally **not** force-written into a page field. (Documented; no
  data corruption.)
- **ABNT / Harvard / IEEE** citation styles label the Article Number as a page
  ("p. e…") because their bundled CSL has no native article-number slot; the value
  is present and correct, only labelled as a page. The IEEE "Art. no." label is
  applied to the primary on-page citation only.
- **RIS / BibTeX** downloads carry the Article Number in a page field (RIS `SP`,
  BibTeX `pages`), not a dedicated machine-readable field; the canonical carriers
  are Crossref `item_number` and JATS `elocation-id`.
- The generator surfaces its suggestion as **help text** (not a pre-filled value)
  because the OJS workflow re-syncs a field's value from the publication.
- JATS output is produced via the canonical `OAIMetadataFormat_JATS::findJats`
  hook; a JATS producer must be installed to expose JATS over OAI.

See the README's *Known behavior & limitations* for the full list.
