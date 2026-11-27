<?php

/**
 * @defgroup plugins_generic_articleNumber Article Number Plugin
 */

/**
 * @file index.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @ingroup plugins_generic_articleNumber
 * @brief Wrapper for the Article Number plugin. OJS's PluginRegistry loads each
 *        generic plugin by including its index.php and using the returned
 *        object, so this thin wrapper is required for the plugin to appear in
 *        the plugin manager list.
 */

// This package is for OJS 3.4 and 3.5. OJS 3.3 loads every plugin through this
// file, so a package installed on the wrong version is stopped here: without this,
// the whole 3.3 site fails. OJS 3.4+ has already loaded its namespaced
// application class at this point; OJS 3.3 has no such class.
// The second argument must stay false: with autoloading, another plugin's class
// loader on a 3.3 site can load a core file twice, which is a fatal error. Do not
// test the plain name 'PKPApplication': 3.3, 3.4 and 3.5 all define it.
if (!class_exists('PKP\core\PKPApplication', false)) {
    // OJS 3.3 expects a plugin object here (returning nothing trips an assertion),
    // so return a placeholder that adds no hooks and says what to install.
    // tara-yoksay: P04 (import() runs only on OJS 3.3, where it exists)
    import('lib.pkp.classes.plugins.GenericPlugin');
    if (!class_exists('ArticleNumberPlugin', false)) {
        class ArticleNumberPlugin extends GenericPlugin
        {
            public function register($category, $path, $mainContextId = null)
            {
                parent::register($category, $path, $mainContextId);
                return true; // listed, but adds no hooks: the site is not touched
            }

            public function getDisplayName()
            {
                return 'Article Number (wrong package)';
            }

            public function getDescription()
            {
                return 'This package is for OJS 3.4 and 3.5 and does nothing on this site. '
                    . 'Delete it and install the OJS 3.3 package (articleNumber-ojs3.3-<version>.tar.gz).';
            }
        }
    }
    return new ArticleNumberPlugin();
}

// A site upgraded from OJS 3.3 can still hold the old plugin record while the plugin
// folder is being replaced; load the class explicitly so the first request after a
// fresh upload does not depend on the autoloader's cached "class not found" answer.
if (!class_exists(\APP\plugins\generic\articleNumber\ArticleNumberPlugin::class, false)) {
    require_once __DIR__ . '/ArticleNumberPlugin.php';
}

return new \APP\plugins\generic\articleNumber\ArticleNumberPlugin();
