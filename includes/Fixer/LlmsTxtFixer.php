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

final class LlmsTxtFixer extends AbstractFixer
{
    public const CHECK_ID = 'llms_txt';

    // States reported by status() — drive which panel renders.
    public const STATE_FIXABLE      = 'fixable';
    public const STATE_APPLIED      = 'applied_by_us';
    public const STATE_EDITED       = 'modified_after_apply';
    public const STATE_FOREIGN_FILE = 'file_exists_foreign';
    public const STATE_VIRTUAL      = 'served_virtually';
    public const STATE_NOT_WRITABLE = 'not_writable';
    public const STATE_UNSUPPORTED  = 'unsupported_install';

    public const MARKER_OPTION = 'caidance_air_llms_txt_marker';

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

    public function id(): string
    {
        return self::CHECK_ID;
    }

    public function label(): string
    {
        return 'llms.txt';
    }

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
     * When a marker exists but the file is gone (a deploy or cleanup
     * removed it), the state is fixable with 'stale_marker' set — the
     * drift signal the panel and the weekly scan both surface.
     *
     * @param array<string, mixed>|null $latestCheck
     * @return array{state: string, path: string, owner: string, marker: array<string, mixed>|null, stale_marker: bool}
     */
    public function status(?array $latestCheck): array
    {
        $path   = $this->path();
        $marker = $this->marker();
        $base   = ['path' => $path, 'owner' => '', 'marker' => $marker, 'stale_marker' => false];

        if (file_exists($path)) {
            $hash = (string) sha1_file($path);
            if ($marker !== null && isset($marker['hash']) && $marker['hash'] === $hash) {
                return ['state' => self::STATE_APPLIED] + $base;
            }
            if ($marker !== null) {
                return ['state' => self::STATE_EDITED] + $base;
            }
            return ['state' => self::STATE_FOREIGN_FILE] + $base;
        }

        // No physical file. If the last scan still saw /llms.txt respond,
        // another plugin or the server itself is serving it virtually.
        $scanSawContent = is_array($latestCheck)
            && in_array(($latestCheck['status'] ?? ''), ['pass', 'partial'], true);
        if ($scanSawContent) {
            return ['state' => self::STATE_VIRTUAL, 'owner' => $this->likelyProvider()] + $base;
        }

        if (!$this->isStandardInstall()) {
            return ['state' => self::STATE_UNSUPPORTED] + $base;
        }

        if (!wp_is_writable(ABSPATH)) {
            return ['state' => self::STATE_NOT_WRITABLE] + $base;
        }

        return ['state' => self::STATE_FIXABLE, 'stale_marker' => ($marker !== null)] + $base;
    }

    /**
     * The exact content apply() would write. Deterministic, so preview
     * and write always match.
     */
    public function previewContent(): string
    {
        return (new LlmsTxtContentBuilder())->build();
    }

    /**
     * @param array<string, mixed> $status
     * @param array<string, mixed> $latestCheck
     */
    public function wantsPanel(array $status, array $latestCheck): bool
    {
        $passing = (($latestCheck['status'] ?? '') === 'pass');
        return !($passing && ($status['state'] ?? '') !== self::STATE_APPLIED);
    }

    /**
     * @param array<string, mixed> $status
     * @param array<string, mixed> $latestCheck
     */
    public function renderPanel(array $status, bool $previewing, array $latestCheck): string
    {
        $state = (string) ($status['state'] ?? '');
        $html  = '';

        switch ($state) {
            case self::STATE_APPLIED:
                $appliedAt = is_array($status['marker']) ? (string) ($status['marker']['applied_at'] ?? '') : '';
                $lead      = '<strong>' . esc_html__('Applied by Caidance', 'caidance-ai-readiness') . '</strong>';
                if ($appliedAt !== '') {
                    $lead .= ' ' . esc_html__('on', 'caidance-ai-readiness') . ' <code>' . esc_html($appliedAt) . '</code>';
                }
                $lead .= ' &mdash; ' . esc_html__('the file on disk still matches exactly what was approved.', 'caidance-ai-readiness');
                $html .= $this->paragraph($lead);
                $html .= $this->revertForm(__('Revert this fix', 'caidance-ai-readiness'));
                $html .= $this->descriptionLine(__('Reverting deletes only the exact file Caidance wrote — its content hash is verified first.', 'caidance-ai-readiness'));
                break;

            case self::STATE_EDITED:
                $appliedAt = is_array($status['marker']) ? (string) ($status['marker']['applied_at'] ?? '') : '';
                $html     .= $this->paragraph(sprintf(
                    /* translators: %s is the date the fix was originally applied. */
                    esc_html__('Caidance created this file on %s, but it has been edited since. Caidance will not delete or overwrite your edits, so revert is disabled. Delete the file manually if you want a clean slate.', 'caidance-ai-readiness'),
                    esc_html($appliedAt)
                ));
                break;

            case self::STATE_FOREIGN_FILE:
                $html .= $this->paragraph(sprintf(
                    /* translators: %s is the llms.txt file path. */
                    esc_html__('An llms.txt file already exists at %s that Caidance did not create. It never overwrites or edits a file it does not own. Improve it using the fix hint above — or remove it and re-scan if you would rather Caidance generate one for you.', 'caidance-ai-readiness'),
                    '<code>' . esc_html((string) $status['path']) . '</code>'
                ));
                break;

            case self::STATE_VIRTUAL:
                $owner = (string) ($status['owner'] ?? '');
                $html .= $this->paragraph(
                    $owner !== ''
                        ? sprintf(
                            /* translators: %s is the plugin likely serving llms.txt. */
                            esc_html__('Something on your site already serves /llms.txt (likely %s). Caidance steps aside rather than creating a duplicate — improve the content in the tool that owns it.', 'caidance-ai-readiness'),
                            esc_html($owner)
                        )
                        : esc_html__('Something on your site already serves /llms.txt (another plugin or a server rule). Caidance steps aside rather than creating a duplicate — improve the content in the tool that owns it.', 'caidance-ai-readiness')
                );
                break;

            case self::STATE_NOT_WRITABLE:
            case self::STATE_UNSUPPORTED:
                $html .= $this->paragraph(esc_html(
                    $state === self::STATE_NOT_WRITABLE
                        ? __('Your site root is not writable from WordPress on this host, so Caidance cannot create the file for you. Copy this content into a file named llms.txt at your site root:', 'caidance-ai-readiness')
                        : __('This WordPress install serves the site from a different address than WordPress itself, so Caidance cannot be certain where /llms.txt must live. Create this content as llms.txt at your public site root:', 'caidance-ai-readiness')
                ));
                $html .= $this->preBlock($this->previewContent());
                break;

            case self::STATE_FIXABLE:
            default:
                if (!empty($status['stale_marker'])) {
                    $html .= $this->paragraph('<strong>' . esc_html__('Drift detected:', 'caidance-ai-readiness') . '</strong> ' . esc_html__('Caidance applied this fix earlier, but the file has since disappeared — a deploy, migration, or cleanup may have removed it. You can re-apply it below.', 'caidance-ai-readiness'));
                }
                if ($previewing) {
                    $html .= '<h4 style="margin:0 0 6px;">' . esc_html__('The exact file Caidance will create', 'caidance-ai-readiness') . '</h4>';
                    $html .= '<p style="margin:0 0 8px;">' . esc_html__('Location:', 'caidance-ai-readiness') . ' <code>' . esc_html((string) $status['path']) . '</code></p>';
                    $html .= $this->preBlock($this->previewContent());
                    $html .= '<ul style="list-style:disc;margin:0 0 12px 20px;">'
                        . '<li>' . esc_html__('Nothing is written until you click approve.', 'caidance-ai-readiness') . '</li>'
                        . '<li>' . esc_html__('After writing, Caidance re-checks your site and records before/after evidence.', 'caidance-ai-readiness') . '</li>'
                        . '<li>' . esc_html__('One click reverses it — Caidance deletes only the exact file it wrote.', 'caidance-ai-readiness') . '</li>'
                        . '<li>' . esc_html__('If you uninstall the plugin later, the file stays — it is your content.', 'caidance-ai-readiness') . '</li>'
                        . '</ul>';
                    $html .= $this->approveForm(__('Approve & apply', 'caidance-ai-readiness'));
                    $html .= $this->cancelLink();
                } else {
                    $html .= $this->paragraph(
                        '<strong>' . esc_html__('Caidance can fix this one for you.', 'caidance-ai-readiness') . '</strong> '
                        . esc_html__('It creates a plain-text llms.txt at your site root, built from your real pages — and you see the exact file before anything is written. One click reverses it later.', 'caidance-ai-readiness')
                    );
                    $html .= $this->previewLink(__('Preview the fix', 'caidance-ai-readiness'));
                }
                break;
        }

        return $html;
    }

    /**
     * Applies the fix. Re-validates the world at write time, writes via
     * WP_Filesystem, records the marker, verifies file + URL, re-runs
     * the full scan, and logs evidence.
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

        $this->evidence()->append([
            'event'   => 'applied',
            'fix'     => $this->id(),
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

        return $this->succeed('applied', $message, $before, $after);
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
            $this->evidence()->append([
                'event'   => 'reverted',
                'fix'     => $this->id(),
                'details' => 'The file was already gone (removed outside the plugin). Cleared the apply record.',
            ]);
            return $this->succeed('reverted', 'The file was already removed. Caidance cleared its apply record.', null, null);
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

        $this->evidence()->append([
            'event'   => 'reverted',
            'fix'     => $this->id(),
            'details' => 'Deleted ' . $this->path() . ' (hash matched the apply record exactly).',
            'before'  => $before,
            'after'   => $after,
        ]);

        return $this->succeed('reverted', 'The Caidance-created llms.txt was removed and your score re-checked.', $before, $after);
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
}
