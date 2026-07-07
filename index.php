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

require_once('ArticleNumberPlugin.inc.php');

return new ArticleNumberPlugin();
