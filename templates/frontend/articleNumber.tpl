{**
 * plugins/generic/articleNumber/templates/frontend/articleNumber.tpl
 *
 * Article Number Plugin for OJS — reader-facing display.
 *
 * Rendered into the article landing page's entry-details column via the
 * Templates::Article::Details hook. Theme-independent; mirrors the core
 * ".item / .label / .value" markup so it inherits each theme's styling.
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
{/if}
