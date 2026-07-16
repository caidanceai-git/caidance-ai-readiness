<?php
/**
 * The First Fix: creates (and can revert) the site's /llms.txt file.
 *
 * Safety model, in order of importance:
 *
 *   1. CREATE-ONLY. The fixer writes llms.txt only where none exists.
 *      It never overwrites, edits, or deletes a file it did not write.
 *   2. APPROVAL-GATED. Nothing is written until the owner has seen the
 *      exact proposed file content and clicked approve (UI enforces
 *      this; apply() additionally re-checks the world at write time).
 *   3. CONFLICT-AWARE. If a physical llms.txt exists, or anything else
 *      (an SEO plugin, the server) already serves /llms.txt, the fixer
 *      reports who and steps aside.
 *   4. VERIFIED. After writing, the file is re-read and hash-compared,
 *      the URL is fetched (cache-busted), and the full scan re-runs so
 *      the score reflects reality. Everything lands in the EvidenceLog.
 *   5. REVERSIBLE. Revert deletes the file only when its current hash
 *      matches what was written (recorded in the marker option). An
 *      edited file is never deleted.
 *
 * @package Caidance\AiReadiness
 */

declare(strict_types=1);

namespace Caidance\AiReadiness\Fixer;

use Caidance\AiReadiness\Http\SiteFetcher;
use Caidance\AiReadiness\Scanner\LocalScanner;
use Caidance\AiReadiness\Storage\EvidenceLog;
use Caidance\AiReadiness\Storage\ScanHistoryRepository;

final class LlmsTxtFixer
{
    public const CHECK_ID = 'llms_txt';

    // States reported by status() — drive which panel the Tools page renders.
    public const STATE_FIXABLE      = 'fixable';
    public const STATE_APPLIED      = 'applied_by_us';
    public const STATE_EDITED       = 'modified_after_apply';
    public const STATE_FOREIGN_FILE = 'file_exists_foreign';
    public const STATE_VIRTUAL      = 'served_virtually';
    public const STATE_NOT_WRITABLE = 'not_writable';
    public const STATE_UNSUPPORTED  = 'unsupported_install';

    private const MARKER_OPTION = 'caidance_air_llms_txt_marker';

    /**
     * Active plugins known to serve /llms.txt virtually, keyed by
     * directory slug. Used only to NAME the likely owner when stepping
     * aside — detection itself is behavioral (the URL responds).
     */
    private const KNOWN_PROVIDERS = [
        'wordpress-seo'       => 'Yoast SEO',
        'seo-by-rank-math'    => 'Rank Math SEO',
        'all-in-one-seo-pack' => 'All in One SEO',
        'website-llms-txt'    => 'Website LLMs.txt',
    ];

    public function path(): string
    {
        return trailingslashit(ABSPATH) . 'llms.txt';
    }

    /**
     * Standard install = home and site URL match, so ABSPATH is the
     * public root and /llms.txt maps to path(). Subdirectory installs
     * fall back to manual guidance rather than guessing.
     */
    public function isStandardInstall(): bool
    {
        return untrailingslashit((string) home_url('/')) === untrailingslashit((string) site_url('/'));
    }

    /**
     * @return array<string, mixed>|null The marker recorded at apply time.
     */
    public function marker(): ?array
    {
        $marker = get_option(self::MARKER_OPTION, null);
        return is_array($marker) ? $marker : null;
    }

    /**
     * Determines the current fix state WITHOUT live HTTP. Virtual-serve
     * detection uses the stored scan's llms_txt result (no physical file
     * + check saw content = something else serves it). apply() re-checks
     * live before any write, so a stale read here can never cause a bad
     * write — at worst the panel copy is one scan behind.
     *
     * @param array<string, mixed>|null $latestLlmsCheck The llms_txt row
     *        from the most recent stored scan, if any.
     * @return array{state: string, path: string, owner: string, marker: array<string, mixed>|null}
     */
    public function status(?array $latestLlmsCheck): array
    {
        $path   = $this->path();
        $marker = $this->marker();

        if (file_exists($path)) {
            $hash = (string) sha1_file($path);
            if ($marker !== null && isset($marker['hash']) && $marker['hash'] === $hash) {
                return ['state' => self::STATE_APPLIED, 'path' => $path, 'owner' => '', 'marker' => $marker];
            }
            if ($marker !== null) {
                return ['state' => self::STATE_EDITED, 'path' => $path, 'owner' => '', 'marker' => $marker];
            }
            return ['state' => self::STATE_FOREIGN_FILE, 'path' => $path, 'owner' => '', 'marker' => null];
        }

        // No physical file. If the last scan still saw /llms.txt respond,
        // another plugin or the server itself is serving it virtually.
        $scanSawContent = is_array($latestLlmsCheck)
            && in_array(($latestLlmsCheck['status'] ?? ''), ['pass', 'partial'], true);
        if ($scanSawContent) {
            return ['state' => self::STATE_VIRTUAL, 'path' => $path, 'owner' => $this->likelyProvider(), 'marker' => null];
        }

        if (!$this->isStandardInstall()) {
            return ['state' => self::STATE_UNSUPPORTED, 'path' => $path, 'owner' => '', 'marker' => null];
        }

        if (!wp_is_writable(ABSPATH)) {
            return ['state' => self::STATE_NOT_WRITABLE, 'path' => $path, 'owner' => '', 'marker' => null];
        }

        return ['state' => self::STATE_FIXABLE, 'path' => $path, 'owner' => '', 'marker' => null];
    }

    /**
     * The exact content apply() would write. Shown verbatim in the
     * preview — deterministic, so preview and write always match.
     */
    public function previewContent(): string
    {
        return (new LlmsTxtContentBuilder())->build();
    }

    /**
     * Applies the fix. Re-validates the world at write time (the UI's
     * status may be a scan old), writes via WP_Filesystem, records the
     * marker, verifies file + URL, re-runs the full scan, and logs
     * evidence. Refusals are also logged — a refusal is evidence too.
     *
     * @return array{ok: bool, code: string, message: string, score_before: int|null, score_after: int|null}
     */
    public function apply(): array
    {
        if (!current_user_can('manage_options')) {
            return $this->refuse('not_allowed', 'You do not have permission to apply fixes on this site.');
        }
        if (!$this->isStandardInstall()) {
            return $this->refuse('unsupported_install', 'This WordPress install serves the site from a different address than WordPress itself, so the plugin cannot be certain where /llms.txt must live. Create the file manually at your public site root.');
        }

        clearstatcache();
        if (file_exists($this->path())) {
            return $this->refuse('file_appeared', 'An llms.txt file now exists that Caidance did not create. Nothing was written or changed. Re-run a scan to refresh the results.');
        }

        // Live conflict check: if anything already serves /llms.txt
        // (SEO plugin route, server rule), step aside.
        $fetcher = new SiteFetcher();
        $live    = $fetcher->get($fetcher->urlFor('/llms.txt'));
        if ($live['ok']) {
            return $this->refuse('served_virtually', 'Something on your site already serves /llms.txt' . ($this->likelyProvider() !== '' ? ' (likely ' . $this->likelyProvider() . ')' : '') . '. Caidance steps aside rather than creating a duplicate.');
        }

        $before  = $this->latestCheckSnapshot();
        $content = $this->previewContent();

        $filesystem = $this->filesystem();
        if ($filesystem === null) {
            return $this->refuse('filesystem_unavailable', 'WordPress could not get direct filesystem access on this host. Copy the previewed content into a file named llms.txt at your site root instead.');
        }
        if (!$filesystem->put_contents($this->path(), $content, FS_CHMOD_FILE)) {
            return $this->refuse('write_failed', 'The file could not be written. Your hosting may restrict writes to the site root — copy the previewed content into llms.txt manually.');
        }

        update_option(self::MARKER_OPTION, [
            'hash'       => sha1($content),
            'length'     => strlen($content),
            'applied_at' => current_time('mysql'),
            'applied_by' => $this->currentUserLogin(),
        ], false);

        // Verify: the file on disk, then the URL (cache-busted so a CDN
        // that cached the old 404 cannot fake a failure or a success).
        clearstatcache();
        $fileVerified = file_exists($this->path()) && sha1_file($this->path()) === sha1($content);
        $verifyUrl    = $fetcher->urlFor('/llms.txt') . '?caidance-air-verify=' . time();
        $servedCheck  = (new SiteFetcher())->get($verifyUrl);
        $urlVerified  = $servedCheck['ok'] && trim($servedCheck['body']) === trim($content);

        $after = $this->rescanAndSnapshot();

        (new EvidenceLog())->append([
            'event'   => 'applied',
            'fix'     => self::CHECK_ID,
            'details' => sprintf(
                'Created %s (%d bytes). File hash verified: %s. Served at /llms.txt: %s.',
                $this->path(),
                strlen($content),
                $fileVerified ? 'yes' : 'NO',
                $urlVerified ? 'yes (HTTP ' . $servedCheck['status_code'] . ')' : 'not yet (HTTP ' . $servedCheck['status_code'] . ' — a cache layer may need a few minutes)'
            ),
            'before'  => $before,
            'after'   => $after,
        ]);

        $message = 'llms.txt created and verified on disk.';
        if ($urlVerified) {
            $message = 'llms.txt created — file verified and serving at /llms.txt.';
        } elseif ($fileVerified) {
            $message = 'llms.txt created and verified on disk. The URL is not serving it yet — a cache layer may need a few minutes.';
        }

        return [
            'ok'           => true,
            'code'         => 'applied',
            'message'      => $message,
            'score_before' => is_array($before) ? ($before['total_score'] ?? null) : null,
            'score_after'  => is_array($after) ? ($after['total_score'] ?? null) : null,
        ];
    }

    /**
     * Reverts the fix: deletes the file ONLY if it still matches the
     * hash recorded at apply time, then re-scans and logs evidence.
     *
     * @return array{ok: bool, code: string, message: string, score_before: int|null, score_after: int|null}
     */
    public function revert(): array
    {
        if (!current_user_can('manage_options')) {
            return $this->refuse('not_allowed', 'You do not have permission to revert fixes on this site.');
        }

        $marker = $this->marker();
        if ($marker === null) {
            return $this->refuse('nothing_to_revert', 'Caidance has no record of applying this fix, so there is nothing to revert.');
        }

        clearstatcache();
        if (!file_exists($this->path())) {
            delete_option(self::MARKER_OPTION);
            (new EvidenceLog())->append([
                'event'   => 'reverted',
                'fix'     => self::CHECK_ID,
                'details' => 'The file was already gone (removed outside the plugin). Cleared the apply record.',
            ]);
            return ['ok' => true, 'code' => 'reverted', 'message' => 'The file was already removed. Caidance cleared its apply record.', 'score_before' => null, 'score_after' => null];
        }

        if ((string) sha1_file($this->path()) !== (string) ($marker['hash'] ?? '')) {
            return $this->refuse('edited_since_apply', 'llms.txt has been edited since Caidance created it, so it will not be deleted — your edits are yours. Delete the file manually if you really want it gone.');
        }

        $filesystem = $this->filesystem();
        if ($filesystem === null || !$filesystem->delete($this->path())) {
            return $this->refuse('delete_failed', 'The file could not be deleted on this host. Remove llms.txt manually from your site root.');
        }

        delete_option(self::MARKER_OPTION);

        $before = $this->latestCheckSnapshot();
        $after  = $this->rescanAndSnapshot();

        (new EvidenceLog())->append([
            'event'   => 'reverted',
            'fix'     => self::CHECK_ID,
            'details' => 'Deleted ' . $this->path() . ' (hash matched the apply record exactly).',
            'before'  => $before,
            'after'   => $after,
        ]);

        return [
            'ok'           => true,
            'code'         => 'reverted',
            'message'      => 'The Caidance-created llms.txt was removed and your score re-checked.',
            'score_before' => is_array($before) ? ($before['total_score'] ?? null) : null,
            'score_after'  => is_array($after) ? ($after['total_score'] ?? null) : null,
        ];
    }

    /**
     * Names the likely virtual provider by scanning active plugin slugs
     * against the known list. Empty string when unknown.
     */
    public function likelyProvider(): string
    {
        $active = get_option('active_plugins', []);
        if (!is_array($active)) {
            return '';
        }
        foreach ($active as $pluginFile) {
            $slug = dirname((string) $pluginFile);
            if (isset(self::KNOWN_PROVIDERS[$slug])) {
                return self::KNOWN_PROVIDERS[$slug];
            }
        }
        return '';
    }

    /**
     * Snapshot of the llms_txt row + total score from the latest stored
     * scan (the "before" side of evidence).
     *
     * @return array<string, mixed>|null
     */
    private function latestCheckSnapshot(): ?array
    {
        $latest = (new ScanHistoryRepository())->getLatest();
        if (!is_array($latest)) {
            return null;
        }
        $row = null;
        foreach (($latest['results'] ?? []) as $result) {
            if (is_array($result) && ($result['checkId'] ?? '') === self::CHECK_ID) {
                $row = $result;
                break;
            }
        }
        return [
            'total_score' => (int) ($latest['total_score'] ?? 0),
            'band'        => (string) ($latest['band'] ?? ''),
            'check'       => $row,
        ];
    }

    /**
     * Re-runs the full scan, persists it, and returns the same snapshot
     * shape as latestCheckSnapshot() (the "after" side of evidence).
     *
     * @return array<string, mixed>
     */
    private function rescanAndSnapshot(): array
    {
        $result = LocalScanner::buildDefault()->run();
        (new ScanHistoryRepository())->saveScan($result);

        $row = null;
        foreach (($result['results'] ?? []) as $checkResult) {
            if (is_array($checkResult) && ($checkResult['checkId'] ?? '') === self::CHECK_ID) {
                $row = $checkResult;
                break;
            }
        }
        return [
            'total_score' => (int) ($result['total_score'] ?? 0),
            'band'        => (string) ($result['band'] ?? ''),
            'check'       => $row,
        ];
    }

    /**
     * Initializes WP_Filesystem (direct method). Returns null when
     * WordPress would need FTP/SSH credentials — we never collect those.
     */
    private function filesystem(): ?\WP_Filesystem_Base
    {
        global $wp_filesystem;

        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if (!WP_Filesystem()) {
            return null;
        }
        return $wp_filesystem instanceof \WP_Filesystem_Base ? $wp_filesystem : null;
    }

    private function currentUserLogin(): string
    {
        $user = wp_get_current_user();
        return ($user instanceof \WP_User && $user->exists()) ? $user->user_login : 'system';
    }

    /**
     * Builds a refusal result and logs it — a refusal is evidence too.
     *
     * @return array{ok: bool, code: string, message: string, score_before: null, score_after: null}
     */
    private function refuse(string $code, string $message): array
    {
        (new EvidenceLog())->append([
            'event'   => 'refused',
            'fix'     => self::CHECK_ID,
            'details' => '[' . $code . '] ' . $message,
        ]);

        return ['ok' => false, 'code' => $code, 'message' => $message, 'score_before' => null, 'score_after' => null];
    }
}
