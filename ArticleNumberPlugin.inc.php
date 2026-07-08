<?php
/**
 * @file ArticleNumberPlugin.inc.php
 *
 * Article Number Plugin for OJS — main plugin class (OJS 3.3 generic plugin).
 *
 * Phase 1 scope (skeleton + schema + opt-in):
 *   - Registers a runtime `workNumber` (string, nullable) property on the
 *     publication schema via the `Schema::get::publication` hook.
 *   - Per-journal opt-in: the feature is OFF by default; each journal turns it
 *     on with the `enableWorkNumber` plugin setting. When OFF (or the plugin is
 *     disabled), nothing is injected and OJS behaves exactly as before.
 *   - Graceful hand-off guard: if core (or another plugin) ever defines
 *     `workNumber` natively, this plugin detects it and skips its own injection
 *     to avoid a clash — the data already lives in the right place.
 *
 * Naming contract (non-negotiable, see master prompt §1):
 *   - DATA is stored under PKP's internal property name `workNumber` in
 *     `publication_settings` (versioned, per-publication). This guarantees a
 *     zero-migration hand-off if/when core ships the property.
 *   - The user-visible LABEL is "Article Number" (localized).
 *   - This plugin's own global classes are uniquely prefixed `ArticleNumber*`
 *     so they never collide with OJS core classes.
 */

import('lib.pkp.classes.plugins.GenericPlugin');

class ArticleNumberPlugin extends GenericPlugin
{
    /** PKP-aligned publication property name (the stored data key). Do not change. */
    public const PROP_WORK_NUMBER = 'workNumber';

    /** Per-journal opt-in plugin setting name. */
    public const SETTING_ENABLE = 'enableWorkNumber';

    /** Per-journal: enable the optional sequential-suggestion generator. */
    public const SETTING_GENERATOR = 'workNumberGeneratorEnabled';

    /** Per-journal: the generator template (free-string output; never a constraint). */
    public const SETTING_TEMPLATE = 'workNumberTemplate';

    /** Default generator template: e0001, e0002, … */
    public const DEFAULT_TEMPLATE = 'e{:04d}';

    /**
     * Per-journal uniqueness scope for the Article Number:
     *   'journal' (default) — unique across the whole journal (PLoS / continuous model).
     *   'issue'             — unique only within the same issue.
     */
    public const SETTING_UNIQUENESS_SCOPE = 'workNumberUniquenessScope';

    /**
     * Per-journal: HIDE the plugin's own "Article Number" item in the
     * article-details block (1 = hide, unset = show). Stored as a truthy "hide"
     * flag on purpose: OJS's settings cache reads back a falsy value (0/false/'')
     * as null — indistinguishable from "unset" — so only a truthy state is
     * reliable. Default (unset) = show. Turn ON for themes that display the
     * Article Number themselves (e.g. Nivo/Atlas/Axis) to avoid showing it twice.
     */
    public const SETTING_HIDE_DETAILS_BLOCK = 'hideArticleNumberInDetails';

    /**
     * Per-journal: HIDE the Article Number in the issue table of contents
     * (1 = hide, unset = show). Same inverted-flag storage as
     * SETTING_HIDE_DETAILS_BLOCK (OJS reads falsy settings back as null). On by
     * default so digital-first journals show a locator in the TOC where the page
     * number normally appears; turn OFF for themes that render it there
     * themselves (via the {article_number} helper) to avoid a duplicate.
     */
    public const SETTING_HIDE_IN_TOC = 'hideArticleNumberInToc';

    /**
     * Per-journal cap on the in-panel "Apply" migration. When a Scan finds at
     * least this many candidate articles, the panel locks Apply and directs the
     * manager to the CLI tool (which has no web-request timeout). Scan and
     * Rollback are never threshold-limited. Overridable via the plugin setting.
     */
    public const SETTING_MIGRATION_THRESHOLD = 'workNumberMigrationThreshold';

    /** Default candidate-count cap for the in-panel Apply (see above). */
    public const DEFAULT_MIGRATION_THRESHOLD = 2000;

    /** Guards the once-per-request hand-off log line. */
    private static $handOffLogged = false;

    /** Guards once-per-request Smarty function registration. */
    private static $smartyRegistered = false;

    /**
     * Set true while the QuickSubmit form is rendering, so the shared
     * SubmissionMetadataForm::AdditionalMetadata hook only injects our field in
     * QuickSubmit (not in the normal submission wizard, which already has the
     * IssueEntryForm field).
     */
    private static $inQuickSubmit = false;

    /** Lazily-built single service instance (the plugin's data logic lives here). */
    private $service = null;

    /**
     * The plugin's single source of truth for uniqueness, page classification and
     * the migration engine. Hook callbacks and the (CLI / panel) migration paths
     * all delegate here so each rule has exactly one implementation.
     *
     * @return ArticleNumberService
     */
    public function getService()
    {
        if ($this->service === null) {
            $this->import('ArticleNumberService');
            $this->service = new ArticleNumberService($this);
        }
        return $this->service;
    }

    public function getDisplayName()
    {
        return __('plugins.generic.articleNumber.name');
    }

    public function getDescription()
    {
        return __('plugins.generic.articleNumber.description');
    }

    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if (!$success) {
            return false;
        }
        $this->addLocaleData();

        if ($this->getEnabled($mainContextId)) {
            // Inject the workNumber property into the publication schema at
            // runtime. The callback re-checks the per-journal opt-in and the
            // hand-off guard, so registering here unconditionally is safe.
            HookRegistry::register('Schema::get::publication', array($this, 'addWorkNumberToSchema'));

            // Phase 2: editor UI. Inject the "Article Number" field into the
            // publication issue-entry form, and enforce the published-coordinate
            // immutability rule on save. Both callbacks re-check the per-journal
            // opt-in, so registering here unconditionally is safe.
            HookRegistry::register('Form::config::before', array($this, 'addFieldToIssueEntryForm'));
            HookRegistry::register('Publication::validate', array($this, 'validateWorkNumberImmutability'));
            // Uniqueness: block saving a duplicate Article Number on a not-yet-
            // published article (per the journal's scope). Same hook, separate
            // callback. Covers the normal workflow (IssueEntryForm + any service
            // edit); QuickSubmit is covered by its own validate hook below.
            HookRegistry::register('Publication::validate', array($this, 'validateWorkNumberUniqueness'));
            HookRegistry::register('quicksubmitform::validate', array($this, 'validateQuickSubmitUniqueness'));

            // Phase 3: reader-facing. Show the Article Number on the article
            // landing page (theme-independent), and fix the Google Scholar meta
            // bug by suppressing citation_firstpage/lastpage when an article
            // number is the authoritative coordinate. The Scholar callback runs
            // LATE so it overrides the googleScholar plugin (which runs at the
            // default priority on the same hook).
            HookRegistry::register('Templates::Article::Details', array($this, 'displayArticleNumber'));
            // Reader-facing: show the Article Number in the issue table of contents,
            // where the page number normally appears (the core template only prints
            // `pages`, which is empty for digital-first articles).
            HookRegistry::register('Templates::Issue::Issue::Article', array($this, 'displayArticleNumberInToc'));
            HookRegistry::register('ArticleHandler::view', array($this, 'suppressScholarPages'), HOOK_SEQUENCE_LATE);
            // Expose a Smarty {article_number} helper so theme developers can
            // place the value wherever they want (e.g. in place of the page
            // range, in the theme's native location). See docs/THEME-INTEGRATION.md.
            HookRegistry::register('TemplateManager::display', array($this, 'registerSmartyHelpers'));

            // QuickSubmit (plugins/importexport/quickSubmit) support. QuickSubmit
            // is an old PKP Form, so we attach to its base-class hooks instead of
            // Form::config::before. Display goes through the core
            // SubmissionMetadataForm::AdditionalMetadata template hook, scoped to
            // QuickSubmit via a flag set on the QuickSubmit form's display hook so
            // the normal submission wizard (which already has the IssueEntry field)
            // is never affected. No fork; only workNumber is written.
            HookRegistry::register('quicksubmitform::display', array($this, 'markQuickSubmitContext'));
            HookRegistry::register('Templates::Submission::SubmissionMetadataForm::AdditionalMetadata', array($this, 'displayQuickSubmitWorkNumber'));
            HookRegistry::register('quicksubmitform::readuservars', array($this, 'readQuickSubmitWorkNumber'));
            HookRegistry::register('quicksubmitform::execute', array($this, 'saveQuickSubmitWorkNumber'));

            // Phase 4: Crossref export injection. The Crossref article filter
            // fires Filter::execute as `articlecrossrefxmlfilter::execute` with
            // the assembled DOMDocument by reference (Filter.inc.php). We attach
            // to that output, add <publisher_item><item_number> and drop <pages>
            // for articles that have a workNumber. The crossref plugin is never
            // forked or modified.
            HookRegistry::register('articlecrossrefxmlfilter::execute', array($this, 'injectCrossrefArticleNumber'));

            // Phase 5a: JATS bridge. The canonical JATS generation hook
            // (OAIMetadataFormat_JATS::findJats) hands us the generated JATS
            // DOMDocument; running LATE (after the jatsTemplate plugin builds it)
            // we replace <fpage>/<lpage> with <elocation-id> when a workNumber is
            // set, satisfying JATS4R (the two must never coexist).
            HookRegistry::register('OAIMetadataFormat_JATS::findJats', array($this, 'injectJatsElocationId'), HOOK_SEQUENCE_LATE);

            // Phase 5b: citation. Map the workNumber onto the CSL data before
            // citeproc renders it (the only available hook is pre-render).
            HookRegistry::register('CitationStyleLanguage::citation', array($this, 'mapCitationWorkNumber'));
            // IEEE's bundled CSL renders the article number in the page slot
            // ("p. <n>"); IEEE convention is "Art. no. <n>". There is no
            // post-render hook, so we refine the primary on-page citation here,
            // after the citationStyleLanguage plugin has assigned it (LATE).
            HookRegistry::register('ArticleHandler::view', array($this, 'refinePrimaryIeeeCitation'), HOOK_SEQUENCE_LATE);
        }

        return $success;
    }

    /**
     * Hook callback for `Schema::get::publication`.
     *
     * Adds a nullable, non-multilingual string property `workNumber` to the
     * publication schema. SchemaDAO then persists it automatically to
     * `publication_settings` (no custom DAO, correct versioning).
     *
     * @param string $hookName
     * @param array  $params [0] => stdClass schema (by reference)
     * @return bool false (never short-circuit the hook chain)
     */
    public function addWorkNumberToSchema($hookName, $params)
    {
        $schema =& $params[0];
        if (!is_object($schema) || !isset($schema->properties)) {
            return false;
        }

        // Graceful hand-off: native (or third-party) workNumber already present
        // → do not touch it. Log once so the operator can later retire us.
        if (isset($schema->properties->{self::PROP_WORK_NUMBER})) {
            if (!self::$handOffLogged) {
                error_log('[articleNumber] Native "workNumber" property detected on the publication schema; '
                    . 'skipping plugin schema injection (graceful hand-off).');
                self::$handOffLogged = true;
            }
            return false;
        }

        // Per-journal opt-in gate. When a journal context is resolvable and the
        // feature is OFF for it, leave the schema untouched (disabled == no
        // change). When no context is resolvable (CLI / site-level / global
        // schema load) inject anyway, so any already-stored workNumber values
        // still hydrate correctly regardless of UI state.
        $context = $this->_resolveContext();
        if ($context && !$this->isFeatureEnabled($context->getId())) {
            return false;
        }

        $schema->properties->{self::PROP_WORK_NUMBER} = (object) [
            'type' => 'string',
            'apiSummary' => true,
            'multilingual' => false,
            'validation' => ['nullable'],
        ];

        return false;
    }

    /**
     * Whether the Article Number feature is switched on for a given journal.
     * Used by this and later phases (form field, export, frontend display).
     *
     * @param int $contextId
     * @return bool
     */
    public function isFeatureEnabled($contextId)
    {
        return (bool) $this->getSetting($contextId, self::SETTING_ENABLE);
    }

    /**
     * Resolve the current journal context, if any.
     * @return Context|null
     */
    private function _resolveContext()
    {
        $request = Application::get()->getRequest();
        return $request ? $request->getContext() : null;
    }

    /**
     * Hook callback for `Form::config::before`.
     *
     * Injects the "Article Number" text field directly after the core "pages"
     * field on the publication issue-entry form, but only when (a) this is the
     * issue-entry form and (b) the feature is enabled for the current journal.
     * The field's value is pre-populated from the publication referenced in the
     * form's API action URL. Core fields are never modified.
     *
     * @param string        $hookName
     * @param FormComponent $form The form being configured (passed by ref by core)
     * @return bool false
     */
    public function addFieldToIssueEntryForm($hookName, $form)
    {
        // The issue-entry form's id is the literal 'issueEntry' (FORM_ISSUE_ENTRY).
        // Compare to the literal so we never touch unrelated forms and never
        // depend on a constant that may not be defined for those forms.
        if (!is_object($form) || empty($form->id) || $form->id !== 'issueEntry') {
            return false;
        }

        $context = $this->_resolveContext();
        if (!$context || !$this->isFeatureEnabled($context->getId())) {
            return false;
        }

        // Pre-populate from the publication referenced in the form action URL
        // (.../submissions/{id}/publications/{publicationId}).
        $contextId = $context->getId();
        $value = '';
        $publication = null;
        if (!empty($form->action) && preg_match('#/publications/(\d+)#', $form->action, $m)) {
            $publication = \Services::get('publication')->get((int) $m[1]);
            if ($publication) {
                $value = $publication->getData(self::PROP_WORK_NUMBER);
            }
        }

        $description = __('plugins.generic.articleNumber.field.description');

        // Phase 6.5 — optional generator. When enabled, and only for an
        // as-yet-unnumbered, NOT-yet-published article in an assigned issue,
        // pre-fill an editable suggestion (next sequential value in that
        // journal+issue). The value remains a free string the editor can change
        // or clear. Never applies to published articles; never touches `pages`.
        // Surface the suggestion in the help text (the OJS workflow re-syncs a
        // field's reactive value from the publication, so a pre-filled value
        // would be overwritten — the description is the reliable, non-destructive
        // place for a suggestion, and it keeps "suggestion" semantics: the editor
        // chooses to enter it). Only for an unnumbered, NOT-yet-published article
        // in an assigned issue, when the generator is enabled.
        $isPublished = $publication && (int) $publication->getData('status') === STATUS_PUBLISHED;
        if (($value === null || $value === '')
                && $publication
                && !$isPublished
                && $this->getSetting($contextId, self::SETTING_GENERATOR)
                && $publication->getData('issueId')) {
            $template = (string) ($this->getSetting($contextId, self::SETTING_TEMPLATE) ?: self::DEFAULT_TEMPLATE);
            $next = $this->_nextNumberForIssue((int) $publication->getData('issueId'), $template);
            $suggestion = $this->_formatTemplate($template, $next);
            $description .= '<br><em>' . __('plugins.generic.articleNumber.generator.suggested', array('number' => htmlspecialchars($suggestion))) . '</em>';
        }

        // Predictive collision notice in the help text (the hard block happens on
        // save via Publication::validate). Uses the journal's uniqueness scope.
        if ($value !== null && $value !== '' && $publication) {
            $scope = $this->getUniquenessScope($contextId);
            if ($this->isWorkNumberTaken($contextId, (string) $value, (int) $publication->getData('submissionId'), $scope, $publication->getData('issueId'))) {
                $description .= '<br><strong>' . __('plugins.generic.articleNumber.generator.collision') . '</strong>';
            }
        }

        import('lib.pkp.classes.components.forms.FieldText');
        $field = new \PKP\components\forms\FieldText(self::PROP_WORK_NUMBER, [
            'label' => __('plugins.generic.articleNumber.field.label'),
            'description' => $description,
            'value' => $value,
        ]);

        // Insert immediately after the core "pages" field. addToPosition is a
        // no-op-safe append if "pages" is absent.
        $form->addField($field, array(FIELD_POSITION_AFTER, 'pages'));

        return false;
    }

    /**
     * Compute the next sequential number within a journal+issue scope:
     * (highest integer found in any workNumber in that issue) + 1.
     *
     * @param int    $issueId
     * @param string $template (unused for extraction; we read the max integer)
     * @return int
     */
    private function _nextNumberForIssue($issueId, $template)
    {
        $pubIds = $this->_issuePublicationIds($issueId);
        if (empty($pubIds)) {
            return 1;
        }
        $dao = DAORegistry::getDAO('PublicationDAO');
        $in = implode(',', array_map('intval', $pubIds));
        $max = 0;
        foreach ($dao->retrieve(
            "SELECT setting_value FROM publication_settings WHERE setting_name = '" . self::PROP_WORK_NUMBER . "' AND publication_id IN ($in)"
        ) as $row) {
            $n = $this->_extractTrailingInt($row->setting_value);
            if ($n !== null && $n > $max) {
                $max = $n;
            }
        }
        return $max + 1;
    }

    /**
     * All publication ids assigned to a given issue (issueId is a publication
     * setting in OJS 3.3).
     *
     * @param int $issueId
     * @return int[]
     */
    private function _issuePublicationIds($issueId)
    {
        $dao = DAORegistry::getDAO('PublicationDAO');
        $ids = array();
        foreach ($dao->retrieve(
            'SELECT publication_id FROM publication_settings WHERE setting_name = ? AND setting_value = ? AND locale = ?',
            array('issueId', (string) $issueId, '')
        ) as $row) {
            $ids[] = (int) $row->publication_id;
        }
        return $ids;
    }

    /**
     * Extract the last run of digits from a value, as an int (e.g. e0001 -> 1).
     *
     * @param string $value
     * @return int|null
     */
    private function _extractTrailingInt($value)
    {
        if (preg_match('/(\d+)(?!.*\d)/', (string) $value, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    /**
     * Render a generator template into a free-string value.
     * Supports a single counter token: `{:0Nd}` (zero-padded width N), `{:d}` or
     * `{n}` (plain). All other characters are literal.
     *
     * @param string $template
     * @param int    $n
     * @return string
     */
    private function _formatTemplate($template, $n)
    {
        $out = preg_replace_callback('/\{:0(\d+)d\}/', function ($mm) use ($n) {
            return str_pad((string) $n, (int) $mm[1], '0', STR_PAD_LEFT);
        }, $template);
        $out = str_replace(array('{:d}', '{n}'), (string) $n, $out);
        return $out;
    }

    /**
     * Hook callback for `Templates::Article::Details`.
     *
     * Appends an "Article Number: X" item to the article landing page's
     * entry-details column. Theme-independent (every theme calls this hook).
     * Renders nothing unless the feature is enabled and a workNumber is set.
     *
     * @param string $hookName
     * @param array  $args [1]=>&$templateMgr, [2]=>&$output
     * @return bool false
     */
    public function displayArticleNumber($hookName, $args)
    {
        $templateMgr =& $args[1];
        $output =& $args[2];

        $context = $this->_resolveContext();
        if (!$context || !$this->isFeatureEnabled($context->getId())) {
            return false;
        }

        // Per-journal opt-out: a theme that renders the Article Number itself
        // sets the truthy "hide" flag to suppress this plugin's own item and
        // avoid a duplicate. Default (unset) shows it.
        if ($this->getSetting($context->getId(), self::SETTING_HIDE_DETAILS_BLOCK)) {
            return false;
        }

        $publication = $templateMgr->getTemplateVars('publication');
        if (!is_object($publication)) {
            return false;
        }
        $workNumber = $publication->getData(self::PROP_WORK_NUMBER);
        if ($workNumber === null || $workNumber === '') {
            return false;
        }

        $templateMgr->assign('articleNumberValue', $workNumber);
        $output .= $templateMgr->fetch($this->getTemplateResource('frontend/articleNumber.tpl'));

        return false;
    }

    /**
     * Hook callback for `Templates::Issue::Issue::Article` — shows the Article
     * Number in the issue table of contents, in the place a page number would
     * normally occupy. Mirrors displayArticleNumber() but is gated by its own
     * per-journal toggle (SETTING_HIDE_IN_TOC).
     *
     * @param string $hookName
     * @param array  $args [0]=>&$params, [1]=>$templateMgr, [2]=>&$output
     * @return bool false
     */
    public function displayArticleNumberInToc($hookName, $args)
    {
        $templateMgr =& $args[1];
        $output =& $args[2];

        $context = $this->_resolveContext();
        if (!$context || !$this->isFeatureEnabled($context->getId())) {
            return false;
        }
        // Per-journal opt-out (default shows). Turn ON when the theme renders the
        // Article Number in the TOC itself, to avoid a duplicate.
        if ($this->getSetting($context->getId(), self::SETTING_HIDE_IN_TOC)) {
            return false;
        }

        $publication = $templateMgr->getTemplateVars('publication');
        if (!is_object($publication)) {
            return false;
        }
        $workNumber = $publication->getData(self::PROP_WORK_NUMBER);
        if ($workNumber === null || $workNumber === '') {
            return false;
        }

        $templateMgr->assign('articleNumberValue', $workNumber);
        $output .= $templateMgr->fetch($this->getTemplateResource('frontend/articleNumberToc.tpl'));

        return false;
    }

    /**
     * Hook callback for `quicksubmitform::display`. Marks that the QuickSubmit
     * form is rendering, so the shared AdditionalMetadata hook only injects our
     * field here (and not in the normal submission wizard). Returns false so the
     * form renders normally.
     *
     * @param string $hookName
     * @param array  $args
     * @return bool false
     */
    public function markQuickSubmitContext($hookName, $args)
    {
        self::$inQuickSubmit = true;
        return false;
    }

    /**
     * Hook callback for `Templates::Submission::SubmissionMetadataForm::AdditionalMetadata`.
     *
     * Injects the "Article Number" field into the submission-metadata section,
     * but ONLY while QuickSubmit is rendering and the feature is enabled for the
     * journal. Value comes from the form's submitted data (so it survives a
     * validation re-display) or, failing that, the publication's stored value.
     *
     * @param string $hookName
     * @param array  $args [0]=>&$params, [1]=>$smarty, [2]=>&$output
     * @return bool false
     */
    public function displayQuickSubmitWorkNumber($hookName, $args)
    {
        if (!self::$inQuickSubmit) {
            return false; // normal submission wizard — leave it to the IssueEntry field
        }
        $context = $this->_resolveContext();
        if (!$context || !$this->isFeatureEnabled($context->getId())) {
            return false;
        }

        $smarty = $args[1];
        $output =& $args[2];

        // Prefill: submitted form value first (survives validation errors), then
        // the publication's stored value.
        $value = $smarty->getTemplateVars(self::PROP_WORK_NUMBER);
        if ($value === null || $value === '') {
            $publicationId = $smarty->getTemplateVars('publicationId');
            if ($publicationId) {
                $publication = \Services::get('publication')->get((int) $publicationId);
                if ($publication) {
                    $value = $publication->getData(self::PROP_WORK_NUMBER);
                }
            }
        }

        $smarty->assign('articleNumberQsValue', ($value === null) ? '' : $value);
        $output .= $smarty->fetch($this->getTemplateResource('quickSubmitField.tpl'));

        return false;
    }

    /**
     * Hook callback for `quicksubmitform::readuservars`. Adds `workNumber` to the
     * list of request vars the form reads, so the submitted value lands in the
     * form's data (and is preserved on a validation re-display).
     *
     * @param string $hookName
     * @param array  $args [0]=>$form, [1]=>&$vars
     * @return bool false
     */
    public function readQuickSubmitWorkNumber($hookName, $args)
    {
        $context = $this->_resolveContext();
        if ($context && $this->isFeatureEnabled($context->getId())) {
            $vars =& $args[1];
            $vars[] = self::PROP_WORK_NUMBER;
        }
        return false;
    }

    /**
     * Hook callback for `quicksubmitform::execute` (fires inside the form's
     * parent::execute(), BEFORE QuickSubmit's publish step). Writes ONLY the
     * workNumber setting on the submission's current publication. The value
     * survives QuickSubmit's subsequent re-fetch + publish (it is a schema
     * property) and persists for queued submissions too. `pages` and every other
     * core field are left untouched.
     *
     * @param string $hookName
     * @param array  $args [0]=>$form, …
     * @return bool false
     */
    public function saveQuickSubmitWorkNumber($hookName, $args)
    {
        $form = $args[0];
        if (!is_object($form) || !method_exists($form, 'getData')) {
            return false;
        }
        $context = $this->_resolveContext();
        if (!$context || !$this->isFeatureEnabled($context->getId())) {
            return false;
        }

        $submissionId = $form->getData('submissionId');
        if (!$submissionId) {
            return false;
        }
        $submission = \Services::get('submission')->get((int) $submissionId);
        if (!$submission) {
            return false;
        }
        $publication = $submission->getCurrentPublication();
        if (!$publication) {
            return false;
        }

        // Write ONLY our workNumber setting directly (never touch pages or other
        // core fields). Mirrors the migration tool's direct-write approach.
        $dao = DAORegistry::getDAO('PublicationDAO');
        $pubId = (int) $publication->getId();
        $raw = $form->getData(self::PROP_WORK_NUMBER);
        $workNumber = ($raw === null) ? '' : trim((string) $raw);

        if ($workNumber === '') {
            $dao->update(
                'DELETE FROM publication_settings WHERE publication_id = ? AND setting_name = ? AND locale = ?',
                array($pubId, self::PROP_WORK_NUMBER, '')
            );
        } else {
            $dao->update(
                'INSERT INTO publication_settings (publication_id, locale, setting_name, setting_value) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
                array($pubId, '', self::PROP_WORK_NUMBER, $workNumber)
            );
        }

        return false;
    }

    /**
     * Hook callback for `TemplateManager::display`. Registers the Smarty
     * `{article_number}` function once per request so theme templates can read
     * and place the Article Number themselves.
     *
     * @param string $hookName
     * @param array  $args [0] => TemplateManager
     * @return bool false
     */
    public function registerSmartyHelpers($hookName, $args)
    {
        if (self::$smartyRegistered) {
            return false;
        }
        $templateMgr = $args[0];
        if (!is_object($templateMgr) || !method_exists($templateMgr, 'registerPlugin')) {
            return false;
        }
        $templateMgr->registerPlugin('function', 'article_number', array($this, 'smartyArticleNumber'));
        self::$smartyRegistered = true;
        return false;
    }

    /**
     * Smarty `{article_number}` function.
     *
     *   {article_number publication=$publication}                 outputs the value (escaped)
     *   {article_number publication=$publication assign="artNo"}  assigns it to $artNo, outputs nothing
     *
     * Falls back to the template's $publication if the parameter is omitted.
     * Returns an empty string when no Article Number is set, so themes can do
     * an {if} test cleanly.
     *
     * @param array  $params
     * @param object $smarty Smarty template
     * @return string
     */
    public function smartyArticleNumber($params, $smarty)
    {
        $publication = isset($params['publication']) ? $params['publication'] : $smarty->getTemplateVars('publication');
        $value = is_object($publication) ? $publication->getData(self::PROP_WORK_NUMBER) : null;
        $value = ($value === null) ? '' : (string) $value;

        if (!empty($params['assign'])) {
            $smarty->assign($params['assign'], $value);
            return '';
        }
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Hook callback for `ArticleHandler::view` (registered at HOOK_SEQUENCE_LATE).
     *
     * Google Scholar has no metadata tag for an article number; when editors put
     * the value in `pages`, the googleScholar plugin emits a fake
     * citation_firstpage == citation_lastpage, corrupting the indexed record.
     * When a real workNumber is the authoritative coordinate, we blank the two
     * page-meta header slots the googleScholar plugin filled (it runs at the
     * default priority on this same hook; we run LATE), so they render nothing.
     * Core is never modified and the googleScholar plugin is never forked.
     *
     * @param string $hookName
     * @param array  $args [0]=>$request, [1]=>$issue, [2]=>$submission
     * @return bool false
     */
    public function suppressScholarPages($hookName, $args)
    {
        $request = $args[0];
        $submission = isset($args[2]) ? $args[2] : null;
        if (!$request || !is_object($submission)) {
            return false;
        }

        $context = $request->getContext();
        if (!$context || !$this->isFeatureEnabled($context->getId())) {
            return false;
        }

        $publication = $submission->getCurrentPublication();
        if (!$publication) {
            return false;
        }
        $workNumber = $publication->getData(self::PROP_WORK_NUMBER);
        if ($workNumber === null || $workNumber === '') {
            return false;
        }

        // Blank the same header slots the googleScholar plugin uses. addHeader()
        // keys by name (default priority/context), so this overwrites its entry
        // with an empty string -> no firstpage/lastpage tag is rendered.
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->addHeader('googleScholarStartPage', '');
        $templateMgr->addHeader('googleScholarEndPage', '');

        return false;
    }

    /**
     * Hook callback for `articlecrossrefxmlfilter::execute` (Crossref export).
     *
     * Post-processes the assembled Crossref DOMDocument: for every journal_article
     * whose publication has a workNumber, inject
     *   <publisher_item><item_number item_number_type="article_number">…</item_number></publisher_item>
     * and remove the <pages> block (Crossref treats article number and page range
     * as mutually exclusive — the article number is the authoritative coordinate).
     * Articles without a workNumber are left exactly as the core filter produced
     * them (no regression). The crossref plugin is never forked.
     *
     * @param string $hookName
     * @param array  $args [0]=>&$preliminaryOutput (DOMDocument)
     * @return bool false
     */
    public function injectCrossrefArticleNumber($hookName, $args)
    {
        $doc =& $args[0];
        if (!($doc instanceof DOMDocument)) {
            return false;
        }

        $submissionService = \Services::get('submission');

        // Snapshot the node list first: we mutate the tree while iterating.
        $articleNodes = array();
        foreach ($doc->getElementsByTagName('journal_article') as $node) {
            $articleNodes[] = $node;
        }

        foreach ($articleNodes as $articleNode) {
            // Resolve the submission for this node from its <doi_data>/<resource>
            // URL. We gate on the SUBMISSION's own journal (not the request
            // context), so this works for both web and CLI export and is correct
            // even if a single export ever mixed journals.
            $submission = $this->_resolveSubmissionForArticleNode($articleNode, $submissionService);
            if (!$submission || !$this->isFeatureEnabled($submission->getContextId())) {
                continue;
            }
            $publication = $submission->getCurrentPublication();
            $workNumber = $publication ? $publication->getData(self::PROP_WORK_NUMBER) : null;
            if ($workNumber === null || $workNumber === '') {
                continue; // page-based article: leave untouched
            }

            $ns = $articleNode->namespaceURI;

            $publisherItem = $doc->createElementNS($ns, 'publisher_item');
            $itemNumber = $doc->createElementNS($ns, 'item_number');
            $itemNumber->setAttribute('item_number_type', 'article_number');
            $itemNumber->appendChild($doc->createTextNode((string) $workNumber));
            $publisherItem->appendChild($itemNumber);

            // Crossref child order: … publication_date, pages, publisher_item,
            // ai:program, doi_data … Place publisher_item in the pages slot; if
            // there is no pages node, insert before ai:program (license) or doi_data.
            $pagesNode = $this->_firstChildByLocalName($articleNode, 'pages');
            if ($pagesNode) {
                $articleNode->insertBefore($publisherItem, $pagesNode);
                $articleNode->removeChild($pagesNode); // mutual exclusion: drop pages
            } else {
                $anchor = $this->_firstChildByLocalName($articleNode, 'program'); // ai:program
                if (!$anchor) {
                    $anchor = $this->_firstChildByLocalName($articleNode, 'doi_data');
                }
                if ($anchor) {
                    $articleNode->insertBefore($publisherItem, $anchor);
                } else {
                    $articleNode->appendChild($publisherItem);
                }
            }
        }

        return false;
    }

    /**
     * Resolve the Submission for a Crossref journal_article node by reading its
     * <doi_data>/<resource> article URL (…/{contextPath}/article/view/{bestId}).
     * Request-context independent, so it works under web and CLI export alike.
     *
     * @param DOMElement $articleNode
     * @param mixed      $submissionService
     * @return Submission|null
     */
    private function _resolveSubmissionForArticleNode($articleNode, $submissionService)
    {
        $doiData = $this->_firstChildByLocalName($articleNode, 'doi_data');
        if (!$doiData) {
            return null;
        }
        $resource = $this->_firstChildByLocalName($doiData, 'resource');
        if (!$resource) {
            return null;
        }
        $url = trim($resource->textContent);
        if ($url === '' || !preg_match('#/([^/]+)/article/view/([^/?\#]+)#', $url, $m)) {
            return null;
        }
        $contextPath = urldecode($m[1]);
        $bestId = urldecode($m[2]);

        if (ctype_digit($bestId)) {
            return $submissionService->get((int) $bestId);
        }
        // Non-numeric best id is a urlPath, which requires the journal id.
        $context = Application::getContextDAO()->getByPath($contextPath);
        if (!$context) {
            return null;
        }
        return $submissionService->getByUrlPath($bestId, $context->getId());
    }

    /**
     * Hook callback for `OAIMetadataFormat_JATS::findJats` (registered LATE).
     *
     * After the jatsTemplate plugin builds the JATS DOMDocument, replace
     * <fpage>/<lpage> with <elocation-id> when the article has a workNumber.
     * JATS4R: elocation-id and fpage/lpage must never coexist.
     *
     * @param string $hookName
     * @param array  $args [0]=>$plugin,[1]=>$record,[2]=>$candidateFiles,[3]=>&$doc
     * @return bool false
     */
    public function injectJatsElocationId($hookName, $args)
    {
        $record = isset($args[1]) ? $args[1] : null;
        $doc =& $args[3];
        if (!($doc instanceof DOMDocument) || !is_object($record)) {
            return false;
        }

        $article = $record->getData('article');
        if (!is_object($article)) {
            return false;
        }
        // Gate on the article's own journal (request-context independent).
        $contextId = method_exists($article, 'getContextId') ? $article->getContextId() : null;
        if (!$contextId || !$this->isFeatureEnabled($contextId)) {
            return false;
        }
        $publication = $article->getCurrentPublication();
        $workNumber = $publication ? $publication->getData(self::PROP_WORK_NUMBER) : null;
        if ($workNumber === null || $workNumber === '') {
            return false;
        }

        $this->_replacePagesWithElocationId($doc, (string) $workNumber);
        return false;
    }

    /**
     * Hook callback for `ArticleHandler::view` (registered LATE).
     *
     * When the journal's primary citation style is IEEE and the article has a
     * workNumber, rewrite the rendered locator "p./pp. <n>" to IEEE's
     * "Art. no. <n>" on the primary on-page citation. Only the default on-page
     * citation is affected (the only one available as a template var without a
     * post-render hook); AJAX style-switching is unaffected.
     *
     * @param string $hookName
     * @param array  $args [0]=>$request,[1]=>$issue,[2]=>$submission
     * @return bool false
     */
    public function refinePrimaryIeeeCitation($hookName, $args)
    {
        $request = $args[0];
        $submission = isset($args[2]) ? $args[2] : null;
        if (!$request || !is_object($submission)) {
            return false;
        }
        $context = $request->getContext();
        if (!$context || !$this->isFeatureEnabled($context->getId())) {
            return false;
        }
        $publication = $submission->getCurrentPublication();
        $workNumber = $publication ? $publication->getData(self::PROP_WORK_NUMBER) : null;
        if ($workNumber === null || $workNumber === '') {
            return false;
        }

        // Only IEEE needs the label fix; APA already renders "Article <n>".
        $csl = PluginRegistry::getPlugin('generic', 'citationstylelanguageplugin');
        if (!$csl || !method_exists($csl, 'getPrimaryStyleName')) {
            return false;
        }
        if (stripos((string) $csl->getPrimaryStyleName($context->getId()), 'ieee') === false) {
            return false;
        }

        $templateMgr = TemplateManager::getManager($request);
        $citation = $templateMgr->getTemplateVars('citation');
        if (!is_string($citation) || $citation === '') {
            return false;
        }

        $fixed = preg_replace(
            '/\bpp?\.\s*' . preg_quote($workNumber, '/') . '/u',
            'Art. no. ' . $workNumber,
            $citation
        );
        if ($fixed !== null && $fixed !== $citation) {
            $templateMgr->assign('citation', $fixed);
        }

        return false;
    }

    /**
     * Replace every <fpage>/<lpage> in a JATS document with a single
     * <elocation-id> carrying the article number. The elocation-id takes the
     * slot of the first <fpage> (correct JATS article-meta ordering); if there
     * is no <fpage>, it is inserted before <permissions> or appended to
     * <article-meta>.
     *
     * @param DOMDocument $doc
     * @param string      $workNumber
     */
    private function _replacePagesWithElocationId($doc, $workNumber)
    {
        $fpages = array();
        foreach ($doc->getElementsByTagName('fpage') as $n) { $fpages[] = $n; }
        $lpages = array();
        foreach ($doc->getElementsByTagName('lpage') as $n) { $lpages[] = $n; }

        $elocation = $doc->createElement('elocation-id');
        $elocation->appendChild($doc->createTextNode($workNumber));

        if (!empty($fpages)) {
            $first = $fpages[0];
            $first->parentNode->insertBefore($elocation, $first);
        } else {
            // No page elements: place inside <article-meta> before <permissions>.
            $articleMeta = $doc->getElementsByTagName('article-meta')->item(0);
            if (!$articleMeta) {
                return; // nothing sensible to attach to
            }
            $permissions = $this->_firstChildByLocalName($articleMeta, 'permissions');
            if ($permissions) {
                $articleMeta->insertBefore($elocation, $permissions);
            } else {
                $articleMeta->appendChild($elocation);
            }
        }

        // Remove the page-range elements (mutual exclusion with elocation-id).
        foreach (array_merge($fpages, $lpages) as $n) {
            if ($n->parentNode) {
                $n->parentNode->removeChild($n);
            }
        }
    }

    /**
     * Hook callback for `CitationStyleLanguage::citation` (pre-render).
     *
     * STYLE-AWARE mapping (Path A). The workNumber is fed onto the transient CSL
     * data differently depending on what the rendered style's bundled CSL does
     * with the `number` variable for `article-journal` — established by auditing
     * the 10 packaged styles:
     *
     *   - Group 1 — renders `number` natively for article-journal (MLA): give
     *     ONLY `number`, leave `page` empty. Setting both (the old behaviour)
     *     double-printed the locator (e.g. "…e0500, May 2019, p. e0500").
     *   - APA — the bundled APA renders the `page` slot verbatim (unlabeled) and
     *     never surfaces `number` for article-journal, so we keep injecting a
     *     localized "Article <n>" as the page value (reference output).
     *   - Group 2 — does not surface `number` for article-journal (ACM, ACS,
     *     Chicago, Turabian, Vancouver, ABNT, Harvard, IEEE): the number must
     *     ride in `page` or the locator disappears entirely. ABNT/Harvard/IEEE
     *     force a "p." label on `page` that cannot be removed without forking the
     *     CSL file (a documented, accepted limitation); the others render it
     *     cleanly unlabeled.
     *
     * Unknown / custom styles fall through to Group 2 (page), the safe default
     * that guarantees the locator is never lost. The stored `pages` field is
     * never read or written — only this transient CSL array.
     *
     * @param string $hookName
     * @param array  $args [0]=>&$citationData,[1]=>&$citationStyle,[2]=>$article,[3]=>$issue,[4]=>$context,[5]=>$publication
     * @return bool false
     */
    public function mapCitationWorkNumber($hookName, $args)
    {
        $citationData =& $args[0];
        $citationStyle = isset($args[1]) ? $args[1] : '';
        $context = isset($args[4]) ? $args[4] : null;
        $publication = isset($args[5]) ? $args[5] : null;

        if (!$context || !$this->isFeatureEnabled($context->getId()) || !is_object($publication)) {
            return false;
        }
        $workNumber = $publication->getData(self::PROP_WORK_NUMBER);
        if ($workNumber === null || $workNumber === '') {
            return false;
        }

        // Start from a clean slate: drop any real page range (the article number
        // is the authoritative coordinate) and clear `number` so only the slot
        // chosen below is populated.
        unset($citationData->page);
        unset($citationData->number);

        $styleId = strtolower((string) $citationStyle);

        // Styles that render `number` natively for article-journal → number only.
        $nativeNumberStyles = array('modern-language-association');

        if (stripos($styleId, 'apa') !== false) {
            // APA (Group 2, special): inject the labeled locator into `page`.
            $citationData->page = __('plugins.generic.articleNumber.citation.articleLabel') . ' ' . $workNumber;
        } elseif (in_array($styleId, $nativeNumberStyles, true) || stripos($styleId, 'modern-language') !== false) {
            // Group 1 (native number): single, unlabeled locator; page stays empty.
            $citationData->number = (string) $workNumber;
        } else {
            // Group 2 (no native number): the number must ride in `page` or the
            // locator is lost. (ABNT/Harvard/IEEE add an unavoidable "p." label.)
            $citationData->page = (string) $workNumber;
        }

        return false;
    }

    /**
     * Return the first direct child element of $parent whose localName matches,
     * or null. Namespace-prefix agnostic.
     *
     * @param DOMNode $parent
     * @param string  $localName
     * @return DOMElement|null
     */
    private function _firstChildByLocalName($parent, $localName)
    {
        foreach ($parent->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE && $child->localName === $localName) {
                return $child;
            }
        }
        return null;
    }

    /**
     * Hook callback for `Publication::validate`.
     *
     * Enforces immutability of the authoritative coordinate (Principle #6):
     * once a publication is published, its Article Number cannot be changed (or
     * set for the first time) through the editor UI. The sanctioned path for
     * back-filling published articles is the migration tool (Phase 6), which
     * writes directly rather than through this validated edit flow.
     *
     * @param string $hookName
     * @param array  $args [0]=>&$errors, [1]=>$action, [2]=>$props, ...
     * @return bool false
     */
    public function validateWorkNumberImmutability($hookName, $args)
    {
        $errors =& $args[0];
        $action = $args[1];
        $props = $args[2];

        // Only act when the workNumber is part of this edit.
        if (!is_array($props) || !array_key_exists(self::PROP_WORK_NUMBER, $props)) {
            return false;
        }
        // Immutability only applies to edits of an existing publication.
        if ($action !== VALIDATE_ACTION_EDIT || empty($props['id'])) {
            return false;
        }

        $publication = \Services::get('publication')->get((int) $props['id']);
        if (!$publication) {
            return false;
        }

        // Gate on the per-journal opt-in for this publication's journal.
        $submission = \Services::get('submission')->get($publication->getData('submissionId'));
        $contextId = $submission ? $submission->getData('contextId') : null;
        if (!$contextId || !$this->isFeatureEnabled($contextId)) {
            return false;
        }

        // Block any change to the Article Number on an already-published article.
        if ((int) $publication->getData('status') === STATUS_PUBLISHED) {
            $old = (string) $publication->getData(self::PROP_WORK_NUMBER);
            $new = (string) $props[self::PROP_WORK_NUMBER];
            if ($old !== $new) {
                $errors[self::PROP_WORK_NUMBER] = array(
                    __('plugins.generic.articleNumber.field.error.published'),
                );
            }
        }

        return false;
    }

    /**
     * Resolve the per-journal uniqueness scope ('journal' default, or 'issue').
     *
     * @param int $contextId
     * @return string
     */
    public function getUniquenessScope($contextId)
    {
        return $this->getService()->getUniquenessScope($contextId);
    }

    /**
     * Single source of truth: is $value already used as a workNumber by a
     * DIFFERENT submission, within the configured scope? Exact (trimmed) string
     * match; empty value is never a collision. Excludes all publications of
     * $excludeSubmissionId (so an article's own value, across its versions, never
     * self-collides).
     *
     * @param int    $contextId
     * @param string $value
     * @param int    $excludeSubmissionId
     * @param string $scope 'journal' | 'issue'
     * @param mixed  $issueId required for 'issue' scope
     * @return bool
     */
    public function isWorkNumberTaken($contextId, $value, $excludeSubmissionId, $scope, $issueId = null)
    {
        return $this->getService()->isWorkNumberTaken($contextId, $value, $excludeSubmissionId, $scope, $issueId);
    }

    /**
     * Hook callback for `Publication::validate`. Blocks saving a DUPLICATE
     * Article Number on a not-yet-published article (within the journal's scope).
     * Published articles are handled by the immutability validator and are left
     * to it here.
     *
     * @param string $hookName
     * @param array  $args [0]=>&$errors, [1]=>$action, [2]=>$props, ...
     * @return bool false
     */
    public function validateWorkNumberUniqueness($hookName, $args)
    {
        $errors =& $args[0];
        $props = $args[2];

        if (!is_array($props) || !array_key_exists(self::PROP_WORK_NUMBER, $props)) {
            return false;
        }
        $value = trim((string) $props[self::PROP_WORK_NUMBER]);
        if ($value === '') {
            return false;
        }

        // Resolve the submission / issue / status for this publication.
        // status null = treat as not-yet-published (e.g. ADD) → uniqueness applies.
        $submissionId = null;
        $issueId = isset($props['issueId']) ? $props['issueId'] : null;
        $status = null;
        if (!empty($props['id'])) {
            $publication = \Services::get('publication')->get((int) $props['id']);
            if (!$publication) {
                return false;
            }
            $submissionId = $publication->getData('submissionId');
            if ($issueId === null) {
                $issueId = $publication->getData('issueId');
            }
            $status = $publication->getData('status');
        } elseif (!empty($props['submissionId'])) {
            $submissionId = $props['submissionId'];
        }
        if (!$submissionId) {
            return false;
        }

        $submission = \Services::get('submission')->get((int) $submissionId);
        $contextId = $submission ? $submission->getData('contextId') : null;
        if (!$contextId || !$this->isFeatureEnabled($contextId)) {
            return false;
        }
        // Published is the immutability validator's job; uniqueness guards the
        // not-yet-published assignment.
        if ($status !== null && (int) $status === STATUS_PUBLISHED) {
            return false;
        }

        if ($this->isWorkNumberTaken($contextId, $value, (int) $submissionId, $this->getUniquenessScope($contextId), $issueId)) {
            $errors[self::PROP_WORK_NUMBER] = array(__('plugins.generic.articleNumber.uniqueness.error'));
        }
        return false;
    }

    /**
     * Hook callback for `quicksubmitform::validate`. Blocks a QuickSubmit save
     * when the entered Article Number duplicates another in the journal's scope.
     * Adds a form error (which makes the form invalid, so execute never runs and
     * the value is not written).
     *
     * @param string $hookName
     * @param array  $args [0]=>$form, [1]=>&$value
     * @return bool false
     */
    public function validateQuickSubmitUniqueness($hookName, $args)
    {
        $form = $args[0];
        if (!is_object($form) || !method_exists($form, 'getData')) {
            return false;
        }
        $context = $this->_resolveContext();
        if (!$context || !$this->isFeatureEnabled($context->getId())) {
            return false;
        }
        $value = trim((string) $form->getData(self::PROP_WORK_NUMBER));
        if ($value === '') {
            return false;
        }
        $submissionId = $form->getData('submissionId');
        if (!$submissionId) {
            return false;
        }
        $issueId = $form->getData('issueId'); // only set when scheduling for publication

        if ($this->isWorkNumberTaken($context->getId(), $value, (int) $submissionId, $this->getUniquenessScope($context->getId()), $issueId ?: null)) {
            if (method_exists($form, 'addError')) {
                $form->addError(self::PROP_WORK_NUMBER, __('plugins.generic.articleNumber.uniqueness.error'));
            }
            if (method_exists($form, 'addErrorField')) {
                $form->addErrorField(self::PROP_WORK_NUMBER);
            }
        }
        return false;
    }

    /**
     * @copydoc Plugin::getActions()
     * Adds the "Settings" link to the plugin row when enabled.
     */
    public function getActions($request, $actionArgs)
    {
        $actions = parent::getActions($request, $actionArgs);
        if (!$this->getEnabled()) {
            return $actions;
        }

        import('lib.pkp.classes.linkAction.request.AjaxModal');
        $router = $request->getRouter();
        $settingsAction = new LinkAction(
            'settings',
            new AjaxModal(
                $router->url($request, null, null, 'manage', null, array(
                    'verb' => 'settings',
                    'plugin' => $this->getName(),
                    'category' => 'generic',
                )),
                $this->getDisplayName()
            ),
            __('manager.plugins.settings'),
            null
        );

        array_unshift($actions, $settingsAction);
        return $actions;
    }

    /**
     * @copydoc Plugin::manage()
     * Renders / saves the per-journal settings form.
     */
    public function manage($args, $request)
    {
        $verb = $request->getUserVar('verb');
        switch ($verb) {
            case 'settings':
                AppLocale::requireComponents(LOCALE_COMPONENT_APP_COMMON, LOCALE_COMPONENT_PKP_MANAGER);

                $context = $request->getContext();
                $this->import('ArticleNumberSettingsForm');
                $form = new ArticleNumberSettingsForm($this, $context ? $context->getId() : CONTEXT_ID_NONE);

                if ($request->getUserVar('save')) {
                    $form->readInputData();
                    if ($form->validate()) {
                        $form->execute();
                        import('classes.notification.NotificationManager');
                        $notificationManager = new NotificationManager();
                        $notificationManager->createTrivialNotification($request->getUser()->getId());
                        return new JSONMessage(true);
                    }
                } else {
                    $form->initData();
                }
                return new JSONMessage(true, $form->fetch($request));

            case 'migrateScan':
            case 'migrateApply':
            case 'migrateRollback':
                return $this->handleMigrationVerb($verb, $request);
        }
        return parent::manage($args, $request);
    }

    /**
     * Per-journal candidate-count cap for the in-panel Apply (overridable).
     *
     * @param int $contextId
     * @return int
     */
    public function getMigrationThreshold($contextId)
    {
        $t = (int) $this->getSetting($contextId, self::SETTING_MIGRATION_THRESHOLD);
        return $t > 0 ? $t : self::DEFAULT_MIGRATION_THRESHOLD;
    }

    /**
     * Gate every in-panel migration action: a context must exist, the user must
     * be a journal Manager (or site Admin), and the feature must be enabled for
     * this journal. Returns true to proceed, or a JSONMessage(false) to reject.
     *
     * @return true|JSONMessage
     */
    private function migrationGuard($request, $context)
    {
        if (!$context) {
            return new JSONMessage(false, __('plugins.generic.articleNumber.migrate.error.noContext'));
        }
        $user = $request->getUser();
        if (!$user) {
            return new JSONMessage(false, __('plugins.generic.articleNumber.migrate.error.unauthorized'));
        }
        $roleDao = DAORegistry::getDAO('RoleDAO');
        $isManager = $roleDao->userHasRole($context->getId(), $user->getId(), ROLE_ID_MANAGER);
        $isAdmin = $roleDao->userHasRole(CONTEXT_ID_NONE, $user->getId(), ROLE_ID_SITE_ADMIN);
        if (!$isManager && !$isAdmin) {
            return new JSONMessage(false, __('plugins.generic.articleNumber.migrate.error.unauthorized'));
        }
        if (!$this->isFeatureEnabled($context->getId())) {
            return new JSONMessage(false, __('plugins.generic.articleNumber.migrate.error.disabled'));
        }
        return true;
    }

    /**
     * In-panel migration: Scan (read-only), Apply (writes), Rollback (surgical).
     * All three delegate the actual work to ArticleNumberService::processJournal
     * (the same engine the CLI tool uses) and only ever touch THIS journal.
     *
     * Apply is doubly guarded server-side regardless of the client:
     *   - CSRF token must be valid;
     *   - the Scan signature the client echoes back must still match a fresh,
     *     live re-scan (so a save between Scan and Apply forces a re-Scan rather
     *     than applying stale results);
     *   - the live candidate count must be below the per-journal threshold.
     *
     * @return JSONMessage
     */
    private function handleMigrationVerb($verb, $request)
    {
        AppLocale::requireComponents(LOCALE_COMPONENT_APP_COMMON);
        $context = $request->getContext();
        $guard = $this->migrationGuard($request, $context);
        if ($guard !== true) {
            return $guard;
        }
        $cid = $context->getId();
        $service = $this->getService();
        $threshold = $this->getMigrationThreshold($cid);

        if ($verb === 'migrateScan') {
            $res = $service->processJournal($cid, 'dry-run');
            $candidateCount = (int) $res['counts']['derive'];
            return new JSONMessage(true, json_encode(array(
                'mode' => 'scan',
                'counts' => $res['counts'],
                'samples' => $res['samples'],
                'candidateCount' => $candidateCount,
                'threshold' => $threshold,
                'applyAllowed' => ($candidateCount > 0 && $candidateCount < $threshold),
                'signature' => $res['signature'],
                'cliCommand' => 'php plugins/generic/articleNumber/tools/migrateArticleNumbers.php apply ' . $context->getPath(),
            )));
        }

        if ($verb === 'migrateRollback') {
            if (!$request->checkCSRF()) {
                return new JSONMessage(false, __('form.csrfInvalid'));
            }
            $res = $service->processJournal($cid, 'rollback');
            return new JSONMessage(true, json_encode(array(
                'mode' => 'rollback',
                'removedCount' => (int) $res['counts']['changed'],
                'samples' => $res['samples'],
            )));
        }

        // migrateApply
        if (!$request->checkCSRF()) {
            return new JSONMessage(false, __('form.csrfInvalid'));
        }
        $clientSig = (string) $request->getUserVar('signature');
        $live = $service->processJournal($cid, 'dry-run'); // fresh, live re-scan
        $liveCount = (int) $live['counts']['derive'];

        if ($clientSig === '' || $clientSig !== $live['signature']) {
            return new JSONMessage(true, json_encode(array('mode' => 'apply', 'error' => 'stale', 'liveCount' => $liveCount)));
        }
        if ($liveCount === 0) {
            return new JSONMessage(true, json_encode(array('mode' => 'apply', 'error' => 'nothing', 'liveCount' => 0)));
        }
        if ($liveCount >= $threshold) {
            return new JSONMessage(true, json_encode(array('mode' => 'apply', 'error' => 'locked', 'liveCount' => $liveCount, 'threshold' => $threshold)));
        }

        $res = $service->processJournal($cid, 'apply');
        if (!empty($res['aborted'])) {
            return new JSONMessage(true, json_encode(array('mode' => 'apply', 'error' => 'aborted', 'abortPubId' => $res['abortPubId'])));
        }
        return new JSONMessage(true, json_encode(array(
            'mode' => 'apply',
            'appliedCount' => (int) $res['counts']['changed'],
            'duplicateCount' => (int) $res['counts']['duplicate'],
            'samples' => $res['samples'],
        )));
    }
}
