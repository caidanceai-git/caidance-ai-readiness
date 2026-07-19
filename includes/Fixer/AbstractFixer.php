<?php
/**
 * Shared machinery for all fixes: filesystem access, evidence + refusal
 * plumbing, before/after scan snapshots, and the panel building blocks
 * (approve/revert forms, preview links, pre blocks) so every fixer's
 * panel looks and behaves identically.
 *
 * @package Caidance\AiReadiness
 */

declare(strict_types=1);

namespace Caidance\AiReadiness\Fixer;

use Caidance\AiReadiness\Admin\FixActions;
use Caidance\AiReadiness\Admin\ToolsPage;
use Caidance\AiReadiness\Scanner\LocalScanner;
use Caidance\AiReadiness\Storage\EvidenceLog;
use Caidance\AiReadiness\Storage\ScanHistoryRepository;

abstract class AbstractFixer implements FixInterface
{
    /**
     * Snapshot of this fix's check row + total score from the latest
     * stored scan (the "before" side of evidence).
     *
     * @return array<string, mixed>|null
     */
    protected function latestCheckSnapshot(): ?array
    {
        $latest = (new ScanHistoryRepository())->getLatest();
        if (!is_array($latest)) {
            return null;
        }
        return $this->snapshotFromScan($latest);
    }

    /**
     * Re-runs the full scan, persists it, and returns the same snapshot
     * shape (the "after" side of evidence).
     *
     * @return array<string, mixed>
     */
    protected function rescanAndSnapshot(): array
    {
        $result = LocalScanner::buildDefault()->run();
        (new ScanHistoryRepository())->saveScan($result);
        return $this->snapshotFromScan($result);
    }

    /**
     * @param array<string, mixed> $scan
     * @return array<string, mixed>
     */
    private function snapshotFromScan(array $scan): array
    {
        $row = null;
        foreach (($scan['results'] ?? []) as $checkResult) {
            if (is_array($checkResult) && ($checkResult['checkId'] ?? '') === $this->id()) {
                $row = $checkResult;
                break;
            }
        }
        return [
            'total_score'     => (int) ($scan['total_score'] ?? 0),
            'band'            => (string) ($scan['band'] ?? ''),
            // Scans stored before blockage detection lack the key —
            // they were always full-score scans, so default true.
            'score_available' => !isset($scan['score_available']) || (bool) $scan['score_available'],
            'check'           => $row,
        ];
    }

    /**
     * Builds a refusal result and logs it — a refusal is evidence too.
     *
     * @return array{ok: bool, code: string, message: string, score_before: null, score_after: null}
     */
    protected function refuse(string $code, string $message): array
    {
        (new EvidenceLog())->append([
            'event'   => 'refused',
            'fix'     => $this->id(),
            'details' => '[' . $code . '] ' . $message,
        ]);

        return ['ok' => false, 'code' => $code, 'message' => $message, 'score_before' => null, 'score_after' => null];
    }

    /**
     * Success-result builder shared by apply()/revert().
     *
     * A snapshot from a scanner-blocked scan (score_available false)
     * yields a null score rather than a collapsed number — otherwise a
     * firewall challenge during the post-apply re-scan would show a
     * false "Your score: 48 → 6" in the fix notice.
     *
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @return array{ok: bool, code: string, message: string, score_before: int|null, score_after: int|null}
     */
    protected function succeed(string $code, string $message, ?array $before, ?array $after): array
    {
        return [
            'ok'           => true,
            'code'         => $code,
            'message'      => $message,
            'score_before' => $this->usableScore($before),
            'score_after'  => $this->usableScore($after),
        ];
    }

    /**
     * @param array<string, mixed>|null $snapshot
     */
    private function usableScore(?array $snapshot): ?int
    {
        if (!is_array($snapshot) || !isset($snapshot['total_score'])) {
            return null;
        }
        if (isset($snapshot['score_available']) && $snapshot['score_available'] === false) {
            return null;
        }
        return (int) $snapshot['total_score'];
    }

    /**
     * Initializes WP_Filesystem (direct method). Returns null when
     * WordPress would need FTP/SSH credentials — we never collect those.
     */
    protected function filesystem(): ?\WP_Filesystem_Base
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

    protected function currentUserLogin(): string
    {
        $user = wp_get_current_user();
        return ($user instanceof \WP_User && $user->exists()) ? $user->user_login : 'system';
    }

    protected function evidence(): EvidenceLog
    {
        return new EvidenceLog();
    }

    // ------------------------------------------------------------------
    // Panel building blocks — identical chrome across every fix.
    // ------------------------------------------------------------------

    protected function toolsUrl(): string
    {
        return add_query_arg(['page' => ToolsPage::MENU_SLUG], admin_url('tools.php'));
    }

    protected function previewLink(string $label): string
    {
        $url = add_query_arg(
            ['page' => ToolsPage::MENU_SLUG, 'caidance-air-preview' => $this->id()],
            admin_url('tools.php')
        ) . '#caidance-air-fix-' . $this->id();

        return '<a class="button button-primary" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    }

    protected function cancelLink(): string
    {
        return '<a class="button" href="' . esc_url($this->toolsUrl()) . '">' . esc_html__('Cancel', 'caidance-ai-readiness') . '</a>';
    }

    protected function approveForm(string $buttonLabel, bool $primary = true): string
    {
        return $this->actionForm(FixActions::APPLY_ACTION, $buttonLabel, $primary);
    }

    protected function revertForm(string $buttonLabel): string
    {
        return $this->actionForm(FixActions::REVERT_ACTION, $buttonLabel, false);
    }

    private function actionForm(string $action, string $buttonLabel, bool $primary): string
    {
        $html  = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:8px;">';
        $html .= '<input type="hidden" name="action" value="' . esc_attr($action) . '" />';
        $html .= '<input type="hidden" name="fix" value="' . esc_attr($this->id()) . '" />';
        $html .= wp_nonce_field($action . '_' . $this->id(), '_wpnonce', true, false);
        $html .= '<button type="submit" class="button' . ($primary ? ' button-primary' : '') . '">' . esc_html($buttonLabel) . '</button>';
        $html .= '</form>';

        return $html;
    }

    protected function preBlock(string $content): string
    {
        return '<pre style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:12px;max-height:340px;overflow:auto;white-space:pre-wrap;font-size:12px;margin:0 0 10px;">'
            . esc_html($content)
            . '</pre>';
    }

    protected function paragraph(string $html): string
    {
        return '<p style="margin:0 0 10px;">' . $html . '</p>';
    }

    protected function descriptionLine(string $text): string
    {
        return '<p class="description" style="margin:8px 0 0;">' . esc_html($text) . '</p>';
    }
}
