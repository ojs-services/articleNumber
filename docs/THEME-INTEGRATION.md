# Theme Integration Guide — Article Number Plugin

This guide is for **theme developers** who want an article's **Article Number**
(elocation-id) to appear in their theme's own layout — typically **in place of
the page range** in the theme's native location (next to the title, in the
citation line, etc.).

> The plugin already shows the Article Number out of the box on every theme, as
> an item in the article details / sidebar area (via the
> `Templates::Article::Details` hook). You only need this guide if you want to
> control *where* and *how* it appears in your own theme — for example, to show
> "Art. no. e0231470" exactly where you would otherwise print "p. 19–34".

## The data model

- The value is stored on the **publication** under the key `workNumber`.
- It is a **free string** — `e0231470`, `P2.16`, `051101`, etc. Never assume it
  is numeric.
- It is **empty** when no Article Number is assigned. In that case, fall back to
  the page range.
- **Display is your choice.** Showing the Article Number instead of pages on the
  page is a presentation decision. (The strict "either pages or article number,
  never both" rule only applies to *export* — Crossref/JATS/Scholar — and the
  plugin handles that automatically.)

## Accessing the value in a template

### Option A — Smarty helper (recommended)

The plugin registers an `{article_number}` Smarty function:

```smarty
{* Output the value directly (HTML-escaped): *}
{article_number publication=$publication}

{* Or assign it to a variable for conditional logic: *}
{article_number publication=$publication assign="articleNumber"}
```

- `publication` defaults to the template's `$publication` if omitted.
- Output mode is HTML-escaped for you.
- Returns an empty string when no Article Number is set.

### Option B — Direct access

```smarty
{assign var="articleNumber" value=$publication->getData('workNumber')|default:""}
```

Remember to `|escape` it yourself when you print it directly.

## Recommended snippet: Article Number *instead of* pages

Wherever your theme currently prints the page range, prefer the Article Number
when it exists:

```smarty
{article_number publication=$publication assign="articleNumber"}
{if $articleNumber}
    <span class="article-number">Art. no. <b>{$articleNumber|escape}</b></span>
{elseif $publication->getData('pages')}
    <span class="pages">p. <b>{$publication->getData('pages')|escape}</b></span>
{/if}
```

For example, in a theme whose `templates/frontend/objects/article_details.tpl`
prints pages like:

```smarty
{if $pages}<span>p. <b>{$pages|escape}</b></span>{/if}
```

replace it with the snippet above so an article that has an Article Number shows
"Art. no. …" in that exact spot, and articles without one keep their page range.

## Avoiding a duplicate

The plugin's default details-block item and your inline placement would both
appear at once. When your theme renders the Article Number itself, turn the
plugin's own item off so it isn't shown twice:

**Settings → Website → Plugins → Article Number Plugin → Settings →**
uncheck **"Show the Article Number in the article details block"**.

This per-journal setting hides only the plugin's on-page item; export, citation,
and the Google Scholar fix are unaffected. Do this for every journal whose
active theme renders the Article Number itself (Nivo, Atlas, Axis, …).

## Citations ("How to Cite")

There are **two kinds of themes**, and they behave differently:

1. **Themes that use the citationStyleLanguage plugin's output**
   (they render the `$citation` template variable, like the default theme).
   These are handled **automatically** — this plugin hooks
   `CitationStyleLanguage::citation` and rewrites the citation to show the
   Article Number (APA "Article e298", IEEE "Art. no. e298") instead of pages.
   **No theme work needed.**

2. **Themes that build their own citation strings** (e.g. a custom "How to Cite"
   modal that assembles APA/MLA/Chicago/BibTeX itself, reading
   `$publication->getData('pages')` directly). These **bypass** the
   citationStyleLanguage plugin, so this plugin's hook never runs for them. You
   must make the citation builder Article-Number-aware yourself.

For a self-building theme, wherever the citation appends the page range, prefer
the Article Number:

```php
// PHP (theme plugin) — before:
$pages = $publication->getData('pages');
if ($pages) $apa .= ', ' . $pages;

// after:
$workNumber = $publication->getData('workNumber');
if ($workNumber !== null && $workNumber !== '') {
    $apa .= ', Article ' . $workNumber;          // APA:  "…, 1(1), Article e298."
    // IEEE: ', Art. no. ' . $workNumber;
} elseif ($pages) {
    $apa .= ', ' . $pages;
}
```

Apply the same "Article Number instead of pages" rule to every format the theme
emits (APA, MLA, Chicago, BibTeX `pages`, RIS `SP/EP`, etc.). The export side
(Crossref/JATS/Scholar) is already handled by the plugin — this only concerns the
human-readable citation your theme renders.

## Label conventions (optional)

If you want to mirror common citation conventions:

- Generic / APA-style: `Article {$articleNumber}`
- IEEE-style: `Art. no. {$articleNumber}`

These are display labels only — the stored value is always just the number.

## Checklist for theme developers

- [ ] Read `workNumber` via `{article_number}` (or `getData('workNumber')`).
- [ ] Show it **instead of** the page range when present; fall back to pages.
- [ ] Escape the value (the helper does this; direct access does not).
- [ ] Don't assume the value is numeric or zero-padded.
- [ ] Decide whether to keep the plugin's default sidebar card.
