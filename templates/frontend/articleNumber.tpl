{**
 * plugins/generic/articleNumber/templates/frontend/articleNumber.tpl
 *
 * Article Number Plugin for OJS — reader-facing display.
 *
 * Rendered into the article landing page's entry-details column via the
 * Templates::Article::Details hook. Theme-independent; mirrors the core
 * ".item / .label / .value" markup so it inherits each theme's styling.
 *
 * The core hook fires at the END of the .entry_details column, so the small
 * script below moves the item up to sit just before the publication date
 * (i.e. right after the galley links), where a locator naturally belongs. It
 * degrades gracefully: if there is no ".item.published", the item stays put.
 * Themes that render the Article Number themselves (via {article_number}) can
 * place it wherever they like and disable this item in the plugin settings.
 *
 * @uses $articleNumberValue string The publication's workNumber.
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *}
{if $articleNumberValue}
	<div class="item articleNumber">
		<h2 class="label">
			{translate key="plugins.generic.articleNumber.field.label"}
		</h2>
		<div class="value">
			{$articleNumberValue|escape}
		</div>
	</div>
	{literal}
	<script>
	(function () {
		var s = document.currentScript;
		var card = s ? s.previousElementSibling : null;
		if (!card || !card.classList || !card.classList.contains('articleNumber')) {
			var all = document.querySelectorAll('.item.articleNumber');
			card = all.length ? all[all.length - 1] : null;
		}
		if (!card) return;
		var box = (card.closest ? card.closest('.entry_details') : null) || card.parentNode;
		if (!box) return;
		var published = box.querySelector('.item.published');
		if (published && published !== card) {
			box.insertBefore(card, published);
		}
	})();
	</script>
	{/literal}
{/if}
