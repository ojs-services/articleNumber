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
        // The form shows a "Show …" checkbox; internally we store the inverse
        // "hide" flag (truthy = hide). Default = show.
        $hide = $this->plugin->getSetting($this->contextId, ArticleNumberPlugin::SETTING_HIDE_DETAILS_BLOCK);
        $this->setData('showArticleNumberInDetails', !$hide);
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
        $this->readUserVars(array('enableWorkNumber', 'showArticleNumberInDetails', 'workNumberUniquenessScope', 'workNumberGeneratorEnabled', 'workNumberTemplate', 'workNumberMigrationThreshold'));

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
        // Store the inverse "hide" flag as a truthy int (1 = hide) so it reads
        // back reliably; "show" stores 0 (which reads back as null = default show).
        $this->plugin->updateSetting(
            $this->contextId,
            ArticleNumberPlugin::SETTING_HIDE_DETAILS_BLOCK,
            $this->getData('showArticleNumberInDetails') ? 0 : 1,
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
