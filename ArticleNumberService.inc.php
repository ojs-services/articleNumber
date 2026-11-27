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
     * Falls back to 'journal' when no plugin is available. The migration engine
     * uses it, so every caller (settings panel and CLI) passes the plugin.
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
     * Used by the uniqueness gate, publish-time derivation and the migration
     * engine.
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

        // Roman-numeral page ranges of a printed front matter: "I-VIII", "II-XV".
        // Also genuine pages, so they are skipped, not listed for review.
        if (preg_match('/^(pp?\.?\s*)?[IVXLCDM]+\s*[-\x{2013}\x{2014}]\s*[IVXLCDM]+$/iu', $p)) {
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

    /**
     * Why a value classified as 'manual' needs a human decision. Returns a short
     * code the screens translate: number | roman | text | mixed | other.
     *
     * @param string $pages
     * @return string
     */
    public static function manualReason($pages)
    {
        $p = trim((string) $pages);
        if (preg_match('/^\d+$/', $p)) return 'number';             // short bare number: maybe a real single page
        if (preg_match('/^[IVXLCDM]+$/i', $p)) return 'roman';      // single roman numeral / letters only
        if (!preg_match('/\d/', $p)) return 'text';                 // words such as "Editorial"
        if (preg_match('/[A-Za-z]/', $p)) return 'mixed';           // letters + digits + punctuation: "e714-0", "e-762"
        return 'other';
    }

    // ------------------------------------------------------------------ //
    //  Derivation at publication time                                    //
    // ------------------------------------------------------------------ //

    /**
     * The number to write when an article is published or scheduled with no
     * Article Number: the Pages value, when it passes the same "derive" rule the
     * Scan uses (never a real page range or an unclear value). Returns null when
     * the Pages value is not an article number. `duplicateOf` is the publication
     * that already uses the value within the journal's uniqueness scope; the
     * caller must NOT write the value when it is set.
     *
     * @param int    $contextId
     * @param int    $submissionId
     * @param string $pages
     * @param mixed  $issueId used when uniqueness is per issue
     * @return array|null {value:string, duplicateOf:int|null}
     */
    public function deriveOnPublish($contextId, $submissionId, $pages, $issueId = null)
    {
        if (self::classifyPages($pages) !== 'derive') {
            return null;
        }
        $value = trim((string) $pages);
        return array(
            'value' => $value,
            'duplicateOf' => $this->findWorkNumberCollision($contextId, $value, (int) $submissionId, $this->getUniquenessScope($contextId), $issueId),
        );
    }

    /**
     * Submission id of a publication (the number editors see in OJS lists).
     *
     * @param int $pubId
     * @return int
     */
    public function submissionIdOf($pubId)
    {
        $result = DAORegistry::getDAO('PublicationDAO')->retrieve(
            'SELECT submission_id FROM publications WHERE publication_id = ?',
            array((int) $pubId)
        );
        foreach ($result as $row) return (int) $row->submission_id;
        return 0;
    }

    /**
     * Mark a publication's Article Number as derived by this plugin, so Undo can
     * remove it (a number typed by hand never carries the marker).
     *
     * @param int $pubId
     */
    public function markDerived($pubId)
    {
        $this->setRawSetting(DAORegistry::getDAO('PublicationDAO'), $pubId, self::SETTING_DERIVED, '1');
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
     * @return array {counts:array, samples:array, records:array, stats:array,
     *               aborted:bool, abortPubId:int|null, signature:string} —
     *               `records` has one row per article (dry-run/apply only), `stats`
     *               the article / version counts; `signature` is an md5 of the full candidate
     *               set ("pubId:value" for every derive candidate), used by the
     *               panel to detect data changes between Scan and Apply.
     */
    public function processJournal($contextId, $mode)
    {
        $dao = DAORegistry::getDAO('PublicationDAO');
        $submissionService = Services::get('submission');

        $counts = array('derive' => 0, 'manual' => 0, 'realpage' => 0, 'empty' => 0, 'hasWorkNumber' => 0, 'changed' => 0, 'duplicate' => 0, 'unpublished' => 0);
        $samples = array('derive' => array(), 'manual' => array(), 'duplicate' => array());
        // Articles whose Pages value looks like an article number.
        $candidates = array();
        $scope = $this->getUniquenessScope($contextId);
        // Identity of the full candidate set (every candidate, not just sampled),
        // for the panel's "did the data change since Scan?" staleness check.
        $sigParts = array();
        // One row per article (its current publication) for the full report / CSV.
        $records = array();
        $issueLabels = array();
        $stats = array('articles' => 0, 'published' => 0, 'other' => 0, 'versions' => 0);
        if ($mode !== 'rollback') {
            $vr = $dao->retrieve(
                'SELECT COUNT(*) AS c FROM publications p JOIN submissions s ON s.submission_id = p.submission_id WHERE s.context_id = ?',
                array((int) $contextId)
            );
            foreach ($vr as $row) { $stats['versions'] = (int) $row->c; break; }
        }

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
            $stats['articles']++;
            $status = (int) $submission->getData('status');
            if ($status === 3 || $status === 5) { // published or scheduled: converted
                $stats['published']++;
            } else {
                $stats['other']++;
            }
            $record = array(
                'publicationId' => (int) $pubId,
                'submissionId' => (int) $submission->getId(),
                'status' => (int) $submission->getData('status'),
                'issue' => $this->issueLabel($publication->getData('issueId'), $issueLabels),
                'title' => (string) $publication->getLocalizedTitle(),
                'pages' => (string) $pages,
                'result' => '',
                'reason' => '',
                'duplicate' => false,
                'duplicateOf' => null,
                'articleNumber' => '',
            );

            // Only published (3) and scheduled (5) articles are converted. An article
            // still in progress (or declined) is left alone: it gets its number the
            // normal way, typed in the Issue form before publication, or from a later
            // Scan once it is published.
            if ($status !== 3 && $status !== 5) {
                $counts['unpublished']++;
                $record['result'] = 'unpublished';
                $records[] = $record;
                continue;
            }

            if ($existingWn !== null && $existingWn !== '') {
                $counts['hasWorkNumber']++;
                $record['result'] = 'numbered';
                $record['articleNumber'] = $existingWn;
                $records[] = $record;
                continue; // idempotent: never overwrite an existing Article Number
            }

            $class = self::classifyPages($pages);
            $counts[$class]++;
            $resultOf = array('derive' => 'convert', 'manual' => 'check', 'realpage' => 'skip', 'empty' => 'nopages');
            $record['result'] = $resultOf[$class];
            if ($class === 'derive') {
                $candidates[] = array(
                    'index' => count($records),
                    'pubId' => (int) $pubId,
                    'submissionId' => (int) $submission->getId(),
                    'issueId' => $publication->getData('issueId'),
                    'pages' => (string) $pages,
                    'value' => trim((string) $pages),
                );
            } elseif ($class === 'manual') {
                // Not capped: every value that needs a human decision is listed.
                $samples['manual'][] = "#$pubId  pages='" . $pages . "'";
                $record['reason'] = self::manualReason($pages);
            }
            $records[] = $record;
        }

        // Candidates are decided only now, when all of them are known. A number is
        // NEVER written twice (within the journal's uniqueness scope): when another
        // article already has it, or when two candidates would claim it, a person
        // has to say which article it belongs to, so they go to the manual list.
        $claims = array();
        foreach ($candidates as $c) {
            $key = $this->claimKey($c, $scope);
            if ($key !== null) $claims[$key][] = $c['submissionId'];
        }
        foreach ($candidates as $c) {
            $value = $c['value'];
            $pubId = $c['pubId'];
            $hit = $this->findWorkNumberCollision($contextId, $value, $c['submissionId'], $scope, $c['issueId']);
            $otherId = $hit ? $this->submissionIdOf($hit) : null;
            $key = $this->claimKey($c, $scope);
            if (!$otherId && $key !== null && count($claims[$key]) > 1) {
                foreach ($claims[$key] as $sid) {
                    if ($sid !== $c['submissionId']) { $otherId = $sid; break; }
                }
            }
            if ($otherId) {
                $counts['derive']--;
                $counts['manual']++;
                $counts['duplicate']++;
                $records[$c['index']]['result'] = 'check';
                $records[$c['index']]['reason'] = 'duplicate';
                $records[$c['index']]['duplicate'] = true;
                $records[$c['index']]['duplicateOf'] = $otherId;
                // Not capped: these need a human decision too.
                $samples['duplicate'][] = "#$pubId  pages='" . $c['pages'] . "'  same number as submission #$otherId";
                continue;
            }
            $records[$c['index']]['articleNumber'] = $value;
            $sigParts[] = $pubId . ':' . $value; // full-candidate-set identity
            if (count($samples['derive']) < self::SAMPLE_LIMIT) $samples['derive'][] = "#$pubId  pages='" . $c['pages'] . "'  ->  workNumber='" . $value . "'";
            if ($mode === 'apply') {
                $this->setRawSetting($dao, $pubId, self::PROP_WORK_NUMBER, $value);
                $this->setRawSetting($dao, $pubId, self::SETTING_DERIVED, '1');
                // pages-immutability self-check (cheap, per record)
                $after = $this->getRawSetting($dao, $pubId, 'pages');
                if ($after !== null && $after !== $c['pages']) {
                    return array('counts' => $counts, 'samples' => $samples, 'records' => $records, 'stats' => $stats, 'aborted' => true, 'abortPubId' => $pubId, 'signature' => md5(implode('|', $sigParts)));
                }
                $counts['changed']++;
            }
        }

        return array('counts' => $counts, 'samples' => $samples, 'records' => $records, 'stats' => $stats, 'aborted' => false, 'abortPubId' => null, 'signature' => md5(implode('|', $sigParts)));
    }

    /**
     * Key under which two migration candidates would claim the same number: the
     * value (compared without case, as the database does), plus the issue when
     * uniqueness is per issue. Null when the candidate cannot clash (per-issue
     * uniqueness, no issue assigned).
     *
     * @param array  $candidate
     * @param string $scope 'journal' | 'issue'
     * @return string|null
     */
    private function claimKey(array $candidate, $scope)
    {
        $value = function_exists('mb_strtolower') ? mb_strtolower($candidate['value'], 'UTF-8') : strtolower($candidate['value']);
        if ($scope !== 'issue') {
            return $value;
        }
        return empty($candidate['issueId']) ? null : ((int) $candidate['issueId']) . '|' . $value;
    }

    /**
     * Human-readable issue label (e.g. "Vol. 4 No. 2 (2026)") for the reports,
     * cached per run. Empty when the publication is not assigned to an issue.
     *
     * @param mixed $issueId
     * @param array $cache
     * @return string
     */
    private function issueLabel($issueId, array &$cache)
    {
        $issueId = (int) $issueId;
        if ($issueId <= 0) return '';
        if (!isset($cache[$issueId])) {
            $issue = Services::get('issue')->get($issueId);
            $cache[$issueId] = $issue ? (string) $issue->getIssueIdentification() : '';
        }
        return $cache[$issueId];
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
