{**
 * plugins/generic/articleNumber/templates/frontend/articleNumberToc.tpl
 *
 * Article Number Plugin for OJS — issue table-of-contents display.
 *
 * Injected into each article's entry on the issue TOC via the
 * `Templates::Issue::Issue::Article` hook. Uses its OWN class and forces
 * static/block layout inline so it can never inherit a theme's (or a journal's
 * custom) `.pages` positioning — some themes set `.pages { position: absolute }`,
 * which would otherwise stack every article's number on top of one another.
 *
 * The hook fires at the END of the summary (after the galley links), so the
 * small script moves the item up to sit just BEFORE the galley links — where a
 * locator naturally belongs, next to the authors. It degrades gracefully: with
 * no galley links, the item stays put. A theme that wants the number elsewhere
 * can render it itself with {article_number} and disable this in the settings.
 *
 * @uses $articleNumberValue string The publication's workNumber.
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *}
{if $articleNumberValue}
	<div class="articleNumberToc" style="position:static; float:none; display:block; clear:both;">
		{translate key="plugins.generic.articleNumber.field.label"}: {$articleNumberValue|escape}
	</div>
	{literal}
	<script>
	(function () {
		var s = document.currentScript;
		var el = (s && s.previousElementSibling && s.previousElementSibling.classList &&
			s.previousElementSibling.classList.contains('articleNumberToc')) ? s.previousElementSibling : null;
		var summary = el && el.closest ? el.closest('.obj_article_summary') : (el ? el.parentNode : null);
		if (!el && s && s.closest) { summary = s.closest('.obj_article_summary'); }
		if (!el && summary) { var c = summary.querySelectorAll('.articleNumberToc'); el = c.length ? c[c.length - 1] : null; }
		if (!el || !summary) return;
		var galleys = summary.querySelector('.galleys_links') || summary.querySelector('.galleys');
		if (galleys && galleys.parentNode && galleys !== el && !galleys.contains(el)) {
			galleys.parentNode.insertBefore(el, galleys);
		}
	})();
	</script>
	{/literal}
{/if}
