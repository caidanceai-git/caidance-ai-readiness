<?php
/**
 * Tools → Caidance Scan menu page.
 *
 * Full readout of the most recent scan — score, band, all 10 check results
 * with industry-aware fix recommendations. v1 scaffold renders the page
 * shell + empty-state branches; full result rendering ships with the
 * Scanner subsystem in the next batch.
 *
 * @package Caidance\AiReadiness
 */

declare(strict_types=1);

namespace Caidance\AiReadiness\Admin;

use Caidance\AiReadiness\Fixer\LlmsTxtFixer;
use Caidance\AiReadiness\Rendering\ResultRenderer;
use Caidance\AiReadiness\Storage\EvidenceLog;
use Caidance\AiReadiness\Storage\ScanHistoryRepository;

final class ToolsPage
{
    public const MENU_SLUG = 'caidance-ai-readiness-tools';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
    }

    public function registerMenu(): void
    {
        add_management_page(
            __('Caidance Scan', 'caidance-ai-readiness'),
            __('Caidance Scan', 'caidance-ai-readiness'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'renderPage']
        );
    }

    public function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $industry    = (string) get_option('caidance_air_industry', '');
        $settingsUrl = admin_url('options-general.php?page=' . SettingsPage::MENU_SLUG);
        $repo        = new ScanHistoryRepository();
        $latest      = $repo->getLatest();
        $history     = $repo->getHistory();

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Caidance — AI-Readiness Scan', 'caidance-ai-readiness'); ?></h1>

            <?php $this->renderFixNotice(); ?>

            <?php if ($industry === ''): ?>
                <div class="notice notice-warning inline">
                    <p>
                        <?php
                        printf(
                            /* translators: %s is an HTML link to the Settings → Caidance page. */
                            esc_html__('Pick your industry first on %s.', 'caidance-ai-readiness'),
                            '<a href="' . esc_url($settingsUrl) . '">' . esc_html__('Settings → Caidance', 'caidance-ai-readiness') . '</a>'
                        );
                        ?>
                    </p>
                </div>
            <?php elseif (!is_array($latest)): ?>
                <p><?php esc_html_e('No scan has run yet.', 'caidance-ai-readiness'); ?></p>
                <p>
                    <a class="button button-primary" href="<?php echo esc_url($settingsUrl); ?>">
                        <?php esc_html_e('Go to Settings → Run scan now', 'caidance-ai-readiness'); ?>
                    </a>
                </p>
            <?php else:
                $score   = (int) ($latest['total_score'] ?? 0);
                $max     = (int) ($latest['max_possible'] ?? 0);
                $band    = (string) ($latest['band'] ?? 'starter');
                $ranAt   = (string) ($latest['ran_at'] ?? '');
                $results = is_array($latest['results'] ?? null) ? $latest['results'] : [];
                ?>
                <p style="margin-top:16px;">
                    <?php echo ResultRenderer::renderScoreBadge($score, $max, $band); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </p>
                <p style="color:#646970;">
                    <?php
                    printf(
                        /* translators: %s is the last-scan timestamp wrapped in a code tag. */
                        esc_html__('Last scan: %s', 'caidance-ai-readiness'),
                        '<code>' . esc_html($ranAt) . '</code>'
                    );
                    ?>
                    &nbsp;&middot;&nbsp;
                    <a href="<?php echo esc_url($settingsUrl); ?>"><?php esc_html_e('Re-run scan on Settings', 'caidance-ai-readiness'); ?></a>
                </p>

                <h2 style="margin-top:24px;"><?php esc_html_e('All checks', 'caidance-ai-readiness'); ?></h2>
                <?php
                foreach ($results as $result) {
                    if (!is_array($result)) {
                        continue;
                    }
                    echo ResultRenderer::renderResultRow($result); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    if (($result['checkId'] ?? '') === LlmsTxtFixer::CHECK_ID) {
                        $this->renderLlmsTxtFixPanel($result);
                    }
                }

                // Optional bridge cards: calculator (Quantify) + Pilot connect. Full results page only — not the widget.
                echo ResultRenderer::renderCalculatorBridge($band); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                echo ResultRenderer::renderPilotConnect($band); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

                echo ResultRenderer::renderScoreHistory($history, 4); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

                $this->renderEvidenceLog();
            endif;
            ?>

            <hr />

            <p>
                <?php
                printf(
                    /* translators: %s is an HTML link to the Caidance snapshot URL. */
                    esc_html__('For the full off-site picture, see the free 60-second snapshot at %s.', 'caidance-ai-readiness'),
                    '<a href="' . esc_url('https://caidance.ai/snapshot/?utm_source=wp_plugin&utm_medium=tools_footer&utm_campaign=wp_org_v1') . '" target="_blank" rel="noopener noreferrer">caidance.ai/snapshot</a>'
                );
                ?>
            </p>
        </div>
        <?php
    }

    /**
     * Renders the one-shot result notice from the last apply/revert
     * (stored in a short per-user transient by FixActions).
     */
    private function renderFixNotice(): void
    {
        $key    = FixActions::noticeKey();
        $notice = get_transient($key);
        if (!is_array($notice)) {
            return;
        }
        delete_transient($key);

        $ok   = !empty($notice['ok']);
        $from = $notice['score_before'] ?? null;
        $to   = $notice['score_after'] ?? null;
        ?>
        <div class="notice <?php echo $ok ? 'notice-success' : 'notice-warning'; ?> is-dismissible">
            <p>
                <strong><?php echo $ok ? esc_html__('Fix result:', 'caidance-ai-readiness') : esc_html__('Nothing was changed:', 'caidance-ai-readiness'); ?></strong>
                <?php echo esc_html((string) ($notice['message'] ?? '')); ?>
                <?php if (is_int($from) && is_int($to) && $to !== $from): ?>
                    <strong>
                        <?php
                        printf(
                            /* translators: 1: score before the fix, 2: score after the fix. */
                            esc_html__('Your score: %1$d → %2$d.', 'caidance-ai-readiness'),
                            $from,
                            $to
                        );
                        ?>
                    </strong>
                <?php endif; ?>
            </p>
        </div>
        <?php
    }

    /**
     * Renders the First Fix panel directly under the llms.txt check row.
     * Which panel appears is driven by the fixer's state machine; a
     * passing check that Caidance does not manage gets no panel at all.
     *
     * @param array<string, mixed> $llmsResult The stored llms_txt check row.
     */
    private function renderLlmsTxtFixPanel(array $llmsResult): void
    {
        $fixer   = new LlmsTxtFixer();
        $status  = $fixer->status($llmsResult);
        $state   = (string) $status['state'];
        $passing = (($llmsResult['status'] ?? '') === 'pass');

        if ($passing && $state !== LlmsTxtFixer::STATE_APPLIED) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view flag for the preview expansion; no data processing.
        $previewing = isset($_GET['caidance-air-preview']) && $_GET['caidance-air-preview'] === '1';

        echo '<div id="caidance-air-llms-fix" style="border:1px solid #c3c4c7;border-left:4px solid #2271b1;border-radius:4px;padding:14px 16px;margin:0 0 14px;background:#f0f6fc;max-width:860px;">';

        switch ($state) {
            case LlmsTxtFixer::STATE_APPLIED:
                $appliedAt = is_array($status['marker']) ? (string) ($status['marker']['applied_at'] ?? '') : '';
                ?>
                <p style="margin:0 0 10px;">
                    <strong><?php esc_html_e('Applied by Caidance', 'caidance-ai-readiness'); ?></strong>
                    <?php if ($appliedAt !== ''): ?>
                        <?php esc_html_e('on', 'caidance-ai-readiness'); ?> <code><?php echo esc_html($appliedAt); ?></code>
                    <?php endif; ?>
                    &mdash; <?php esc_html_e('the file on disk still matches exactly what was approved.', 'caidance-ai-readiness'); ?>
                </p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
                    <input type="hidden" name="action" value="<?php echo esc_attr(FixActions::REVERT_ACTION); ?>" />
                    <?php wp_nonce_field(FixActions::REVERT_ACTION); ?>
                    <button type="submit" class="button"><?php esc_html_e('Revert this fix', 'caidance-ai-readiness'); ?></button>
                </form>
                <p class="description" style="margin:8px 0 0;"><?php esc_html_e('Reverting deletes only the exact file Caidance wrote — its content hash is verified first.', 'caidance-ai-readiness'); ?></p>
                <?php
                break;

            case LlmsTxtFixer::STATE_EDITED:
                $appliedAt = is_array($status['marker']) ? (string) ($status['marker']['applied_at'] ?? '') : '';
                ?>
                <p style="margin:0;">
                    <?php
                    printf(
                        /* translators: %s is the date the fix was originally applied. */
                        esc_html__('Caidance created this file on %s, but it has been edited since. Caidance will not delete or overwrite your edits, so revert is disabled. Delete the file manually if you want a clean slate.', 'caidance-ai-readiness'),
                        esc_html($appliedAt)
                    );
                    ?>
                </p>
                <?php
                break;

            case LlmsTxtFixer::STATE_FOREIGN_FILE:
                ?>
                <p style="margin:0;">
                    <?php
                    printf(
                        /* translators: %s is the llms.txt file path. */
                        esc_html__('An llms.txt file already exists at %s that Caidance did not create. It never overwrites or edits a file it does not own. Improve it using the fix hint above — or remove it and re-scan if you would rather Caidance generate one for you.', 'caidance-ai-readiness'),
                        '<code>' . esc_html((string) $status['path']) . '</code>'
                    );
                    ?>
                </p>
                <?php
                break;

            case LlmsTxtFixer::STATE_VIRTUAL:
                $owner = (string) $status['owner'];
                ?>
                <p style="margin:0;">
                    <?php
                    if ($owner !== '') {
                        printf(
                            /* translators: %s is the plugin likely serving llms.txt. */
                            esc_html__('Something on your site already serves /llms.txt (likely %s). Caidance steps aside rather than creating a duplicate — improve the content in the tool that owns it.', 'caidance-ai-readiness'),
                            esc_html($owner)
                        );
                    } else {
                        esc_html_e('Something on your site already serves /llms.txt (another plugin or a server rule). Caidance steps aside rather than creating a duplicate — improve the content in the tool that owns it.', 'caidance-ai-readiness');
                    }
                    ?>
                </p>
                <?php
                break;

            case LlmsTxtFixer::STATE_NOT_WRITABLE:
            case LlmsTxtFixer::STATE_UNSUPPORTED:
                ?>
                <p style="margin:0 0 10px;">
                    <?php
                    if ($state === LlmsTxtFixer::STATE_NOT_WRITABLE) {
                        esc_html_e('Your site root is not writable from WordPress on this host, so Caidance cannot create the file for you. Copy this content into a file named llms.txt at your site root:', 'caidance-ai-readiness');
                    } else {
                        esc_html_e('This WordPress install serves the site from a different address than WordPress itself, so Caidance cannot be certain where /llms.txt must live. Create this content as llms.txt at your public site root:', 'caidance-ai-readiness');
                    }
                    ?>
                </p>
                <pre style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:12px;max-height:340px;overflow:auto;white-space:pre-wrap;font-size:12px;margin:0;"><?php echo esc_html($fixer->previewContent()); ?></pre>
                <?php
                break;

            case LlmsTxtFixer::STATE_FIXABLE:
            default:
                if ($previewing) {
                    ?>
                    <h4 style="margin:0 0 6px;"><?php esc_html_e('The exact file Caidance will create', 'caidance-ai-readiness'); ?></h4>
                    <p style="margin:0 0 8px;"><?php esc_html_e('Location:', 'caidance-ai-readiness'); ?> <code><?php echo esc_html((string) $status['path']); ?></code></p>
                    <pre style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:12px;max-height:340px;overflow:auto;white-space:pre-wrap;font-size:12px;margin:0 0 10px;"><?php echo esc_html($fixer->previewContent()); ?></pre>
                    <ul style="list-style:disc;margin:0 0 12px 20px;">
                        <li><?php esc_html_e('Nothing is written until you click approve.', 'caidance-ai-readiness'); ?></li>
                        <li><?php esc_html_e('After writing, Caidance re-checks your site and records before/after evidence.', 'caidance-ai-readiness'); ?></li>
                        <li><?php esc_html_e('One click reverses it — Caidance deletes only the exact file it wrote.', 'caidance-ai-readiness'); ?></li>
                        <li><?php esc_html_e('If you uninstall the plugin later, the file stays — it is your content.', 'caidance-ai-readiness'); ?></li>
                    </ul>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:8px;">
                        <input type="hidden" name="action" value="<?php echo esc_attr(FixActions::APPLY_ACTION); ?>" />
                        <?php wp_nonce_field(FixActions::APPLY_ACTION); ?>
                        <button type="submit" class="button button-primary"><?php esc_html_e('Approve & apply', 'caidance-ai-readiness'); ?></button>
                    </form>
                    <a class="button" href="<?php echo esc_url(add_query_arg(['page' => self::MENU_SLUG], admin_url('tools.php'))); ?>"><?php esc_html_e('Cancel', 'caidance-ai-readiness'); ?></a>
                    <?php
                } else {
                    $previewUrl = add_query_arg(['page' => self::MENU_SLUG, 'caidance-air-preview' => '1'], admin_url('tools.php')) . '#caidance-air-llms-fix';
                    ?>
                    <p style="margin:0 0 10px;">
                        <strong><?php esc_html_e('Caidance can fix this one for you.', 'caidance-ai-readiness'); ?></strong>
                        <?php esc_html_e('It creates a plain-text llms.txt at your site root, built from your real pages — and you see the exact file before anything is written. One click reverses it later.', 'caidance-ai-readiness'); ?>
                    </p>
                    <a class="button button-primary" href="<?php echo esc_url($previewUrl); ?>"><?php esc_html_e('Preview the fix', 'caidance-ai-readiness'); ?></a>
                    <?php
                }
                break;
        }

        echo '</div>';
    }

    /**
     * Renders the append-only fix evidence log (latest 10 entries).
     */
    private function renderEvidenceLog(): void
    {
        $entries = (new EvidenceLog())->all();
        if ($entries === []) {
            return;
        }
        $visible = array_slice($entries, 0, 10);
        ?>
        <h3 style="margin-top:24px;"><?php esc_html_e('Fix evidence log', 'caidance-ai-readiness'); ?></h3>
        <ul style="margin:0;padding:0;list-style:none;max-width:860px;">
            <?php foreach ($visible as $entry): ?>
                <li style="padding:6px 0;border-bottom:1px solid #f0f0f1;color:#1d2327;font-size:13px;">
                    <code><?php echo esc_html((string) ($entry['at'] ?? '')); ?></code>
                    <strong style="text-transform:uppercase;font-size:11px;letter-spacing:0.04em;margin:0 6px;"><?php echo esc_html((string) ($entry['event'] ?? '')); ?></strong>
                    <?php echo esc_html((string) ($entry['details'] ?? '')); ?>
                    <?php
                    $before = $entry['before'] ?? null;
                    $after  = $entry['after'] ?? null;
                    if (is_array($before) && is_array($after) && isset($before['total_score'], $after['total_score'])) {
                        printf(
                            /* translators: 1: score before, 2: score after. */
                            ' <strong>' . esc_html__('Score: %1$d → %2$d.', 'caidance-ai-readiness') . '</strong>',
                            (int) $before['total_score'],
                            (int) $after['total_score']
                        );
                    }
                    ?>
                    <span style="color:#646970;"> &mdash; <?php echo esc_html((string) ($entry['by'] ?? '')); ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php if (count($entries) > 10): ?>
            <p class="description">
                <?php
                printf(
                    /* translators: %d is the total number of evidence entries stored. */
                    esc_html__('Showing the latest 10 of %d entries.', 'caidance-ai-readiness'),
                    count($entries)
                );
                ?>
            </p>
        <?php endif; ?>
        <?php
    }
}
