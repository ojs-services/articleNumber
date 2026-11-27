<?php
/**
 * @file plugins/generic/articleNumber/tools/migrateArticleNumbers.php
 *
 * Article Number Plugin for OJS — migration / derivation CLI tool.
 *
 * DERIVES an Article Number (workNumber) from page-field values that are really
 * article-number stand-ins (e.g. "e0231470", "P2.16"). This is a DERIVATION,
 * not a move: the source `pages` field is READ ONLY and is NEVER written to in
 * any mode. Derived values are marked so rollback can remove exactly (and only)
 * what this tool created — manually entered Article Numbers are never touched.
 *
 * This is a thin CLI shell: all logic lives in ArticleNumberService (the same
 * engine the settings-panel migration uses). This file only parses arguments,
 * resolves the journal scope, and formats the service's results.
 *
 * Modes:
 *   dry-run   Report what would be derived/flagged. Writes NOTHING.
 *   apply     Derive workNumber for confident candidates (writes ONLY
 *             workNumber + a derived marker; never pages). Idempotent.
 *   rollback  Remove ONLY workNumbers previously derived by this tool.
 *
 * Scope: a single journal path, or `all` (default).
 *
 * Usage:
 *   php plugins/generic/articleNumber/tools/migrateArticleNumbers.php <dry-run|apply|rollback> [journalPath|all]
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 */

// OJS root: five levels above this file when the plugin sits in its normal
// place; otherwise (e.g. a linked folder) fall back to the working directory,
// which is the OJS root when the tool is run as documented above.
$ojsRoot = dirname(__FILE__, 5);
if (!is_file($ojsRoot . '/tools/bootstrap.php')) {
    $ojsRoot = getcwd();
}
require($ojsRoot . '/tools/bootstrap.php');

use APP\core\Application;
use APP\plugins\generic\articleNumber\ArticleNumberPlugin;
use APP\plugins\generic\articleNumber\ArticleNumberService;
use PKP\cliTool\CommandLineTool;

class migrateArticleNumbers extends CommandLineTool {

    /** @var string */
    private $mode;
    /** @var string */
    private $scope;

    public function usage() {
        echo "Article Number — migration / derivation tool\n"
           . "Usage: php {$this->scriptName} <dry-run|apply|rollback> [journalPath|all]\n\n"
           . "  dry-run   Report what WOULD be derived/flagged; writes NOTHING.\n"
           . "  apply     Derive workNumber for confident candidates.\n"
           . "            Writes ONLY workNumber (+ a derived marker). NEVER pages.\n"
           . "  rollback  Remove ONLY the workNumbers this tool derived.\n\n"
           . "  journalPath  Restrict to one journal (its URL path). Default: all.\n";
    }

    public function execute() {
        $this->mode = isset($this->argv[0]) ? $this->argv[0] : '';
        $this->scope = isset($this->argv[1]) ? $this->argv[1] : 'all';

        if (!in_array($this->mode, array('dry-run', 'apply', 'rollback'), true)) {
            $this->usage();
            return;
        }

        $contextDao = Application::getContextDAO();
        if ($this->scope === 'all') {
            $contexts = $contextDao->getAll()->toArray();
        } else {
            $one = $contextDao->getByPath($this->scope);
            if (!$one) { fwrite(STDERR, "Unknown journal path: {$this->scope}\n"); return; }
            $contexts = array($one);
        }

        // With the plugin, so each journal's uniqueness scope is honoured.
        $service = new ArticleNumberService(new ArticleNumberPlugin());

        $grand = array('derive' => 0, 'manual' => 0, 'realpage' => 0, 'empty' => 0, 'hasWorkNumber' => 0, 'changed' => 0, 'duplicate' => 0, 'unpublished' => 0);

        echo "== Article Number migration ==  mode={$this->mode}  scope={$this->scope}\n\n";

        foreach ($contexts as $context) {
            $cid = $context->getId();
            echo "Journal: " . $context->getPath() . " (id $cid)\n";

            $res = $service->processJournal($cid, $this->mode);
            foreach ($res['counts'] as $k => $v) { $grand[$k] += $v; }
            $samples = $res['samples'];
            if ($this->mode !== 'rollback' && !empty($res['stats']['articles'])) {
                $st = $res['stats'];
                echo "  {$st['articles']} article(s): {$st['published']} published or scheduled, {$st['other']} in progress or declined; {$st['versions']} publication version(s) in total (only the current version of each article is converted).
";
            }

            if (!empty($samples['derive'])) {
                echo "  " . ($this->mode === 'rollback' ? 'Rolled back' : ($this->mode === 'apply' ? 'Derived' : 'Would derive')) . ":\n";
                foreach ($samples['derive'] as $s) echo "    $s\n";
            }
            if (!empty($samples['manual'])) {
                echo "  Needs manual review (NOT converted; pages and DOI are left exactly as they are):\n";
                foreach ($samples['manual'] as $s) echo "    $s  -> skipped, reported only\n";
            }
            if (!empty($samples['duplicate'])) {
                echo "  Same number as another article (NOT converted; decide which article it belongs to):\n";
                foreach ($samples['duplicate'] as $s) echo "    $s  -> skipped, reported only\n";
            }
            echo "\n";

            if (!empty($res['aborted'])) {
                fwrite(STDERR, "ABORT: pages changed for #{$res['abortPubId']} — this must never happen.\n");
                return;
            }
        }

        echo "== Totals ==\n";
        if ($this->mode === 'rollback') {
            echo "  derived workNumbers removed: {$grand['changed']}\n";
        } else {
            echo "  candidates (derive):        {$grand['derive']}\n";
            echo "  needs manual review:        {$grand['manual']}\n";
            if ($grand['duplicate']) echo "    of which same number as another article: {$grand['duplicate']} (not written)\n";
            echo "  real page ranges (skipped): {$grand['realpage']}\n";
            echo "  empty pages (skipped):      {$grand['empty']}\n";
            echo "  already had workNumber:     {$grand['hasWorkNumber']}\n";
            echo "  not published (skipped):    {$grand['unpublished']}\n";
            if ($this->mode === 'apply') echo "  WRITTEN (workNumber derived): {$grand['changed']}\n";
            if ($this->mode === 'dry-run') echo "  (dry-run: nothing was written)\n";
        }
        echo "  NOTE: the `pages` field was never modified.\n";
    }
}

// Auto-run when invoked directly; skipped when required for unit testing
// (define ANP_MIGRATE_NOEXEC before requiring this file).
if (!defined('ANP_MIGRATE_NOEXEC')) {
    $tool = new migrateArticleNumbers(isset($argv) ? $argv : array());
    $tool->execute();
}
