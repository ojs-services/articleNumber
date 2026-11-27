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

// This package is for OJS 3.3. OJS 3.4 and 3.5 have already loaded their
// namespaced application class when a plugin's index.php is read; OJS 3.3 has no
// such class. So on 3.4+ (wrong package) this file does nothing.
// The second argument must stay false: with autoloading, another plugin's class
// loader on a 3.3 site can load a core file twice, which is a fatal error. Do not
// test the plain name 'PKPApplication': 3.4 and 3.5 also define it, as an alias.
if (class_exists('PKP\core\PKPApplication', false)) {
    return;
}

require_once('ArticleNumberPlugin.inc.php');

return new ArticleNumberPlugin();
