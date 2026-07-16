<?php
/**
 * Tools → Caidance Scan menu page.
 *
 * Full readout of the most recent scan — score, band, all 10 check results
 * with industry-aware fix recommendations, plus a fix panel under every
 * check that has a registered Caidance fix (discovered via FixRegistry —
 * this page contains no fix-specific logic), the v1.1 bridge cards, and
 * the append-only fix evidence log.
 *
 * @package Caidance\AiReadiness
 */

declare(strict_types=1);

namespace Caidance\AiReadiness\Admin;

use Caidance\AiReadiness\Fixer\FixRegistry;
use Caidance\AiReadiness\Rendering\ResultRenderer;
use Caidance\AiReadiness\Scanner\StackDetector;
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

            <?php
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view switch; no data processing.
            $tab      = isset($_GET['tab']) ? sanitize_key((string) wp_unslash($_GET['tab'])) : 'scan';
            $scanUrl  = add_query_arg(['page' => self::MENU_SLUG], admin_url('tools.php'));
            $stackUrl = add_query_arg(['page' => self::MENU_SLUG, 'tab' => 'stack'], admin_url('tools.php'));
            ?>
            <h2 class="nav-tab-wrapper" style="margin-bottom:16px;">
                <a class="nav-tab <?php echo $tab !== 'stack' ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url($scanUrl); ?>"><?php esc_html_e('Scan results', 'caidance-ai-readiness'); ?></a>
                <a class="nav-tab <?php echo $tab === 'stack' ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url($stackUrl); ?>"><?php esc_html_e('Stack', 'caidance-ai-readiness'); ?></a>
            </h2>

            <?php if ($tab === 'stack'): ?>
                <?php $this->renderStackTab(); ?>
            <?php elseif ($industry === ''): ?>
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
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view flag for the preview expansion; no data processing.
                $previewFix = isset($_GET['caidance-air-preview']) ? sanitize_key((string) wp_unslash($_GET['caidance-air-preview'])) : '';

                foreach ($results as $result) {
                    if (!is_array($result)) {
                        continue;
                    }
                    echo ResultRenderer::renderResultRow($result); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

                    $fix = FixRegistry::get((string) ($result['checkId'] ?? ''));
                    if ($fix === null) {
                        continue;
                    }
                    $fixStatus = $fix->status($result);
                    if (!$fix->wantsPanel($fixStatus, $result)) {
                        continue;
                    }
                    echo '<div id="caidance-air-fix-' . esc_attr($fix->id()) . '" style="border:1px solid #c3c4c7;border-left:4px solid #2271b1;border-radius:4px;padding:14px 16px;margin:0 0 14px;background:#f0f6fc;max-width:860px;">';
                    echo $fix->renderPanel($fixStatus, $previewFix === $fix->id(), $result); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    echo '</div>';
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
     * Renders the Stack Sense tab: the categorized plugin inventory,
     * qualitative observations (normalized-signal shaped — never a
     * score; the scored systems diagnostic is the Alignment Review on
     * caidance.ai), and the Alignment Review deep-link carrying the
     * detected-stack prefill params. Detection is local and read-only.
     */
    private function renderStackTab(): void
    {
        $detector  = new StackDetector();
        $inventory = $detector->inventory();
        $grouped   = $detector->categories($inventory);
        $latest    = (new ScanHistoryRepository())->getLatest();
        $signals   = $detector->signals($inventory, $latest);

        $severityColors = ['info' => '#646970', 'notice' => '#2271b1', 'warning' => '#dba617'];

        $ctaUrl = add_query_arg(
            array_merge($detector->prefillParams($inventory), [
                'utm_source'   => 'wp_plugin',
                'utm_medium'   => 'plugin_stack',
                'utm_campaign' => 'wp_v14_stack_sense',
            ]),
            'https://caidance.ai/alignment-assessment/'
        );
        ?>
        <h2 style="margin-top:8px;"><?php esc_html_e('Your WordPress stack, as an AI operator sees it', 'caidance-ai-readiness'); ?></h2>
        <p style="color:#646970;max-width:860px;"><?php esc_html_e('Detected locally from your active plugins — nothing leaves your site. These are observations, not a score: the scored systems diagnostic is the Alignment Review on caidance.ai.', 'caidance-ai-readiness'); ?></p>

        <?php if ($inventory === []): ?>
            <p><em><?php esc_html_e('None of the plugins from the curated stack table are active — a very lean install.', 'caidance-ai-readiness'); ?></em></p>
        <?php else: ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:10px;max-width:860px;margin:0 0 18px;">
                <?php foreach ($grouped as $category => $names): ?>
                    <div style="border:1px solid #c3c4c7;border-radius:4px;padding:12px 14px;background:#fff;">
                        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#646970;margin-bottom:6px;"><?php echo esc_html((string) (StackDetector::CATEGORY_LABELS[$category] ?? $category)); ?></div>
                        <div style="color:#1d2327;"><?php echo esc_html(implode(', ', $names)); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <h3><?php esc_html_e('Observations', 'caidance-ai-readiness'); ?></h3>
        <?php foreach ($signals as $signal): ?>
            <div style="border:1px solid #c3c4c7;border-left:4px solid <?php echo esc_attr($severityColors[(string) $signal['severity']] ?? '#646970'); ?>;border-radius:4px;padding:12px 14px;margin:0 0 10px;background:#fff;max-width:860px;">
                <p style="margin:0 0 6px;color:#1d2327;"><strong><?php echo esc_html((string) $signal['evidence']); ?></strong></p>
                <?php if ((string) $signal['recommended_fix'] !== ''): ?>
                    <p style="margin:0;color:#646970;font-size:13px;"><?php echo esc_html((string) $signal['recommended_fix']); ?></p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <div style="border:1px solid #c3c4c7;border-left:4px solid #2271b1;border-radius:4px;padding:16px 18px;margin:18px 0 0;background:#f0f6fc;max-width:860px;">
            <h3 style="margin:0 0 8px;"><?php esc_html_e('Want the full picture?', 'caidance-ai-readiness'); ?></h3>
            <p style="margin:0 0 12px;color:#1d2327;"><?php esc_html_e('This is the surface read — what is visible from inside WordPress. The $149 Alignment Review maps your whole stack, including the tools beyond WordPress, and returns stack-specific findings with a 0–60 Alignment Score. It arrives already knowing what was detected here: the first questions come pre-filled.', 'caidance-ai-readiness'); ?></p>
            <a class="button button-primary" href="<?php echo esc_url($ctaUrl); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Run my Alignment Review', 'caidance-ai-readiness'); ?></a>
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
