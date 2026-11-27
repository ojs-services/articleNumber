<?php
/**
 * @file ArticleNumberPlugin.php
 *
 * Article Number Plugin for OJS — main plugin class (generic plugin for
 * OJS 3.4 and 3.5).
 *
 * What it does:
 *   - Registers a runtime `workNumber` (string, nullable) property on the
 *     publication schema via the `Schema::get::publication` hook.
 *   - Per-journal opt-in: the feature is OFF by default; each journal turns it
 *     on with the `enableWorkNumber` plugin setting. When OFF (or the plugin is
 *     disabled), nothing is injected and OJS behaves exactly as before.
 *   - Hand-off guard: if the publication schema already defines the property
 *     (`workNumber`), or OJS's own `articleNumber` property, this plugin leaves
 *     its own injection out so the two never clash.
 *
 * Naming contract:
 *   - DATA is stored under the property name `workNumber` in
 *     `publication_settings` (versioned, per-publication, no extra table).
 *   - The user-visible LABEL is "Article Number" (localized).
 *   - The core `pages` field is only ever read, never written.
 */

namespace APP\plugins\generic\articleNumber;

use APP\core\Application;
use APP\facades\Repo;
use APP\notification\NotificationManager;
use APP\template\TemplateManager;
use Illuminate\Support\Facades\DB;
use PKP\components\forms\FieldHTML;
use PKP\components\forms\FieldText;
use PKP\core\JSONMessage;
use PKP\core\PKPApplication;
use PKP\facades\Locale;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\plugins\PluginRegistry;
use PKP\security\Role;
use PKP\submission\PKPSubmission;

class ArticleNumberPlugin extends GenericPlugin
{
    /** Publication property name (the stored data key). Do not change. */
    public const PROP_WORK_NUMBER = 'workNumber';

    /** Name of the article-number property in OJS releases that ship one natively. */
    public const PROP_CORE_ARTICLE_NUMBER = 'articleNumber';

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
     * Per-journal: the manager's explicit choice for the article-details block
     * ('show' | 'hide'). Unset = the manager never chose, so the default follows
     * the active theme (see themeShowsArticleNumber()). Strings, because OJS reads
     * a falsy stored value back as null — a plain 0 could not be told from "unset".
     * The legacy hide flag above is still written (and still honoured) for
     * journals that saved it before this choice existed.
     */
    public const SETTING_DETAILS_CHOICE = 'articleNumberDetailsChoice';

    /**
     * Per-journal: do NOT derive the number from Pages when an article is
     * published (1 = off, unset = on). Inverted flag for the same reason as the
     * hide flags: OJS reads a falsy stored value back as null.
     */
    public const SETTING_NO_DERIVE_ON_PUBLISH = 'noDeriveOnPublish';

    /**
     * Themes that print the Article Number themselves, so the plugin's own item
     * in the article-details block would show it twice. This is the ONE place that
     * lists them. A theme whose plugin class has a public method
     * rendersArticleNumber() answers for itself instead, yes or no (see
     * docs/THEME-INTEGRATION.md). The list is compared with the theme's directory
     * name, case-insensitively, on the active theme and its parents.
     */
    public const THEMES_SHOWING_NUMBER = array('atlas', 'nivo', 'axis', 'journalplus');

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

    /** This plugin's locale keys start with this. */
    private const LOCALE_PREFIX = 'plugins.generic.articleNumber.';

    /** Guards the once-per-request English fallback hook. */
    private static $englishFallbackRegistered = false;

    /** Guards the once-per-request hand-off log line. */
    private static $handOffLogged = false;

    /** Guards once-per-request Smarty function registration. */
    private static $smartyRegistered = false;

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
        // Before the enabled check: the plugin list shows the name either way.
        $this->_registerEnglishFallback();

        if ($this->getEnabled($mainContextId)) {
            // Inject the workNumber property into the publication schema at
            // runtime. The callback re-checks the per-journal opt-in and the
            // hand-off guard, so registering here unconditionally is safe.
            Hook::add('Schema::get::publication', array($this, 'addWorkNumberToSchema'));

            // Publishing / scheduling: when the article has no Article Number and
            // its Pages value passes the migration's "derive" rule, write it then
            // (setting on by default). Never replaces a number typed by hand.
            Hook::add('Publication::publish::before', array($this, 'deriveNumberOnPublish'));
            Hook::add('Publication::publish', array($this, 'markDerivedOnPublish'));

            // Editor UI: add the "Article Number" field to the publication
            // issue-entry form, and lock the value once an article is published.
            // Both callbacks re-check the per-journal opt-in, so registering
            // here unconditionally is safe.
            Hook::add('Form::config::before', array($this, 'addFieldToIssueEntryForm'));
            // The publish confirmation window: warn before publishing when the
            // number cannot be copied from Pages because it is already used.
            Hook::add('Form::config::before', array($this, 'addDuplicateWarningToPublishForm'));
            Hook::add('Publication::validate', array($this, 'validateWorkNumberImmutability'));
            // Uniqueness: block saving a duplicate Article Number on a not-yet-
            // published article (per the journal's scope). Same hook, separate
            // callback. Covers the normal workflow (IssueEntryForm + any service
            // edit); QuickSubmit is covered by its own validate hook below.
            Hook::add('Publication::validate', array($this, 'validateWorkNumberUniqueness'));
            Hook::add('quicksubmitform::validate', array($this, 'validateQuickSubmitUniqueness'));

            // Reader-facing: show the Article Number on the article landing
            // page (theme-independent), and send Google Scholar the article number
            // as citation_firstpage (and no citation_lastpage) instead of a bogus
            // first = last page pair, when an article number is the
            // authoritative locator. The Scholar callback runs LATE so it
            // overrides the googleScholar plugin (default priority, same hook).
            Hook::add('Templates::Article::Details', array($this, 'displayArticleNumber'));
            // Reader-facing: show the Article Number in the issue table of contents,
            // where the page number normally appears (the core template only prints
            // `pages`, which is empty for digital-first articles).
            Hook::add('Templates::Issue::Issue::Article', array($this, 'displayArticleNumberInToc'));
            Hook::add('ArticleHandler::view', array($this, 'setScholarFirstPage'), Hook::SEQUENCE_LATE);
            // Expose a Smarty {article_number} helper so theme developers can
            // place the value wherever they want (e.g. in place of the page
            // range, in the theme's native location). See docs/THEME-INTEGRATION.md.
            Hook::add('TemplateManager::display', array($this, 'registerSmartyHelpers'));

            // QuickSubmit (the separate PKP "QuickSubmit" plugin) support.
            // QuickSubmit is a classic PKP Form, so we attach to its base-class
            // hooks instead of Form::config::before. Its templates offer no
            // metadata hook, so the field is added to the page through the
            // `additionalContributorsFields` template variable that its form
            // template prints. Nothing is forked; only workNumber is written.
            Hook::add('quicksubmitform::display', array($this, 'displayQuickSubmitWorkNumber'));
            Hook::add('quicksubmitform::readuservars', array($this, 'readQuickSubmitWorkNumber'));
            Hook::add('quicksubmitform::execute', array($this, 'saveQuickSubmitWorkNumber'));

            // Crossref export. The Crossref article filter fires
            // `articlecrossrefxmlfilter::execute` with the assembled DOMDocument
            // by reference. We attach to that output, add
            // <publisher_item><item_number> and drop <pages> for articles that
            // have a workNumber. The crossref plugin is never forked or modified.
            Hook::add('articlecrossrefxmlfilter::execute', array($this, 'injectCrossrefArticleNumber'));

            // JATS. The JATS generation hook (OAIMetadataFormat_JATS::findJats)
            // hands us the generated DOMDocument; running LATE (after the
            // jatsTemplate plugin builds it) we replace <fpage>/<lpage> with
            // <elocation-id> when a workNumber is set (JATS4R: the two must
            // never coexist). Nothing happens when no JATS plugin is installed.
            Hook::add('OAIMetadataFormat_JATS::findJats', array($this, 'injectJatsElocationId'), Hook::SEQUENCE_LATE);

            // Citations. Map the workNumber onto the CSL data before citeproc
            // renders it (the only available hook is pre-render).
            Hook::add('CitationStyleLanguage::citation', array($this, 'mapCitationWorkNumber'));
            // IEEE's bundled CSL renders the article number in the page slot
            // ("p. <n>"); IEEE convention is "Art. no. <n>". There is no
            // post-render hook, so we refine the primary on-page citation here,
            // after the citationStyleLanguage plugin has assigned it (LATE).
            Hook::add('ArticleHandler::view', array($this, 'refinePrimaryIeeeCitation'), Hook::SEQUENCE_LATE);
        }

        return $success;
    }

    /**
     * English fallback for this plugin's own texts: OJS 3.4/3.5 print ##key## for
     * a key that has no translation. The hook runs only for such keys, and only
     * keys with this plugin's prefix are answered, so a language's own file always
     * wins and nobody else's texts are touched.
     * (Locale::registerLoader() is not usable: it crashes every page in OJS core.)
     */
    private function _registerEnglishFallback()
    {
        if (self::$englishFallbackRegistered) {
            return;
        }
        self::$englishFallbackRegistered = true;
        Hook::add('Locale::translate', function ($hookName, $args) {
            $value = &$args[0];
            $key = (string) $args[1];
            $locale = (string) $args[4];
            if ($locale === 'en' || strpos($key, self::LOCALE_PREFIX) !== 0) {
                return Hook::CONTINUE;
            }
            $english = $args[3] === null
                ? Locale::get($key, (array) $args[2], 'en')
                : Locale::choice($key, (int) $args[3], (array) $args[2], 'en');
            if (strpos($english, '##') === 0) {
                return Hook::CONTINUE; // not in English either
            }
            $value = $english;
            return Hook::ABORT;
        });
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

        // Hand-off guard: the property is already defined by someone else
        // (a third party under our name, or OJS itself under its own
        // `articleNumber` name) → do not add ours. Log once per request.
        if (isset($schema->properties->{self::PROP_WORK_NUMBER})
            || isset($schema->properties->{self::PROP_CORE_ARTICLE_NUMBER})) {
            if (!self::$handOffLogged) {
                error_log('[articleNumber] The publication schema already defines an article-number property; '
                    . 'skipping plugin schema injection.');
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
     * The context id OJS uses for "the whole site" (0 in OJS 3.4, null in 3.5).
     * Picked by capability so one code base serves both.
     *
     * @return int|null
     */
    private function _siteContextId()
    {
        return defined(PKPApplication::class . '::SITE_CONTEXT_ID')
            ? PKPApplication::SITE_CONTEXT_ID
            : PKPApplication::CONTEXT_ID_NONE;
    }

    /**
     * Resolve the current journal context, if any.
     * @return \PKP\context\Context|null
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
     * @param string $hookName
     * @param array  $args [0]=>FormComponent The form being configured
     * @return bool false
     */
    public function addFieldToIssueEntryForm($hookName, $args)
    {
        $form = is_array($args) ? ($args[0] ?? null) : $args;
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
            $publication = Repo::publication()->get((int) $m[1]);
            if ($publication) {
                $value = $publication->getData(self::PROP_WORK_NUMBER);
            }
        }

        $description = __('plugins.generic.articleNumber.field.description');

        // Optional generator. When enabled, and only for an
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
        $isPublished = $publication && (int) $publication->getData('status') === PKPSubmission::STATUS_PUBLISHED;
        if (($value === null || $value === '')
                && $publication
                && !$isPublished
                && $this->getSetting($contextId, self::SETTING_GENERATOR)
                && $publication->getData('issueId')) {
            $template = (string) ($this->getSetting($contextId, self::SETTING_TEMPLATE) ?: self::DEFAULT_TEMPLATE);
            $next = $this->_nextNumberForIssue((int) $publication->getData('issueId'), $template);
            $suggestion = $this->_freeSuggestion($contextId, $publication, $template, $next);
            if ($suggestion !== null) {
                $description .= '<br><em>' . __('plugins.generic.articleNumber.generator.suggested', array('number' => htmlspecialchars($suggestion))) . '</em>';
            }
        }

        // Predictive collision notice in the help text (the hard block happens on
        // save via Publication::validate). Uses the journal's uniqueness scope.
        if ($value !== null && $value !== '' && $publication) {
            $scope = $this->getUniquenessScope($contextId);
            if ($this->isWorkNumberTaken($contextId, (string) $value, (int) $publication->getData('submissionId'), $scope, $publication->getData('issueId'))) {
                $description .= '<br><strong>' . $this->_uniquenessErrorMessage($contextId) . '</strong>';
            }
        } elseif ($publication) {
            $dup = $this->_publishDuplicate($contextId, $publication);
            if ($dup) {
                // A published article is locked, so the way to add a number differs.
                $key = ((int) $publication->getData('status') === PKPSubmission::STATUS_PUBLISHED)
                    ? 'plugins.generic.articleNumber.duplicate.fieldSkipped'
                    : 'plugins.generic.articleNumber.duplicate.fieldPending';
                $description .= '<br><strong>' . __($key, array(
                    'value' => htmlspecialchars($dup['value']),
                    'otherId' => $dup['otherId'],
                )) . '</strong>';
            }
        }

        $field = new FieldText(self::PROP_WORK_NUMBER, [
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
     * Hook callback for `Form::config::before`, publish confirmation window: when
     * the Article Number is empty and the Pages value that would be copied into it
     * is already used by another article, say so before the editor publishes.
     * Publishing itself is not blocked.
     *
     * @return bool false
     */
    public function addDuplicateWarningToPublishForm($hookName, $args)
    {
        $form = is_array($args) ? ($args[0] ?? null) : $args;
        if (!is_object($form) || empty($form->id) || $form->id !== 'publish') {
            return false;
        }
        if (empty($form->publication) || !empty($form->errors)) {
            return false; // requirements not met: nothing will be published yet
        }
        $context = $this->_resolveContext();
        if (!$context) {
            return false;
        }
        $dup = $this->_publishDuplicate($context->getId(), $form->publication);
        if (!$dup) {
            return false;
        }
        $form->addField(new FieldHTML('articleNumberDuplicate', [
            'description' => '<p><strong>' . __('plugins.generic.articleNumber.duplicate.publishForm', array(
                'value' => htmlspecialchars($dup['value']),
                'otherId' => $dup['otherId'],
            )) . '</strong></p>',
            'groupId' => 'default',
        ]));
        return false;
    }

    /**
     * The clash that keeps (or kept) this publication from getting its number at
     * publication: its Article Number is empty, its Pages value looks like an
     * article number, and another article already uses that value (within the
     * journal's uniqueness scope). Null when nothing is in the way.
     *
     * @param int    $contextId
     * @param object $publication
     * @return array|null {value:string, otherId:int}
     */
    private function _publishDuplicate($contextId, $publication)
    {
        if (!$this->isFeatureEnabled($contextId) || $this->getSetting($contextId, self::SETTING_NO_DERIVE_ON_PUBLISH)) {
            return null;
        }
        if (trim((string) $publication->getData(self::PROP_WORK_NUMBER)) !== '') {
            return null;
        }
        $service = $this->getService();
        $derived = $service->deriveOnPublish(
            $contextId,
            (int) $publication->getData('submissionId'),
            $publication->getData('pages'),
            $publication->getData('issueId')
        );
        if (!$derived || !$derived['duplicateOf']) {
            return null;
        }
        return array('value' => $derived['value'], 'otherId' => $service->submissionIdOf($derived['duplicateOf']));
    }

    /**
     * Warn the person who is publishing that no number was written. OJS shows it
     * as a notice on the next screen that loads notifications; a scheduled task
     * has no user, and the Scan lists the article either way.
     *
     * @param int    $submissionId
     * @param string $value
     * @param int    $otherId
     */
    private function _notifyDuplicate($submissionId, $value, $otherId)
    {
        try {
            $request = Application::get()->getRequest();
            $user = $request ? $request->getUser() : null;
            if (!$user) {
                return;
            }
            $notificationManager = new NotificationManager();
            $notificationManager->createTrivialNotification($user->getId(), \APP\notification\Notification::NOTIFICATION_TYPE_WARNING, array(
                'contents' => __('plugins.generic.articleNumber.duplicate.notice', array(
                    'submissionId' => (int) $submissionId,
                    'value' => htmlspecialchars($value),
                    'otherId' => (int) $otherId,
                )),
            ));
        } catch (\Throwable $e) {
            // A notice must never stop a publication.
            error_log('articleNumber: could not create the duplicate notice: ' . $e->getMessage());
        }
    }

    /**
     * The first number from $next on that no other article uses, so the generator
     * never suggests a value that saving would reject. Null when none is found.
     *
     * @return string|null
     */
    private function _freeSuggestion($contextId, $publication, $template, $next)
    {
        $scope = $this->getUniquenessScope($contextId);
        $suggestion = $this->_formatTemplate($template, $next);
        for ($tries = 0; $tries < 5000; $tries++) {
            if (!$this->isWorkNumberTaken($contextId, $suggestion, (int) $publication->getData('submissionId'), $scope, $publication->getData('issueId'))) {
                return $suggestion;
            }
            $following = $this->_formatTemplate($template, ++$next);
            if ($following === $suggestion) {
                break; // a template without a counter cannot offer another value
            }
            $suggestion = $following;
        }
        return null;
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
        $max = 0;
        $values = DB::table('publication_settings')
            ->where('setting_name', self::PROP_WORK_NUMBER)
            ->whereIn('publication_id', array_map('intval', $pubIds))
            ->pluck('setting_value');
        foreach ($values as $value) {
            $n = $this->_extractTrailingInt($value);
            if ($n !== null && $n > $max) {
                $max = $n;
            }
        }
        return $max + 1;
    }

    /**
     * All publication ids assigned to a given issue (issueId is a publication
     * setting in OJS 3.4, a column in OJS 3.5).
     *
     * @param int $issueId
     * @return int[]
     */
    private function _issuePublicationIds($issueId)
    {
        return $this->getService()->getIssuePublicationIds($issueId);
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

    /** @var array publication id => number derived at publication, awaiting the saved-check */
    private $pendingDerived = array();

    /**
     * Hook callback for `Publication::publish` (after the save): mark the number as
     * derived by this plugin (so Undo can remove it) only if it was really saved.
     *
     * @param string $hookName
     * @param array  $args [0]=>&$newPublication
     * @return bool false
     */
    public function markDerivedOnPublish($hookName, $args)
    {
        $pub = $args[0];
        $id = (int) $pub->getId();
        if (isset($this->pendingDerived[$id])) {
            if ((string) $pub->getData(self::PROP_WORK_NUMBER) === (string) $this->pendingDerived[$id]) {
                $this->getService()->markDerived($id);
            }
            unset($this->pendingDerived[$id]);
        }
        return false;
    }

    /**
     * Hook callback for `Publication::publish::before`: derive the Article Number
     * from the Pages value at publication (or scheduling) time. Does nothing when
     * the feature or this option is off, when a number is already set, or when the
     * Pages value is a real page range or an unclear value.
     *
     * @param string $hookName
     * @param array  $args [0]=>&$newPublication, [1]=>$publication
     * @return bool false
     */
    public function deriveNumberOnPublish($hookName, $args)
    {
        $newPublication = $args[0];
        $submission = Repo::submission()->get($newPublication->getData('submissionId'));
        if (!$submission) {
            return false;
        }
        $contextId = (int) $submission->getData('contextId');
        if (!$this->isFeatureEnabled($contextId) || $this->getSetting($contextId, self::SETTING_NO_DERIVE_ON_PUBLISH)) {
            return false;
        }
        $existing = $newPublication->getData(self::PROP_WORK_NUMBER);
        if ($existing !== null && trim((string) $existing) !== '') {
            return false; // never overwrite a number that is already there
        }
        $service = $this->getService();
        $derived = $service->deriveOnPublish($contextId, (int) $submission->getId(), $newPublication->getData('pages'), $newPublication->getData('issueId'));
        if ($derived === null) {
            return false;
        }
        if ($derived['duplicateOf']) {
            // Another article has this number: leave the field empty and say so.
            $otherId = $service->submissionIdOf($derived['duplicateOf']);
            error_log('articleNumber: no number written at publication for submission #' . $submission->getId() . ': "' . $derived['value'] . '" is already used by submission #' . $otherId);
            $this->_notifyDuplicate((int) $submission->getId(), $derived['value'], $otherId);
            return false;
        }
        $newPublication->setData(self::PROP_WORK_NUMBER, $derived['value']);
        $this->pendingDerived[(int) $newPublication->getId()] = $derived['value'];
        return false;
    }

    /**
     * Is the plugin's own item in the article-details block hidden for this
     * journal? The manager's saved choice wins; with no choice, the legacy hide
     * flag wins; with neither, the block is hidden only when the active theme
     * prints the Article Number itself.
     *
     * @param int $contextId
     * @return bool
     */
    public function isDetailsBlockHidden($contextId)
    {
        $choice = $this->getSetting($contextId, self::SETTING_DETAILS_CHOICE);
        if ($choice === 'hide') {
            return true;
        }
        if ($choice === 'show') {
            return false;
        }
        if ($this->getSetting($contextId, self::SETTING_HIDE_DETAILS_BLOCK)) {
            return true;
        }
        return $this->themeShowsArticleNumber($contextId);
    }

    /**
     * Does the journal's active theme print the Article Number itself?
     *
     * 1. A theme (or a parent theme) with rendersArticleNumber() is believed,
     *    whether it answers yes or no.
     * 2. Otherwise the theme counts when it is named in THEMES_SHOWING_NUMBER.
     *
     * @param int $contextId
     * @return bool
     */
    public function themeShowsArticleNumber($contextId)
    {
        static $cache = array();
        $contextId = (int) $contextId;
        if (isset($cache[$contextId])) {
            return $cache[$contextId];
        }
        $path = null;
        $chain = array();
        try {
            $request = Application::get()->getRequest();
            $context = ($request->getContext() && (int) $request->getContext()->getId() === $contextId)
                ? $request->getContext()
                : Application::getContextDAO()->getById($contextId);
            $path = $context ? $context->getData('themePluginPath') : null;
            if (!$path && $request->getSite()) {
                $path = $request->getSite()->getData('themePluginPath');
            }
            if ($path) {
                $theme = $this->_themeInDirectory($path, $contextId);
                for ($guard = 0; $theme && $guard < 10; $guard++) {
                    $chain[] = $theme;
                    $theme = $theme->parent ?? null;
                }
            }
            foreach ($chain as $theme) {
                if (method_exists($theme, 'rendersArticleNumber')) {
                    return $cache[$contextId] = (bool) $theme->rendersArticleNumber();
                }
            }
        } catch (\Throwable $e) {
            // Detection must never break a page; the list below still applies.
            error_log('articleNumber: theme detection failed: ' . $e->getMessage());
        }
        $names = $path ? array((string) $path) : array();
        foreach ($chain as $theme) {
            $names[] = (string) $theme->getDirName();
        }
        foreach ($names as $name) {
            if (in_array(strtolower($name), self::THEMES_SHOWING_NUMBER, true)) {
                return $cache[$contextId] = true;
            }
        }
        return $cache[$contextId] = false;
    }

    /**
     * The registered theme plugin object living in a theme directory, loading it
     * first when this request has not loaded it (theme objects are not loaded on
     * every page, e.g. in the back end). Themes are registered under their class
     * name, not their directory, and loadPlugin() hands back a never-registered
     * copy of an already loaded theme, so the registry is searched by directory.
     *
     * @return object|null
     */
    private function _themeInDirectory($directory, $contextId)
    {
        $find = function () use ($directory) {
            foreach ((array) PluginRegistry::getPlugins('themes') as $theme) {
                if ($theme->getDirName() === $directory) {
                    return $theme;
                }
            }
            return null;
        };
        $theme = $find();
        if (!$theme) {
            PluginRegistry::loadPlugin('themes', $directory, $contextId);
            $theme = $find();
        }
        return $theme;
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
        if ($this->isDetailsBlockHidden($context->getId())) {
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
     * Hook callback for `quicksubmitform::display`.
     *
     * Adds the "Article Number" field to the QuickSubmit form when the feature is
     * enabled for the journal. The QuickSubmit template prints the
     * `additionalContributorsFields` variable below its contributors grid, so the
     * rendered field is appended to that variable (anything another plugin put
     * there is kept). The value comes from the submitted form data (so it
     * survives a validation re-display) or, failing that, from the stored value.
     *
     * @param string $hookName
     * @param array  $args [0]=>$form, [1]=>&$returner
     * @return bool false
     */
    public function displayQuickSubmitWorkNumber($hookName, $args)
    {
        $form = $args[0] ?? null;
        if (!is_object($form) || !method_exists($form, 'getData')) {
            return false;
        }
        $request = Application::get()->getRequest();
        $context = $request ? $request->getContext() : null;
        if (!$context || !$this->isFeatureEnabled($context->getId())) {
            return false;
        }

        $value = $form->getData(self::PROP_WORK_NUMBER);
        if ($value === null || $value === '') {
            $submissionId = $form->getData('submissionId');
            $submission = $submissionId ? Repo::submission()->get((int) $submissionId) : null;
            $publication = $submission ? $submission->getCurrentPublication() : null;
            $value = $publication ? $publication->getData(self::PROP_WORK_NUMBER) : null;
        }

        $templateMgr = TemplateManager::getManager($request);
        // The Form Builder helpers need to know the form before it is displayed.
        $templateMgr->getFBV()->setForm($form);
        $templateMgr->assign('articleNumberQsValue', ($value === null) ? '' : (string) $value);
        $html = $templateMgr->fetch($this->getTemplateResource('quickSubmitField.tpl'));

        $existing = $templateMgr->getTemplateVars('additionalContributorsFields');
        $templateMgr->assign('additionalContributorsFields', (is_string($existing) ? $existing : '') . $html);

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
        $submission = Repo::submission()->get((int) $submissionId);
        if (!$submission) {
            return false;
        }
        $publication = $submission->getCurrentPublication();
        if (!$publication) {
            return false;
        }

        // Write ONLY our workNumber setting directly (never touch pages or other
        // core fields). Mirrors the migration tool's direct-write approach.
        $pubId = (int) $publication->getId();
        $raw = $form->getData(self::PROP_WORK_NUMBER);
        $workNumber = ($raw === null) ? '' : trim((string) $raw);
        $key = ['publication_id' => $pubId, 'locale' => '', 'setting_name' => self::PROP_WORK_NUMBER];

        if ($workNumber === '') {
            DB::table('publication_settings')->where($key)->delete();
        } else {
            DB::table('publication_settings')->updateOrInsert($key, ['setting_value' => $workNumber]);
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
        // Registering the same Smarty function twice throws, so skip it when this
        // template manager already has it (e.g. a second plugin instance).
        if (!isset($templateMgr->registered_plugins['function']['article_number'])) {
            try {
                $templateMgr->registerPlugin('function', 'article_number', array($this, 'smartyArticleNumber'));
            } catch (\Exception $e) {
                // Already registered elsewhere; nothing to do.
            }
        }
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
     * The publication the reader is looking at on an `ArticleHandler::view` hook:
     * the 4th hook argument (it can be an older version), else the current one.
     *
     * @param array $args Hook arguments
     * @param \APP\submission\Submission $submission
     * @return \APP\publication\Publication|null
     */
    private function _viewedPublication($args, $submission)
    {
        if (isset($args[3]) && is_object($args[3]) && method_exists($args[3], 'getData')) {
            return $args[3];
        }
        return $submission->getCurrentPublication();
    }

    /**
     * Hook callback for `ArticleHandler::view` (registered at Hook::SEQUENCE_LATE).
     *
     * Google Scholar matches a citation to a paper by journal, volume/issue and
     * the number of the first page. Scholar has no separate tag for an article
     * number, and publishers of continuously published journals put the article
     * number in `citation_firstpage` and send no `citation_lastpage`.
     * When a workNumber is set, we therefore replace the two page-meta header
     * slots the googleScholar plugin fills (it runs at the default priority on
     * this same hook; we run LATE): `citation_firstpage` carries the article
     * number and `citation_lastpage` is not rendered, so the fake
     * firstpage == lastpage pair made from `pages` never goes out.
     * Articles without a workNumber are not touched.
     * Core is never modified and the googleScholar plugin is never forked.
     *
     * @param string $hookName
     * @param array  $args [0]=>$request, [1]=>$issue, [2]=>$submission, [3]=>$publication
     * @return bool false
     */
    public function setScholarFirstPage($hookName, $args)
    {
        $request = $args[0];
        $submission = isset($args[2]) ? $args[2] : null;
        if (!$request || !is_object($submission)) {
            return false;
        }

        // Like the core googleScholar plugin, add nothing on a version URL
        // (article/view/ID/version/PUBID): it only tags the canonical page, so a
        // lone citation_firstpage there would be an orphan tag.
        $requestArgs = $request->getRequestedArgs();
        if (count($requestArgs) > 1 && $requestArgs[1] === 'version') {
            return false;
        }

        $context = $request->getContext();
        if (!$context || !$this->isFeatureEnabled($context->getId())) {
            return false;
        }

        $publication = $this->_viewedPublication($args, $submission);
        if (!$publication) {
            return false;
        }
        $workNumber = $publication->getData(self::PROP_WORK_NUMBER);
        if ($workNumber === null || $workNumber === '') {
            return false;
        }

        // Use the same header slots as the googleScholar plugin. addHeader() keys
        // by name (default priority/context), so this replaces its entries: the
        // first-page slot gets the article number, the last-page slot is emptied
        // (an empty string renders nothing).
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->addHeader(
            'googleScholarStartPage',
            // This replaces the Google Scholar plugin's own header slot (no second tag). tara-yoksay: S02
            '<meta name="citation_firstpage" content="' . htmlspecialchars((string) $workNumber, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"/>'
        );
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
        if (!($doc instanceof \DOMDocument)) {
            return false;
        }

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
            $submission = $this->_resolveSubmissionForArticleNode($articleNode);
            if (!$submission || !$this->isFeatureEnabled((int) $submission->getData('contextId'))) {
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
     * @param \DOMElement $articleNode
     * @return \APP\submission\Submission|null
     */
    private function _resolveSubmissionForArticleNode($articleNode)
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
            return Repo::submission()->get((int) $bestId);
        }
        // Non-numeric best id is a urlPath, which requires the journal id.
        $context = Application::getContextDAO()->getByPath($contextPath);
        if (!$context) {
            return null;
        }
        return Repo::submission()->getByUrlPath($bestId, (int) $context->getId());
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
        if (!($doc instanceof \DOMDocument) || !is_object($record)) {
            return false;
        }

        $article = $record->getData('article');
        if (!is_object($article)) {
            return false;
        }
        // Gate on the article's own journal (request-context independent).
        $contextId = (int) $article->getData('contextId');
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
        $publication = $this->_viewedPublication($args, $submission);
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

        // The citation carries the number HTML-escaped (see mapCitationWorkNumber),
        // so look for, and write back, the escaped form.
        $safe = htmlspecialchars((string) $workNumber, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $fixed = preg_replace_callback(
            '/\bpp?\.\s*' . preg_quote($safe, '/') . '/u',
            function () use ($safe) {
                return 'Art. no. ' . $safe;
            },
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

        // The CSL renderer HTML-escapes the `number` variable but passes `page`
        // through as it is. The citation is shown as HTML, so the value that goes
        // into `page` is escaped here (plain-text downloads keep it as typed).
        $isPlainText = in_array($styleId, array('ris', 'bibtex'), true);
        $pageValue = $isPlainText
            ? (string) $workNumber
            : htmlspecialchars((string) $workNumber, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        if (stripos($styleId, 'apa') !== false) {
            // APA (Group 2, special): inject the labeled locator into `page`.
            $citationData->page = __('plugins.generic.articleNumber.citation.articleLabel') . ' ' . $pageValue;
        } elseif (in_array($styleId, $nativeNumberStyles, true) || stripos($styleId, 'modern-language') !== false) {
            // Group 1 (native number): single, unlabeled locator; page stays empty.
            $citationData->number = (string) $workNumber;
        } else {
            // Group 2 (no native number): the number must ride in `page` or the
            // locator is lost. (ABNT/Harvard/IEEE add an unavoidable "p." label.)
            $citationData->page = $pageValue;
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
     * back-filling published articles is the migration tool, which
     * writes directly rather than through this validated edit flow.
     *
     * @param string $hookName
     * @param array  $args [0]=>&$errors, [1]=>?Publication (null when adding), [2]=>$props, ...
     * @return bool false
     */
    public function validateWorkNumberImmutability($hookName, $args)
    {
        $errors =& $args[0];
        $publication = $args[1];
        $props = $args[2];

        // Only act when the workNumber is part of this edit.
        if (!is_array($props) || !array_key_exists(self::PROP_WORK_NUMBER, $props)) {
            return false;
        }
        // Immutability only applies to edits of an existing publication
        // (OJS passes null as the publication when a new one is being added).
        if (!is_object($publication)) {
            return false;
        }

        // Gate on the per-journal opt-in for this publication's journal.
        $submission = Repo::submission()->get((int) $publication->getData('submissionId'));
        $contextId = $submission ? $submission->getData('contextId') : null;
        if (!$contextId || !$this->isFeatureEnabled($contextId)) {
            return false;
        }

        // Block any change to the Article Number on an already-published article.
        if ((int) $publication->getData('status') === PKPSubmission::STATUS_PUBLISHED) {
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
     * Duplicate-number message that matches the journal's uniqueness scope
     * ("in this journal" or, when uniqueness is per issue, "in this issue").
     *
     * @param int $contextId
     * @return string
     */
    private function _uniquenessErrorMessage($contextId)
    {
        return ($this->getUniquenessScope($contextId) === 'issue')
            ? __('plugins.generic.articleNumber.uniqueness.error.issue')
            : __('plugins.generic.articleNumber.uniqueness.error');
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
     * @param array  $args [0]=>&$errors, [1]=>?Publication (null when adding), [2]=>$props, ...
     * @return bool false
     */
    public function validateWorkNumberUniqueness($hookName, $args)
    {
        $errors =& $args[0];
        $publication = $args[1];
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
        if (is_object($publication)) {
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

        $submission = Repo::submission()->get((int) $submissionId);
        $contextId = $submission ? $submission->getData('contextId') : null;
        if (!$contextId || !$this->isFeatureEnabled($contextId)) {
            return false;
        }
        // Published is the immutability validator's job; uniqueness guards the
        // not-yet-published assignment.
        if ($status !== null && (int) $status === PKPSubmission::STATUS_PUBLISHED) {
            return false;
        }

        if ($this->isWorkNumberTaken($contextId, $value, (int) $submissionId, $this->getUniquenessScope($contextId), $issueId)) {
            $errors[self::PROP_WORK_NUMBER] = array($this->_uniquenessErrorMessage($contextId));
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
                $form->addError(self::PROP_WORK_NUMBER, $this->_uniquenessErrorMessage($context->getId()));
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
                $context = $request->getContext();
                $form = new ArticleNumberSettingsForm($this, $context ? $context->getId() : $this->_siteContextId());

                if ($request->getUserVar('save')) {
                    $form->readInputData();
                    if ($form->validate()) {
                        $form->execute();
                        $notificationManager = new NotificationManager();
                        $notificationManager->createTrivialNotification($request->getUser()->getId());
                        return new JSONMessage(true);
                    }
                } else {
                    $form->initData();
                }
                return new JSONMessage(true, $form->fetch($request));

            case 'migrateCsv':
            case 'migrateScan':
            case 'migrateApply':
            case 'migrateRollback':
                return $this->handleMigrationVerb($verb, $request);
        }
        return parent::manage($args, $request);
    }

    /**
     * The settings window shows at most ArticleNumberService::SAMPLE_LIMIT example
     * lines per list; the command line prints every line.
     *
     * @param array $samples
     * @return array
     */
    private function _panelSamples($samples)
    {
        foreach ($samples as $key => $lines) {
            $samples[$key] = array_slice($lines, 0, ArticleNumberService::SAMPLE_LIMIT);
        }
        return $samples;
    }

    /**
     * Send the full scan result as a CSV download (one row per article: to convert,
     * to check by hand, skipped, already numbered, no pages) and end the request.
     * UTF-8 with a BOM so spreadsheets open accented text correctly; cells that
     * could be read as a formula are neutralised.
     *
     * @param string $journalPath
     * @param array  $records
     */
    private function _sendMigrationCsv($journalPath, $records)
    {
        $statusNames = array(1 => 'in progress', 3 => 'published', 4 => 'declined', 5 => 'scheduled');
        $resultNames = array(
            'convert' => 'to convert',
            'check' => 'manual check',
            'skip' => 'skipped: real page range',
            'numbered' => 'skipped: already has an Article Number',
            'nopages' => 'skipped: no pages value',
            'unpublished' => 'skipped: not published (in progress or declined)',
        );
        $reasonNames = array(
            'number' => 'bare number: could be a real single page',
            'roman' => 'roman numeral or letters only',
            'text' => 'text, not a number',
            'mixed' => 'letters, digits and punctuation: not a recognised pattern',
            'other' => 'not recognised',
            'duplicate' => 'same number as another article: not converted',
        );
        $safe = function ($v) {
            $v = (string) $v;
            return ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) ? "'" . $v : $v;
        };

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="article-number-scan-' . preg_replace('/[^A-Za-z0-9_-]/', '', $journalPath) . '-' . date('Ymd') . '.csv"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array('result', 'publication_id', 'submission_id', 'submission_status', 'issue', 'title', 'pages', 'article_number', 'duplicate', 'note'));
        foreach ($records as $r) {
            fputcsv($out, array(
                $resultNames[$r['result']] ?? $r['result'],
                $r['publicationId'],
                $r['submissionId'],
                $statusNames[$r['status']] ?? ('status ' . $r['status']),
                $safe($r['issue']),
                $safe($r['title']),
                $safe($r['pages']),
                $safe($r['articleNumber']),
                $r['duplicate'] ? 'yes' : '',
                ($r['reason'] !== '' ? ($reasonNames[$r['reason']] ?? $r['reason']) : '')
                    . ($r['duplicateOf'] ? ' (submission #' . $r['duplicateOf'] . ')' : ''),
            ));
        }
        fclose($out);
        exit;
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
        $isManager = $user->hasRole([Role::ROLE_ID_MANAGER], $context->getId());
        $isAdmin = $user->hasRole([Role::ROLE_ID_SITE_ADMIN], $this->_siteContextId());
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
        $context = $request->getContext();
        $guard = $this->migrationGuard($request, $context);
        if ($guard !== true) {
            return $guard;
        }
        $cid = $context->getId();
        $service = $this->getService();
        $threshold = $this->getMigrationThreshold($cid);

        if ($verb === 'migrateCsv') {
            $res = $service->processJournal($cid, 'dry-run');
            $this->_sendMigrationCsv($context->getPath(), $res['records']);
            return new JSONMessage(false); // not reached: _sendMigrationCsv() ends the request
        }

        if ($verb === 'migrateScan') {
            $res = $service->processJournal($cid, 'dry-run');
            $candidateCount = (int) $res['counts']['derive'];
            // Every value that needs a manual check is listed in full (never capped).
            $manualRows = array();
            foreach ($res['records'] as $rec) {
                if ($rec['result'] === 'check') {
                    $manualRows[] = array(
                        'id' => $rec['publicationId'],
                        'issue' => $rec['issue'],
                        'title' => $rec['title'],
                        'pages' => $rec['pages'],
                        'reason' => $rec['reason'],
                        'other' => $rec['duplicateOf'],
                    );
                }
            }
            return new JSONMessage(true, json_encode(array(
                'mode' => 'scan',
                'counts' => $res['counts'],
                'stats' => $res['stats'],
                'manual' => $manualRows,
                'csvUrl' => $request->getRouter()->url($request, null, null, 'manage', null, array(
                    'verb' => 'migrateCsv', 'plugin' => $this->getName(), 'category' => 'generic',
                )),
                'samples' => $this->_panelSamples($res['samples']),
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
                'samples' => $this->_panelSamples($res['samples']),
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
            'samples' => $this->_panelSamples($res['samples']),
        )));
    }
}
