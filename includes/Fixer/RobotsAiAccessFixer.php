<?php
/**
 * Fix for the AI-crawler access check: surgically removes robots.txt
 * groups that block the five major AI crawlers from the site root.
 *
 * This fix introduces modify-with-exact-restore semantics (the general
 * principle behind llms.txt's create-only rule): the complete original
 * file content is stored in the marker before any change, revert
 * restores those exact bytes, and nothing is touched if the file
 * changed since we modified it.
 *
 * Surgical scope — the only thing ever removed is a whole user-agent
 * group whose agents are EXCLUSIVELY the targeted AI crawlers and which
 * blocks from root (Disallow: /). Groups that mix AI crawlers with
 * other agents, blanket * blocks, and path-specific rules are never
 * auto-edited; the panel shows manual guidance instead. Every kept
 * line survives byte-for-byte (RobotsTxtSurgeon).
 *
 * @package Caidance\AiReadiness
 */

declare(strict_types=1);

namespace Caidance\AiReadiness\Fixer;

use Caidance\AiReadiness\Http\SiteFetcher;

final class RobotsAiAccessFixer extends AbstractFixer
{
    public const CHECK_ID = 'ai_crawler_access';

    public const STATE_FIXABLE      = 'fixable';
    public const STATE_APPLIED      = 'applied_by_us';
    public const STATE_EDITED       = 'modified_after_apply';
    public const STATE_VIRTUAL      = 'served_virtually';
    public const STATE_MIXED_MANUAL = 'mixed_manual';
    public const STATE_NOT_WRITABLE = 'not_writable';
    public const STATE_UNSUPPORTED  = 'unsupported_install';
    public const STATE_TOO_LARGE    = 'file_too_large';
    public const STATE_NO_TARGET    = 'analysis_empty';

    public const MARKER_OPTION = 'caidance_air_robots_fix_marker';

    private const MAX_BYTES = 65536;

    /**
     * Mirrors AiCrawlerCheck::CRAWLERS — the five user-agents the check
     * scores. Keep the two lists in sync.
     */
    private const CRAWLERS = [
        'GPTBot',
        'ClaudeBot',
        'PerplexityBot',
        'OAI-SearchBot',
        'Google-Extended',
    ];

    /**
     * Plugins known to filter WordPress's virtual robots.txt output
     * (crawler-blocking features). Used only to name the likely owner.
     */
    private const KNOWN_BLOCKERS = [
        'wordpress-seo'       => 'Yoast SEO',
        'seo-by-rank-math'    => 'Rank Math SEO',
        'all-in-one-seo-pack' => 'All in One SEO',
    ];

    public function id(): string
    {
        return self::CHECK_ID;
    }

    public function label(): string
    {
        return 'AI crawler access (robots.txt)';
    }

    public function path(): string
    {
        return trailingslashit(ABSPATH) . 'robots.txt';
    }

    public function isStandardInstall(): bool
    {
        return untrailingslashit((string) home_url('/')) === untrailingslashit((string) site_url('/'));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function marker(): ?array
    {
        $marker = get_option(self::MARKER_OPTION, null);
        return is_array($marker) ? $marker : null;
    }

    /**
     * Side-effect-free state read. Reads the physical file directly
     * (cheap, local, definitive); no HTTP happens here.
     *
     * @param array<string, mixed>|null $latestCheck
     * @return array{state: string, path: string, owner: string, marker: array<string, mixed>|null, removable: array<int, string>, blocking_lines: array<int, string>}
     */
    public function status(?array $latestCheck): array
    {
        $path   = $this->path();
        $marker = $this->marker();
        $base   = ['path' => $path, 'owner' => '', 'marker' => $marker, 'removable' => [], 'blocking_lines' => []];

        clearstatcache();
        $fileExists = file_exists($path);

        if ($marker !== null) {
            if ($fileExists && (string) sha1_file($path) === (string) ($marker['modified_hash'] ?? '')) {
                return ['state' => self::STATE_APPLIED] + $base;
            }
            if ($fileExists) {
                return ['state' => self::STATE_EDITED] + $base;
            }
            // File gone entirely — robots absent means everything is
            // allowed; the marker is stale. Report as applied-equivalent
            // nothing-to-do; revert clears the record cleanly.
            return ['state' => self::STATE_APPLIED] + $base;
        }

        if (!$fileExists) {
            // Check failed with no physical file = WordPress's virtual
            // robots output is being filtered by something else.
            return ['state' => self::STATE_VIRTUAL, 'owner' => $this->likelyBlocker()] + $base;
        }

        if (!$this->isStandardInstall()) {
            return ['state' => self::STATE_UNSUPPORTED] + $base;
        }

        $size = filesize($path);
        if (!is_int($size) || $size > self::MAX_BYTES) {
            return ['state' => self::STATE_TOO_LARGE] + $base;
        }

        $content = (string) file_get_contents($path);

        $removable = RobotsTxtSurgeon::removableGroups($content, self::CRAWLERS);
        if ($removable !== []) {
            if (!wp_is_writable(ABSPATH) || !wp_is_writable($path)) {
                return ['state' => self::STATE_NOT_WRITABLE, 'blocking_lines' => $this->groupLines($content, $removable)] + $base;
            }
            return ['state' => self::STATE_FIXABLE, 'removable' => $this->groupLines($content, $removable)] + $base;
        }

        $mixed = RobotsTxtSurgeon::mixedBlockingGroups($content, self::CRAWLERS);
        if ($mixed !== []) {
            return ['state' => self::STATE_MIXED_MANUAL, 'blocking_lines' => $this->groupLines($content, $mixed)] + $base;
        }

        return ['state' => self::STATE_NO_TARGET] + $base;
    }

    /**
     * The resulting robots.txt content apply() would produce, plus the
     * exact lines being removed. Recomputed live so preview === write.
     *
     * @return array{content: string, removed: array<int, string>}
     */
    public function previewData(): array
    {
        clearstatcache();
        if (!file_exists($this->path())) {
            return ['content' => '', 'removed' => []];
        }
        $content = (string) file_get_contents($this->path());
        $groups  = RobotsTxtSurgeon::removableGroups($content, self::CRAWLERS);
        return RobotsTxtSurgeon::removeGroups($content, $groups);
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
                $lead .= ' &mdash; ' . esc_html__('the AI-crawler blocks were removed from robots.txt; every other line was kept byte-for-byte.', 'caidance-ai-readiness');
                $html .= $this->paragraph($lead);
                $html .= $this->revertForm(__('Restore the original robots.txt', 'caidance-ai-readiness'));
                $html .= $this->descriptionLine(__('Restore puts back the exact original file — Caidance stored its full content before changing anything. It refuses if robots.txt was edited since.', 'caidance-ai-readiness'));
                break;

            case self::STATE_EDITED:
                $html .= $this->paragraph(esc_html__('robots.txt has been edited since Caidance modified it, so the one-click restore is disabled — your edits are yours. The stored original is kept in the fix record if you ever need it.', 'caidance-ai-readiness'));
                break;

            case self::STATE_VIRTUAL:
                $owner = (string) ($status['owner'] ?? '');
                $html .= $this->paragraph(
                    $owner !== ''
                        ? sprintf(
                            /* translators: %s is the plugin likely filtering robots.txt. */
                            esc_html__('Your site has no physical robots.txt — WordPress generates it, and something (likely %s) is adding the AI-crawler blocks to that output. Caidance does not fight another plugin for the same output: look for a crawler or robots setting in that plugin and allow GPTBot, ClaudeBot, PerplexityBot, OAI-SearchBot, and Google-Extended there.', 'caidance-ai-readiness'),
                            esc_html($owner)
                        )
                        : esc_html__('Your site has no physical robots.txt — WordPress generates it, and another plugin or your theme is adding the AI-crawler blocks to that output. Caidance does not fight another plugin for the same output: find the plugin with a crawler/robots setting and allow the AI crawlers there.', 'caidance-ai-readiness')
                );
                break;

            case self::STATE_MIXED_MANUAL:
                $html .= $this->paragraph(esc_html__('The blocks live in robots.txt groups that also cover OTHER crawlers, so removing them would change behavior for agents you may want blocked. Caidance never auto-edits those. These are the lines involved — remove the AI user-agent lines from them manually if the blocks are unintentional:', 'caidance-ai-readiness'));
                $html .= $this->preBlock(implode("\n", array_map('strval', (array) $status['blocking_lines'])));
                break;

            case self::STATE_NOT_WRITABLE:
                $html .= $this->paragraph(esc_html__('Caidance found removable AI-crawler blocks, but robots.txt is not writable from WordPress on this host. Remove these lines manually:', 'caidance-ai-readiness'));
                $html .= $this->preBlock(implode("\n", array_map('strval', (array) $status['blocking_lines'])));
                break;

            case self::STATE_UNSUPPORTED:
            case self::STATE_TOO_LARGE:
            case self::STATE_NO_TARGET:
                $html .= $this->paragraph(esc_html__('Caidance could not safely identify a removable block in this robots.txt (unusual install layout, a very large file, or blocks in a form it will not auto-edit). Use the fix hint above to adjust the file manually.', 'caidance-ai-readiness'));
                break;

            case self::STATE_FIXABLE:
            default:
                if ($previewing) {
                    $preview = $this->previewData();
                    $html   .= '<h4 style="margin:0 0 6px;">' . esc_html__('Lines Caidance will remove from robots.txt', 'caidance-ai-readiness') . '</h4>';
                    $html   .= $this->preBlock(implode("\n", $preview['removed']));
                    $html   .= '<p style="margin:0 0 8px;">' . esc_html__('The resulting file — every kept line is byte-for-byte identical:', 'caidance-ai-readiness') . '</p>';
                    $html   .= $this->preBlock($preview['content']);
                    $html   .= '<ul style="list-style:disc;margin:0 0 12px 20px;">'
                        . '<li>' . esc_html__('Nothing changes until you click approve.', 'caidance-ai-readiness') . '</li>'
                        . '<li>' . esc_html__('The complete original file is stored first — restore puts back the exact original bytes.', 'caidance-ai-readiness') . '</li>'
                        . '<li>' . esc_html__('After the change, Caidance re-checks your site and records before/after evidence.', 'caidance-ai-readiness') . '</li>'
                        . '</ul>';
                    $html   .= $this->approveForm(__('Approve & apply', 'caidance-ai-readiness'));
                    $html   .= $this->cancelLink();
                } else {
                    $html .= $this->paragraph(
                        '<strong>' . esc_html__('Caidance can fix this one for you.', 'caidance-ai-readiness') . '</strong> '
                        . esc_html__('Your robots.txt has groups that block AI crawlers and nothing else. Caidance can remove exactly those lines — you see the precise change first, the original file is stored, and one click restores it.', 'caidance-ai-readiness')
                    );
                    $html .= $this->previewLink(__('Preview the fix', 'caidance-ai-readiness'));
                }
                break;
        }

        return $html;
    }

    /**
     * @return array{ok: bool, code: string, message: string, score_before: int|null, score_after: int|null}
     */
    public function apply(): array
    {
        if (!current_user_can('manage_options')) {
            return $this->refuse('not_allowed', 'You do not have permission to apply fixes on this site.');
        }
        if (!$this->isStandardInstall()) {
            return $this->refuse('unsupported_install', 'This WordPress install serves the site from a different address than WordPress itself, so the plugin cannot safely edit robots.txt. Adjust the file manually.');
        }
        if ($this->marker() !== null) {
            return $this->refuse('already_applied', 'Caidance already modified robots.txt once. Restore the original first if you want to run the fix again.');
        }

        clearstatcache();
        if (!file_exists($this->path())) {
            return $this->refuse('no_physical_file', 'There is no physical robots.txt to edit — the blocks are coming from a plugin filtering the WordPress-generated output. Adjust that plugin instead.');
        }
        $size = filesize($this->path());
        if (!is_int($size) || $size > self::MAX_BYTES) {
            return $this->refuse('file_too_large', 'robots.txt is unusually large; Caidance will not auto-edit it. Adjust the file manually.');
        }

        $original = (string) file_get_contents($this->path());
        $groups   = RobotsTxtSurgeon::removableGroups($original, self::CRAWLERS);
        if ($groups === []) {
            return $this->refuse('nothing_removable', 'No safely removable AI-crawler block was found (the file may have changed since the scan). Re-run a scan to refresh the results.');
        }

        $result   = RobotsTxtSurgeon::removeGroups($original, $groups);
        $modified = $result['content'];

        $before     = $this->latestCheckSnapshot();
        $filesystem = $this->filesystem();
        if ($filesystem === null) {
            return $this->refuse('filesystem_unavailable', 'WordPress could not get direct filesystem access on this host. Remove the previewed lines from robots.txt manually.');
        }
        if (!$filesystem->put_contents($this->path(), $modified, FS_CHMOD_FILE)) {
            return $this->refuse('write_failed', 'robots.txt could not be written on this host. Remove the previewed lines manually.');
        }

        update_option(self::MARKER_OPTION, [
            'original_content' => $original,
            'original_hash'    => sha1($original),
            'modified_hash'    => sha1($modified),
            'removed_lines'    => $result['removed'],
            'applied_at'       => current_time('mysql'),
            'applied_by'       => $this->currentUserLogin(),
        ], false);

        // Verify: disk hash, then the served file (cache-busted).
        clearstatcache();
        $fileVerified = file_exists($this->path()) && sha1_file($this->path()) === sha1($modified);
        $fetcher      = new SiteFetcher();
        $servedCheck  = $fetcher->get($fetcher->urlFor('/robots.txt') . '?caidance-air-verify=' . time());
        $urlVerified  = $servedCheck['ok'] && trim($servedCheck['body']) === trim($modified);

        $after = $this->rescanAndSnapshot();

        $this->evidence()->append([
            'event'   => 'applied',
            'fix'     => $this->id(),
            'details' => sprintf(
                'Removed %d line(s) from %s (%s). Original stored for restore. Disk verified: %s. Served: %s.',
                count($result['removed']),
                $this->path(),
                implode(' | ', array_slice($result['removed'], 0, 6)),
                $fileVerified ? 'yes' : 'NO',
                $urlVerified ? 'yes' : 'not yet (a cache layer may need a few minutes)'
            ),
            'before'  => $before,
            'after'   => $after,
        ]);

        $message = 'AI-crawler blocks removed from robots.txt; the original file is stored for one-click restore.';
        if (!$urlVerified && $fileVerified) {
            $message .= ' The served copy has not refreshed yet — a cache layer may need a few minutes.';
        }

        return $this->succeed('applied', $message, $before, $after);
    }

    /**
     * @return array{ok: bool, code: string, message: string, score_before: int|null, score_after: int|null}
     */
    public function revert(): array
    {
        if (!current_user_can('manage_options')) {
            return $this->refuse('not_allowed', 'You do not have permission to revert fixes on this site.');
        }

        $marker = $this->marker();
        if ($marker === null) {
            return $this->refuse('nothing_to_revert', 'Caidance has no record of modifying robots.txt, so there is nothing to restore.');
        }

        clearstatcache();
        if (!file_exists($this->path())) {
            delete_option(self::MARKER_OPTION);
            $this->evidence()->append([
                'event'   => 'reverted',
                'fix'     => $this->id(),
                'details' => 'robots.txt was already gone (removed outside the plugin). Cleared the fix record.',
            ]);
            return $this->succeed('reverted', 'robots.txt was already removed. Caidance cleared its fix record.', null, null);
        }

        if ((string) sha1_file($this->path()) !== (string) ($marker['modified_hash'] ?? '')) {
            return $this->refuse('edited_since_apply', 'robots.txt has been edited since Caidance modified it, so it will not be overwritten — your edits are yours. The stored original remains in the fix record.');
        }

        $original   = (string) ($marker['original_content'] ?? '');
        $filesystem = $this->filesystem();
        if ($filesystem === null || !$filesystem->put_contents($this->path(), $original, FS_CHMOD_FILE)) {
            return $this->refuse('write_failed', 'The original robots.txt could not be restored on this host. Restore it manually from the fix record.');
        }

        delete_option(self::MARKER_OPTION);

        $before = $this->latestCheckSnapshot();
        $after  = $this->rescanAndSnapshot();

        $this->evidence()->append([
            'event'   => 'reverted',
            'fix'     => $this->id(),
            'details' => 'Restored the original robots.txt byte-for-byte (' . strlen($original) . ' bytes).',
            'before'  => $before,
            'after'   => $after,
        ]);

        return $this->succeed('reverted', 'The original robots.txt was restored exactly as it was.', $before, $after);
    }

    private function likelyBlocker(): string
    {
        $active = get_option('active_plugins', []);
        if (!is_array($active)) {
            return '';
        }
        foreach ($active as $pluginFile) {
            $slug = dirname((string) $pluginFile);
            if (isset(self::KNOWN_BLOCKERS[$slug])) {
                return self::KNOWN_BLOCKERS[$slug];
            }
        }
        return '';
    }

    /**
     * Raw numbered lines for a set of groups (panel display).
     *
     * @param array<int, array{start: int, end: int, uas: array<int, string>, lines: array<int, int>, root_disallow: bool}> $groups
     * @return array<int, string>
     */
    private function groupLines(string $content, array $groups): array
    {
        $lines = explode("\n", $content);
        $out   = [];
        foreach ($groups as $group) {
            for ($i = $group['start']; $i <= $group['end']; $i++) {
                if (isset($lines[$i])) {
                    $out[] = 'line ' . ($i + 1) . ': ' . rtrim($lines[$i], "\r");
                }
            }
        }
        return $out;
    }
}
