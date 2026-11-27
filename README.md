# Article Number Plugin for OJS

[Türkçe](README_TR.md)

Generic plugin for **OJS 3.4.0.x and 3.5.0.x**, one package for both. OJS 3.3 has its own package (1.x).

Article Number gives an article a digital-first locator (*elocation-id*, for example `e0231470` or `P2.16`) as metadata of its own, separate from the page range. Crossref, JATS, Google Scholar and the citation output receive it as an article number instead of a made-up page range.

Journals that publish continuously have no page numbers. Many of them type the article number into the *Pages* field; Google Scholar then indexes a fake first page equal to the last page, and Crossref and PMC get a page range that does not exist. The plugin fills that gap and never writes to the `pages` field.

## What it does

| Area | Behaviour |
|---|---|
| Editor field | An *Article Number* field on the publication **Issue** form, after *Pages*, with usage guidance. |
| Uniqueness | The same number cannot be saved on two articles of one journal (or of one issue, by setting). The check blocks an unpublished article on save. The plugin itself never writes a used number either (at publication or in the migration). |
| QuickSubmit | The same field is in the form of the QuickSubmit plugin, with the same duplicate check. |
| Published articles | Once an article is published its number is locked, so a deposited coordinate cannot change by accident. |
| Article page | Shows *Article Number: X* in the article details; can be hidden per journal when the theme prints it itself. |
| Issue contents | Shows the number in each article's entry, where a page number would normally appear. |
| Google Scholar | For an article that has a number, `citation_firstpage` carries the number (as PLOS does) and no `citation_lastpage` is sent; the fake first = last page pair made from *Pages* is not sent. Nothing is added on version URLs (`article/view/ID/version/N`), where OJS core adds no Scholar tags either. Articles without a number are untouched. |
| Crossref | Writes `<publisher_item><item_number item_number_type="article_number">` and leaves out `<pages>`. |
| JATS | Writes `<elocation-id>` in place of `<fpage>` / `<lpage>` when a JATS plugin (jatsTemplate) is installed. |
| Citations | The number appears in the citation of the bundled styles (APA: *Article e0231470*). See *Known limits*. |
| Migration | Copies article numbers that were typed into *Pages* to the new field, from the plugin's settings window (one journal) or from the command line (one or all journals). It copies; `pages` is never changed, and the copy can be undone. |
| Number suggestion | Optional, off by default: suggests the next sequential number for a new article. |
| Languages | English, Turkish, Spanish, Portuguese (Portugal and Brazil). In any other interface language the plugin's texts appear in English. |

The feature is **off by default** for every journal. Nothing changes until a journal manager turns it on.

## Requirements

- OJS 3.4.0.x or 3.5.0.x. Tested on OJS 3.4.0-10 (PHP 8.1) and 3.5.0-5 (PHP 8.2).
- No `config.inc.php` changes, no database tables. The value is stored in the publication settings under the name `workNumber`.
- QuickSubmit support needs the separate *QuickSubmit* plugin from the plugin gallery.
- For JATS output the *JATS Template* plugin (and *OAI JATS*) must be installed; OJS 3.5 includes them, on OJS 3.4 they come from the plugin gallery.

## Installation

Sign in as administrator, open *Website → Plugins → Upload a new plugin*, upload the `.tar.gz` package and enable **Article Number Plugin** in the generic plugins list. Then open the plugin's *Settings* and tick *Enable the Article Number field for this journal*.

## Settings

- **Enable the Article Number field** — per journal, off by default.
- **Show the Article Number in the article details block** — on by default, except when the active theme prints the number itself (Atlas, Nivo, Axis, JournalPlus, or a theme that declares it): then it starts off, so the number is not shown twice, and the settings window says so. Turn it on or off yourself and your choice is kept.
- **Show the Article Number in the issue table of contents** (on by default) — same, for the issue contents page.
- **Derive the number from the pages value when an article is published** (on by default) — when an article is published or scheduled with no Article Number and its *Pages* value looks like an article number (the same rule as the Scan: for example `e0231470`), the value is copied into the field. Real page ranges and unclear values are left alone, a number typed by hand is never replaced, and *Pages* is never changed. A number that another article already uses (within the uniqueness setting below) is **not** written: a warning appears in the publish window before you publish and under the *Article Number* field, a notice also appears on the next page the person publishing opens (not on the publish screen itself), and the Scan lists the article for a manual check. Publishing itself is not blocked. Switch it off here to go back to typing every number yourself.
- **Uniqueness** — *journal-wide* (default) or *per issue*.
- **Number suggestion** (optional) — pattern such as `e{:04d}` (`e0001`, `e0002`, …). The suggestion never limits the field: any value can be typed. A number already in use is not suggested.

## Usage

1. Open an article, *Publication → Issue*, and type the number in *Article Number*. Leave *Pages* empty: when a number is set it is the article's locator. If both are filled, Crossref, JATS and Google Scholar use the Article Number; DOAJ, PubMed and other exports use Pages.
2. After publication the number is locked. For articles that are already published, use the migration (below), not the form.

## Migration

For journals that typed the number into *Pages*. The plugin recognises `e0231470`, `e123`, `P2.16`, `ID45` and long zero-padded numbers; real page ranges (`245-260`, and Roman-numeral front matter such as `I-VIII`) are never touched, and unclear values (for example `e-762`, `e714-0`, `Editorial`) are only listed for a manual check, one per line; the tool never rewrites them. The same goes for a value another article already has, or that two articles would both get (within the uniqueness setting): neither is written, each is listed with the other submission's number, and you decide.

- **Settings window:** *Scan* shows what would be converted, *Apply* copies it, *Undo* removes what the tool copied (numbers entered by hand stay). The values that need a manual check are listed in full at the top of the result (publication ID, issue, article title, the *Pages* value and why), followed by the counts: articles in the journal (published and not), publication versions in total, and what will be converted. Only the current version of each article is converted. Only published and scheduled articles are converted; articles still in progress or declined are skipped and shown as such (when an article is published, its number is filled in automatically if the *Pages* value looks like an article number that no other article uses; otherwise type it in the Issue form before publication). *Download the full result (CSV)* gives one row per article: to convert, to check by hand, skipped (real page range, already numbered, no pages).
- **Command line:**

```
php plugins/generic/articleNumber/tools/migrateArticleNumbers.php <dry-run|apply|rollback> [journalPath|all]
```

Above a limit (2000 articles by default) the window locks *Apply* and shows the command line instead.

Nothing is migrated unless you run it. After a migration, Crossref receives `item_number` instead of `pages` for those articles **the next time they are deposited**; records that are already registered at Crossref do not change until then. The DOI and its suffix are never read or changed by the plugin.

## Themes

Out of the box the number appears on every theme as an item in the article details. A theme that prints it in its own place can use the `{article_number}` helper; see [docs/THEME-INTEGRATION.md](docs/THEME-INTEGRATION.md). If the theme already shows the number, turn off the two display settings above. A theme can also say so itself; its answer comes before the plugin's built-in list.

## Known limits

- **IEEE:** the *Art. no.* label is applied to the main citation shown on the article page only. The style switcher and the downloadable citation show the number with the label *p.*.
- **ABNT, Harvard, IEEE:** these styles label every locator *p.*; the number is shown correctly but with that label. The bundled style files are not modified.
- **RIS and BibTeX downloads** carry the number in the page field (`SP`, `pages`).
- **Native XML export and import** do not carry the number. An article imported into another journal arrives without it (its *Pages* value does).
- **DOAJ, PubMed and TR Dizin exports** use the *Pages* field, not the number. **Dublin Core and DataCite** have no article number field here; the `pages` value is untouched.
- **OAI-PMH:** the number appears only in JATS (`elocation-id`). `oai_dc` carries *Pages* only; the MARC formats carry neither.
- **Themes that print the number themselves** (for example Nivo, Axis): turn off the card on the article page (*Show the Article Number in the article details block*), otherwise the number is shown twice.
- **Crossref:** an `item_number` can be at most 32 characters; a longer number makes the Crossref file invalid.
- **Number suggestion:** shown as help text under the field; it is not filled in for you.
- **QuickSubmit** shows no number suggestion: the suggestion appears only in an article's *Publication → Issue* form, and QuickSubmit publishes in one step.

## License & support

GNU GPL v3.

**OJS Services** — [ojs-services.com](https://ojs-services.com) · info@ojs-services.com
