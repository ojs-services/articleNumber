{**
 * plugins/generic/articleNumber/templates/settingsForm.tpl
 *
 * Article Number Plugin for OJS — per-journal settings form.
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *}
<script>
	$(function() {ldelim}
		$('#articleNumberSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>

<form
	class="pkp_form"
	id="articleNumberSettingsForm"
	method="post"
	action="{url router=$smarty.const.ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}"
>
	<div id="articleNumberSettings">

		<div id="description">{translate key="plugins.generic.articleNumber.description"}</div>

		<h3>{translate key="plugins.generic.articleNumber.settings.title"}</h3>

		{csrf}
		{include file="controllers/notification/inPlaceNotification.tpl" notificationId="articleNumberSettingsFormNotification"}

		{fbvFormArea id="articleNumberSettingsFormArea"}
			{fbvFormSection list=true}
				{fbvElement
					type="checkbox"
					id="enableWorkNumber"
					value="1"
					checked=$enableWorkNumber
					label="plugins.generic.articleNumber.settings.enableWorkNumber"
				}
			{/fbvFormSection}
			{fbvFormSection}
				<p class="pkp_help_text">{translate key="plugins.generic.articleNumber.settings.enableWorkNumber.description"}</p>
			{/fbvFormSection}
			{fbvFormSection list=true}
				{fbvElement
					type="checkbox"
					id="showArticleNumberInDetails"
					value="1"
					checked=$showArticleNumberInDetails
					label="plugins.generic.articleNumber.settings.showInDetails"
				}
			{/fbvFormSection}
			{fbvFormSection}
				<p class="pkp_help_text">{translate key="plugins.generic.articleNumber.settings.showInDetails.description"}</p>
			{/fbvFormSection}

			{fbvFormSection list=true label="plugins.generic.articleNumber.settings.uniqueness"}
				{fbvElement type="radio" id="uniqueness-journal" name="workNumberUniquenessScope" value="journal" checked=$workNumberUniquenessScope|compare:"journal" label="plugins.generic.articleNumber.settings.uniqueness.journal"}
				{fbvElement type="radio" id="uniqueness-issue" name="workNumberUniquenessScope" value="issue" checked=$workNumberUniquenessScope|compare:"issue" label="plugins.generic.articleNumber.settings.uniqueness.issue"}
			{/fbvFormSection}
			{fbvFormSection}
				<p class="pkp_help_text">{translate key="plugins.generic.articleNumber.settings.uniqueness.description"}</p>
			{/fbvFormSection}
		{/fbvFormArea}

		<h3>{translate key="plugins.generic.articleNumber.settings.generator.title"}</h3>

		{fbvFormArea id="articleNumberGeneratorFormArea"}
			{fbvFormSection list=true}
				{fbvElement
					type="checkbox"
					id="workNumberGeneratorEnabled"
					value="1"
					checked=$workNumberGeneratorEnabled
					label="plugins.generic.articleNumber.settings.generator.enabled"
				}
			{/fbvFormSection}
			{fbvFormSection}
				{fbvElement
					type="text"
					id="workNumberTemplate"
					value=$workNumberTemplate
					label="plugins.generic.articleNumber.settings.generator.template"
				}
				<p class="pkp_help_text">{translate key="plugins.generic.articleNumber.settings.generator.template.description"}</p>
			{/fbvFormSection}
		{/fbvFormArea}

		{if $enableWorkNumber}
			<h3>{translate key="plugins.generic.articleNumber.migrate.title"}</h3>

			{fbvFormArea id="articleNumberMigrateArea"}
				{fbvFormSection}
					<p class="pkp_help_text">{translate key="plugins.generic.articleNumber.migrate.intro"}</p>
				{/fbvFormSection}
				{fbvFormSection}
					{fbvElement
						type="text"
						id="workNumberMigrationThreshold"
						value=$workNumberMigrationThreshold
						label="plugins.generic.articleNumber.migrate.threshold.label"
					}
					<p class="pkp_help_text">{translate key="plugins.generic.articleNumber.migrate.threshold.description"}</p>
				{/fbvFormSection}
				{fbvFormSection}
					<button type="button" class="pkp_button" id="anpMigrateScan">{translate key="plugins.generic.articleNumber.migrate.scan"}</button>
					<button type="button" class="pkp_button" id="anpMigrateApply" disabled="disabled">{translate key="plugins.generic.articleNumber.migrate.apply"}</button>
					<button type="button" class="pkp_button" id="anpMigrateRollback">{translate key="plugins.generic.articleNumber.migrate.rollback"}</button>
				{/fbvFormSection}
				{fbvFormSection}
					<div id="anpMigrateResult" aria-live="polite" style="white-space:pre-line"></div>
				{/fbvFormSection}
			{/fbvFormArea}
		{else}
			{fbvFormArea id="articleNumberMigrateHintArea"}
				{fbvFormSection}
					<p class="pkp_help_text">{translate key="plugins.generic.articleNumber.migrate.enableFirst"}</p>
				{/fbvFormSection}
			{/fbvFormArea}
		{/if}

		{fbvFormButtons submitText="common.save"}
	</div>
</form>

{if $enableWorkNumber}
<script type="text/javascript">
	window.ANP_MIG = {ldelim}
		scanUrl: "{url router=$smarty.const.ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="migrateScan" escape=false}",
		applyUrl: "{url router=$smarty.const.ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="migrateApply" escape=false}",
		rollbackUrl: "{url router=$smarty.const.ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="migrateRollback" escape=false}",
		s: {ldelim}
			scanning: "{translate key="plugins.generic.articleNumber.migrate.scanning"}",
			applying: "{translate key="plugins.generic.articleNumber.migrate.applying"}",
			rolling: "{translate key="plugins.generic.articleNumber.migrate.rolling"}",
			summary: "{translate key="plugins.generic.articleNumber.migrate.scan.summary"}",
			none: "{translate key="plugins.generic.articleNumber.migrate.scan.none"}",
			ready: "{translate key="plugins.generic.articleNumber.migrate.scan.ready"}",
			locked: "{translate key="plugins.generic.articleNumber.migrate.scan.locked"}",
			applyConfirm: "{translate key="plugins.generic.articleNumber.migrate.apply.confirm"}",
			applyDone: "{translate key="plugins.generic.articleNumber.migrate.apply.done"}",
			stale: "{translate key="plugins.generic.articleNumber.migrate.apply.stale"}",
			nothing: "{translate key="plugins.generic.articleNumber.migrate.apply.nothing"}",
			aborted: "{translate key="plugins.generic.articleNumber.migrate.apply.aborted"}",
			rollbackConfirm: "{translate key="plugins.generic.articleNumber.migrate.rollback.confirm"}",
			rollbackDone: "{translate key="plugins.generic.articleNumber.migrate.rollback.done"}",
			examples: "{translate key="plugins.generic.articleNumber.migrate.examples"}",
			error: "{translate key="plugins.generic.articleNumber.migrate.error"}"
		{rdelim}
	{rdelim};
</script>
<script type="text/javascript">
{literal}
	$(function() {
		var cfg = window.ANP_MIG, s = cfg.s;
		var $result = $('#anpMigrateResult'),
			$apply = $('#anpMigrateApply'),
			lastSig = null, lastCount = 0;

		function csrf() { try { return pkp.currentUser.csrfToken; } catch (e) { return ''; } }
		function esc(x) { return $('<div>').text(x == null ? '' : String(x)).html(); }
		function fill(t, map) { return t.replace(/%(\w+)%/g, function(m, k) { return (k in map) ? map[k] : m; }); }
		function samplesHtml(arr) {
			if (!arr || !arr.length) return '';
			var out = '<br>' + esc(s.examples) + '<ul>';
			for (var i = 0; i < arr.length && i < 25; i++) out += '<li>' + esc(arr[i]) + '</li>';
			return out + '</ul>';
		}
		function setResult(h) { $result.html(h); }
		function lockApply() { lastSig = null; $apply.prop('disabled', true); }

		function post(url, data, cb) {
			$.post(url, data, function(r) {
				if (!r || r.status === false) { setResult(esc((r && r.content) || s.error)); return; }
				var p; try { p = JSON.parse(r.content); } catch (e) { setResult(esc(s.error)); return; }
				cb(p);
			}, 'json').fail(function() { setResult(esc(s.error)); });
		}

		$('#anpMigrateScan').click(function() {
			setResult(esc(s.scanning));
			lockApply();
			post(cfg.scanUrl, {}, function(p) {
				var c = p.counts || {};
				var head = fill(s.summary, {d: p.candidateCount, m: c.manual || 0, r: c.realpage || 0, h: c.hasWorkNumber || 0, dup: c.duplicate || 0});
				var body = esc(head) + samplesHtml(p.samples && p.samples.derive);
				if (p.candidateCount === 0) {
					setResult(esc(s.none));
				} else if (p.applyAllowed) {
					lastSig = p.signature; lastCount = p.candidateCount;
					$apply.prop('disabled', false);
					setResult(body + '<br><strong>' + esc(s.ready) + '</strong>');
				} else {
					lockApply();
					setResult(body + '<br><strong>' + esc(fill(s.locked, {n: p.candidateCount, t: p.threshold})) + '</strong><br><code>' + esc(p.cliCommand) + '</code>');
				}
			});
		});

		$apply.click(function() {
			if (!lastSig) return;
			if (!window.confirm(fill(s.applyConfirm, {n: lastCount}))) return;
			setResult(esc(s.applying));
			post(cfg.applyUrl, {csrfToken: csrf(), signature: lastSig}, function(p) {
				lockApply(); // a fresh Scan is required before another Apply
				if (p.error === 'stale') setResult(esc(s.stale));
				else if (p.error === 'nothing') setResult(esc(s.nothing));
				else if (p.error === 'aborted') setResult(esc(fill(s.aborted, {id: p.abortPubId})));
				else if (p.error === 'locked') setResult(esc(fill(s.locked, {n: p.liveCount, t: p.threshold})));
				else setResult(esc(fill(s.applyDone, {n: p.appliedCount})) + samplesHtml(p.samples && p.samples.derive));
			});
		});

		$('#anpMigrateRollback').click(function() {
			if (!window.confirm(s.rollbackConfirm)) return;
			setResult(esc(s.rolling));
			lockApply();
			post(cfg.rollbackUrl, {csrfToken: csrf()}, function(p) {
				setResult(esc(fill(s.rollbackDone, {n: p.removedCount})) + samplesHtml(p.samples && p.samples.derive));
			});
		});
	});
{/literal}
</script>
{/if}
