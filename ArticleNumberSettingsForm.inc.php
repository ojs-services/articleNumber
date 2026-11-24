<?php
/**
 * @file ArticleNumberSettingsForm.inc.php
 *
 * Article Number Plugin for OJS — per-journal settings form.
 *
 * Phase 1 persists a single per-journal setting:
 *   - enableWorkNumber (bool) — master opt-in for the Article Number feature.
 *     Default OFF. When OFF, the plugin injects nothing and OJS is unchanged.
 */

import('lib.pkp.classes.form.Form');
import('lib.pkp.classes.form.validation.FormValidatorPost');
import('lib.pkp.classes.form.validation.FormValidatorCSRF');

class ArticleNumberSettingsForm extends Form
{
    /** @var ArticleNumberPlugin */
    private $plugin;

    /** @var int */
    private $contextId;

    public function __construct($plugin, $contextId)
    {
        parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));
        $this->plugin = $plugin;
        $this->contextId = (int) $contextId;

        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
    }

    public function initData()
    {
        $this->setData('enableWorkNumber', (bool) $this->plugin->getSetting(
            $this->contextId, ArticleNumberPlugin::SETTING_ENABLE
        ));
        // The form shows a "Show …" checkbox. What it starts as: the manager's
        // saved choice if there is one, otherwise off when the active theme prints
        // the Article Number itself, otherwise on.
        $this->setData('showArticleNumberInDetails', !$this->plugin->isDetailsBlockHidden($this->contextId));
        $this->setData('themeShowsArticleNumber', $this->plugin->themeShowsArticleNumber($this->contextId));
        // Same inverted "hide" flag for the issue table-of-contents display.
        $hideToc = $this->plugin->getSetting($this->contextId, ArticleNumberPlugin::SETTING_HIDE_IN_TOC);
        $this->setData('showArticleNumberInToc', !$hideToc);
        // Derive from Pages at publication: on unless the inverted flag is set.
        $this->setData('deriveNumberOnPublish', !$this->plugin->getSetting($this->contextId, ArticleNumberPlugin::SETTING_NO_DERIVE_ON_PUBLISH));
        $this->setData('workNumberGeneratorEnabled', (bool) $this->plugin->getSetting(
            $this->contextId, ArticleNumberPlugin::SETTING_GENERATOR
        ));
        $template = $this->plugin->getSetting($this->contextId, ArticleNumberPlugin::SETTING_TEMPLATE);
        $this->setData('workNumberTemplate', ($template === null || $template === '')
            ? ArticleNumberPlugin::DEFAULT_TEMPLATE : $template);
        // Uniqueness scope defaults to whole-journal.
        $this->setData('workNumberUniquenessScope', $this->plugin->getUniquenessScope($this->contextId));
        // In-panel migration candidate-count cap (overridable; default 2000).
        $this->setData('workNumberMigrationThreshold', $this->plugin->getMigrationThreshold($this->contextId));
        parent::initData();
    }

    public function readInputData()
    {
        $this->readUserVars(array('enableWorkNumber', 'showArticleNumberInDetails', 'showArticleNumberInToc', 'deriveNumberOnPublish', 'workNumberUniquenessScope', 'workNumberGeneratorEnabled', 'workNumberTemplate', 'workNumberMigrationThreshold'));

        // Constrain scope to the allowed set; default to journal-wide.
        $scope = $this->getData('workNumberUniquenessScope');
        $this->setData('workNumberUniquenessScope', ($scope === 'issue') ? 'issue' : 'journal');

        // Keep the migration threshold a sane positive integer; default if blank/invalid.
        $threshold = (int) $this->getData('workNumberMigrationThreshold');
        $this->setData('workNumberMigrationThreshold', $threshold > 0 ? $threshold : ArticleNumberPlugin::DEFAULT_MIGRATION_THRESHOLD);

        // Keep the template a sane, non-empty free-string pattern.
        $template = trim((string) $this->getData('workNumberTemplate'));
        if ($template === '') {
            $template = ArticleNumberPlugin::DEFAULT_TEMPLATE;
        }
        $this->setData('workNumberTemplate', $template);
        parent::readInputData();
    }

    public function fetch($request, $template = null, $display = false)
    {
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign('pluginName', $this->plugin->getName());
        return parent::fetch($request, $template, $display);
    }

    public function execute(...$functionArgs)
    {
        $this->plugin->updateSetting(
            $this->contextId,
            ArticleNumberPlugin::SETTING_ENABLE,
            (bool) $this->getData('enableWorkNumber'),
            'bool'
        );
        // Remember the manager's choice for the details block only when it is a
        // real choice: one already made earlier, or one that differs from what the
        // journal would get by default (the theme decides the default). Saving the
        // form without touching the checkbox keeps following the theme.
        $wantHidden = !$this->getData('showArticleNumberInDetails');
        $hadChoice = $this->plugin->getSetting($this->contextId, ArticleNumberPlugin::SETTING_DETAILS_CHOICE) !== null;
        $defaultHidden = (bool) $this->plugin->getSetting($this->contextId, ArticleNumberPlugin::SETTING_HIDE_DETAILS_BLOCK)
            || $this->plugin->themeShowsArticleNumber($this->contextId);
        if ($hadChoice || $wantHidden !== $defaultHidden) {
            $this->plugin->updateSetting(
                $this->contextId,
                ArticleNumberPlugin::SETTING_DETAILS_CHOICE,
                $wantHidden ? 'hide' : 'show',
                'string'
            );
        }
        $this->plugin->updateSetting(
            $this->contextId,
            ArticleNumberPlugin::SETTING_NO_DERIVE_ON_PUBLISH,
            $this->getData('deriveNumberOnPublish') ? 0 : 1,
            'int'
        );
        $this->plugin->updateSetting(
            $this->contextId,
            ArticleNumberPlugin::SETTING_HIDE_IN_TOC,
            $this->getData('showArticleNumberInToc') ? 0 : 1,
            'int'
        );
        $this->plugin->updateSetting(
            $this->contextId,
            ArticleNumberPlugin::SETTING_UNIQUENESS_SCOPE,
            (string) $this->getData('workNumberUniquenessScope'),
            'string'
        );
        $this->plugin->updateSetting(
            $this->contextId,
            ArticleNumberPlugin::SETTING_MIGRATION_THRESHOLD,
            (int) $this->getData('workNumberMigrationThreshold'),
            'int'
        );
        $this->plugin->updateSetting(
            $this->contextId,
            ArticleNumberPlugin::SETTING_GENERATOR,
            (bool) $this->getData('workNumberGeneratorEnabled'),
            'bool'
        );
        $this->plugin->updateSetting(
            $this->contextId,
            ArticleNumberPlugin::SETTING_TEMPLATE,
            (string) $this->getData('workNumberTemplate'),
            'string'
        );
        parent::execute(...$functionArgs);
    }
}
