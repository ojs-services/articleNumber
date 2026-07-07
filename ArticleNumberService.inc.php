<?php
/**
 * @file ArticleNumberService.inc.php
 *
 * Article Number Plugin for OJS — single source of truth for the plugin's data
 * logic. Introduced in 1.6.0 as a pure refactor: the uniqueness check, the
 * page→article-number classification, and the migration (derive / rollback)
 * engine were MOVED here verbatim from ArticleNumberPlugin and the CLI tool, so
 * each rule has exactly one implementation. The plugin hook callbacks, the CLI
 * migration tool, and the settings-panel migration handler all delegate here.
 *
 * Non-negotiables preserved verbatim from the original copies:
 *   - Only `workNumber` (+ a `workNumberDerived` marker) is ever written; the
 *     core `pages` field is READ ONLY in every path.
 *   - apply performs a per-record pages-immutability self-check and aborts the
 *     run if `pages` ever changed.
 *   - rollback removes ONLY values this engine itself marked as derived.
 *   - Uniqueness defaults to journal scope and always excludes the article's own
 *     submission (all of its versions).
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 */

class ArticleNumberService
{
    /** PKP-aligned stored data key. Mirrors ArticleNumberPlugin::PROP_WORK_NUMBER. */
    const PROP_WORK_NUMBER = 'workNumber';

    /** Marker proving the migration engine derived a value (enables precise rollback). */
    const SETTING_DERIVED = 'workNumberDerived';

    /** Per-journal uniqueness scope setting name. Mirrors the plugin constant. */
    const SETTING_UNIQUENESS_SCOPE = 'workNumberUniquenessScope';

    /** Per-category sample cap in migration reports (preserves prior output). */
    const SAMPLE_LIMIT = 25;

    /** @var object|null Owning plugin, for per-journal setting reads (optional — the
     *  migration engine never needs it; only getUniquenessScope does). */
    private $plugin;

    public function __construct($plugin = null)
    {
        $this->plugin = $plugin;
    }

    // ------------------------------------------------------------------ //
    //  Uniqueness                                                        //
    // ------------------------------------------------------------------ //

    /**
     * Resolve the per-journal uniqueness scope ('journal' default, or 'issue').
     * Falls back to 'journal' when no plugin is available (e.g. CLI), which the
     * migration engine never relies on.
     *
     * @param int $contextId
     * @return string
     */
    public function getUniquenessScope($contextId)
    {
        $scope = $this->plugin ? $this->plugin->getSetting($contextId, self::SETTING_UNIQUENESS_SCOPE) : null;
        return ($scope === 'issue') ? 'issue' : 'journal';
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
        return $this->findWorkNumberCollision($contextId, $value, $excludeSubmissionId, $scope, $issueId) !== null;
    }

    /**
     * Like isWorkNumberTaken(), but returns the colliding publication id (or null).
     * Used both for the boolean uniqueness gate and for the migration tool's
     * duplicate REPORTING. Journal scope is the default the migration engine uses.
     *
     * @return int|null
     */
    public function findWorkNumberCollision($contextId, $value, $excludeSubmissionId, $scope, $issueId = null)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $dao = DAORegistry::getDAO('PublicationDAO');

        if ($scope === 'issue') {
            if (empty($issueId)) {
                return null; // not assigned to an issue yet → cannot issue-collide
            }
            $sql = "SELECT wn.publication_id AS hit
                    FROM publication_settings wn
                    JOIN publication_settings iss ON iss.publication_id = wn.publication_id
                         AND iss.setting_name = 'issueId' AND iss.setting_value = ?
                    JOIN publications p ON p.publication_id = wn.publication_id
                    WHERE wn.setting_name = ? AND wn.locale = '' AND wn.setting_value = ?
                      AND p.submission_id <> ?
                    LIMIT 1";
            $params = array((string) $issueId, self::PROP_WORK_NUMBER, $value, (int) $excludeSubmissionId);
        } else {
            $sql = "SELECT wn.publication_id AS hit
                    FROM publication_settings wn
                    JOIN publications p ON p.publication_id = wn.publication_id
                    JOIN submissions s ON s.submission_id = p.submission_id
                    WHERE wn.setting_name = ? AND wn.locale = '' AND wn.setting_value = ?
                      AND s.context_id = ?
                      AND p.submission_id <> ?
                    LIMIT 1";
            $params = array(self::PROP_WORK_NUMBER, $value, (int) $contextId, (int) $excludeSubmissionId);
        }

        foreach ($dao->retrieve($sql, $params) as $row) {
            return (int) $row->hit;
        }
        return null;
    }

    // ------------------------------------------------------------------ //
    //  Page classification                                               //
    // ------------------------------------------------------------------ //

    /**
     * Classify a raw `pages` value.
     * @return string one of: derive | manual | realpage | empty
     */
    public static function classifyPages($pages)
    {
        $p = trim((string) $pages);
        if ($p === '') return 'empty';

        // Genuine page range(s): "245-260", "pp. 245-260", "12-15, 30-31".
        // These are real printed pages — never an auto candidate.
        if (preg_match('/^(pp?\.?\s*)?\d+\s*[-\x{2013}\x{2014}]\s*\d+(\s*[,;]\s*\d+(\s*[-\x{2013}\x{2014}]\s*\d+)?)*$/u', $p)) {
            return 'realpage';
        }

        // Confident article-number stand-ins:
        if (preg_match('/^e\d+$/i', $p)) return 'derive';                 // e0231470
        if (preg_match('/^[A-Za-z]\d+\.\d+$/', $p)) return 'derive';      // P2.16
        if (preg_match('/^[A-Za-z]{1,3}\d{2,}$/', $p)) return 'derive';   // A12, e123, ID45
        if (preg_match('/^\d{6,}$/', $p)) return 'derive';               // 051101 (long zero-padded)

        // Everything else (a bare short number that might be a real single
        // page, roman numerals, odd punctuation, etc.) is ambiguous → a human
        // must decide. The tool never auto-derives these.
        return 'manual';
    }

    // ------------------------------------------------------------------ //
    //  Migration engine                                                  //
    // ------------------------------------------------------------------ //

    /**
     * Process ONE journal in the given mode and return a structured result.
     * This is the exact per-journal loop the CLI tool used to inline; the CLI
     * and the settings panel both format these structured results themselves.
     *
     *   dry-run   classify + count + sample; writes NOTHING.
     *   apply     additionally derive workNumber (+ marker) for confident
     *             candidates, with a per-record pages self-check. Idempotent.
     *   rollback  remove ONLY the workNumbers this engine derived.
     *
     * On an apply self-check failure the method returns immediately with
     * aborted=true and abortPubId set; the caller surfaces the abort and stops.
     *
     * @param int    $contextId
     * @param string $mode 'dry-run' | 'apply' | 'rollback'
     * @return array {counts:array, samples:array, aborted:bool, abortPubId:int|null,
     *               signature:string} — `signature` is an md5 of the full candidate
     *               set ("pubId:value" for every derive candidate), used by the
     *               panel to detect data changes between Scan and Apply.
     */
    public function processJournal($contextId, $mode)
    {
        $dao = DAORegistry::getDAO('PublicationDAO');
        $submissionService = Services::get('submission');

        $counts = array('derive' => 0, 'manual' => 0, 'realpage' => 0, 'empty' => 0, 'hasWorkNumber' => 0, 'changed' => 0, 'duplicate' => 0);
        $samples = array('derive' => array(), 'manual' => array(), 'duplicate' => array());
        // Track derived values within this run to catch intra-batch duplicates.
        $derivedSeen = array();
        // Identity of the full candidate set (every candidate, not just sampled),
        // for the panel's "did the data change since Scan?" staleness check.
        $sigParts = array();

        $submissions = $submissionService->getMany(array('contextId' => $contextId));
        foreach ($submissions as $submission) {
            $publication = $submission->getCurrentPublication();
            if (!$publication) continue;
            $pubId = $publication->getId();
            $pages = $publication->getData('pages'); // core field, read-only here

            $existingWn = $this->getRawSetting($dao, $pubId, self::PROP_WORK_NUMBER);
            $isDerived  = $this->getRawSetting($dao, $pubId, self::SETTING_DERIVED) === '1';

            if ($mode === 'rollback') {
                // Remove ONLY values this tool derived; leave manual ones alone.
                if ($isDerived) {
                    $this->deleteRawSetting($dao, $pubId, self::PROP_WORK_NUMBER);
                    $this->deleteRawSetting($dao, $pubId, self::SETTING_DERIVED);
                    $counts['changed']++;
                    if (count($samples['derive']) < self::SAMPLE_LIMIT) $samples['derive'][] = "#$pubId  removed '" . $existingWn . "'";
                }
                continue;
            }

            // dry-run / apply
            if ($existingWn !== null && $existingWn !== '') {
                $counts['hasWorkNumber']++;
                continue; // idempotent: never overwrite an existing Article Number
            }

            $class = self::classifyPages($pages);
            $counts[$class]++;
            if ($class === 'derive') {
                $value = trim((string) $pages);
                $sigParts[] = $pubId . ':' . $value; // full-candidate-set identity
                // Duplicate detection (journal scope) — REPORT only, never blocks.
                // A collision with an existing workNumber elsewhere, or with a
                // value already derived earlier in this run, is flagged.
                $dupWith = isset($derivedSeen[$value]) ? $derivedSeen[$value] : $this->findWorkNumberCollision($contextId, $value, (int) $submission->getId(), 'journal');
                if ($dupWith) {
                    $counts['duplicate']++;
                    if (count($samples['duplicate']) < self::SAMPLE_LIMIT) $samples['duplicate'][] = "DUPLICATE: '$value'  #$pubId collides with #$dupWith";
                }
                $derivedSeen[$value] = $pubId;

                if (count($samples['derive']) < self::SAMPLE_LIMIT) $samples['derive'][] = "#$pubId  pages='" . $pages . "'  ->  workNumber='" . $pages . "'";
                if ($mode === 'apply') {
                    $before = $publication->getData('pages');
                    $this->setRawSetting($dao, $pubId, self::PROP_WORK_NUMBER, (string) $pages);
                    $this->setRawSetting($dao, $pubId, self::SETTING_DERIVED, '1');
                    // pages-immutability self-check (cheap, per record)
                    $after = $this->getRawSetting($dao, $pubId, 'pages');
                    if ($after !== null && $after !== $before) {
                        return array('counts' => $counts, 'samples' => $samples, 'aborted' => true, 'abortPubId' => $pubId, 'signature' => md5(implode('|', $sigParts)));
                    }
                    $counts['changed']++;
                }
            } elseif ($class === 'manual') {
                if (count($samples['manual']) < self::SAMPLE_LIMIT) $samples['manual'][] = "#$pubId  pages='" . $pages . "'";
            }
        }

        return array('counts' => $counts, 'samples' => $samples, 'aborted' => false, 'abortPubId' => null, 'signature' => md5(implode('|', $sigParts)));
    }

    // ------------------------------------------------------------------ //
    //  Raw publication_settings helpers (moved from the CLI tool)        //
    // ------------------------------------------------------------------ //

    private function getRawSetting($dao, $pubId, $name)
    {
        $value = null;
        $result = $dao->retrieve(
            'SELECT setting_value FROM publication_settings WHERE publication_id = ? AND setting_name = ? AND locale = ?',
            array((int) $pubId, $name, '')
        );
        foreach ($result as $row) { $value = $row->setting_value; break; }
        return $value;
    }

    private function setRawSetting($dao, $pubId, $name, $value)
    {
        $dao->update(
            'INSERT INTO publication_settings (publication_id, locale, setting_name, setting_value) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
            array((int) $pubId, '', $name, (string) $value)
        );
    }

    private function deleteRawSetting($dao, $pubId, $name)
    {
        $dao->update(
            'DELETE FROM publication_settings WHERE publication_id = ? AND setting_name = ? AND locale = ?',
            array((int) $pubId, $name, '')
        );
    }
}
