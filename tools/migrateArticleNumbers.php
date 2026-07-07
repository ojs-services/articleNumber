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
 * Since 1.6.0 this is a THIN CLI SHELL: all logic lives in ArticleNumberService
 * (the same engine the settings-panel migration uses). This file only parses
 * arguments, resolves the journal scope, and formats the service's results.
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

require(dirname(__FILE__) . '/../../../../tools/bootstrap.inc.php');
require_once(dirname(__FILE__) . '/../ArticleNumberService.inc.php');

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

        $service = new ArticleNumberService();

        $grand = array('derive' => 0, 'manual' => 0, 'realpage' => 0, 'empty' => 0, 'hasWorkNumber' => 0, 'changed' => 0, 'duplicate' => 0);

        echo "== Article Number migration ==  mode={$this->mode}  scope={$this->scope}\n\n";

        foreach ($contexts as $context) {
            $cid = $context->getId();
            echo "Journal: " . $context->getPath() . " (id $cid)\n";

            $res = $service->processJournal($cid, $this->mode);
            foreach ($res['counts'] as $k => $v) { $grand[$k] += $v; }
            $samples = $res['samples'];

            if (!empty($samples['derive'])) {
                echo "  " . ($this->mode === 'rollback' ? 'Rolled back' : ($this->mode === 'apply' ? 'Derived' : 'Would derive')) . ":\n";
                foreach ($samples['derive'] as $s) echo "    $s\n";
            }
            if (!empty($samples['manual'])) {
                echo "  Needs manual review (NOT auto-derived):\n";
                foreach ($samples['manual'] as $s) echo "    $s\n";
            }
            if (!empty($samples['duplicate'])) {
                echo "  Duplicates (still derived — historical data is kept as-is; review):\n";
                foreach ($samples['duplicate'] as $s) echo "    $s\n";
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
            echo "  real page ranges (skipped): {$grand['realpage']}\n";
            echo "  empty pages (skipped):      {$grand['empty']}\n";
            echo "  already had workNumber:     {$grand['hasWorkNumber']}\n";
            if ($grand['duplicate']) echo "  DUPLICATES flagged:          {$grand['duplicate']} (derived anyway — see per-journal list)\n";
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
