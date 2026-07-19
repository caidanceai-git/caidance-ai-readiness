<?php
/**
 * Dashboard widget that shows the AI-readiness score at a glance.
 *
 * Renders one of three empty-state branches in v1 scaffold (no industry,
 * no scan yet, or post-scan placeholder). Full score + band + top-3 fix
 * rendering ships alongside the Scanner subsystem in the next batch.
 *
 * @package Caidance\AiReadiness
 */

declare(strict_types=1);

namespace Caidance\AiReadiness\Admin;

use Caidance\AiReadiness\Rendering\ResultRenderer;
use Caidance\AiReadiness\Storage\ScanHistoryRepository;

final class DashboardWidget
{
    private const WIDGET_ID = 'caidance_air_dashboard_widget';

    public function register(): void
    {
        add_action('wp_dashboard_setup', [$this, 'addWidget']);
    }

    public function addWidget(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        wp_add_dashboard_widget(
            self::WIDGET_ID,
            __('Caidance — AI-Readiness Score', 'caidance-ai-readiness'),
            [$this, 'render']
        );
    }

    public function render(): void
    {
        $industry    = (string) get_option('caidance_air_industry', '');
        $settingsUrl = admin_url('options-general.php?page=' . SettingsPage::MENU_SLUG);

        // Branch 1: no industry picked yet.
        if ($industry === '') {
            ?>
            <p><?php esc_html_e('Pick your industry to get started.', 'caidance-ai-readiness'); ?></p>
            <p>
                <a class="button button-primary" href="<?php echo esc_url($settingsUrl); ?>">
                    <?php esc_html_e('Open Caidance settings', 'caidance-ai-readiness'); ?>
                </a>
            </p>
            <?php
            return;
        }

        $latest = (new ScanHistoryRepository())->getLatest();

        // Branch 2: industry set, no scan yet.
        if (!is_array($latest)) {
            ?>
            <p><?php esc_html_e('Run your first scan to see your AI-readiness score.', 'caidance-ai-readiness'); ?></p>
            <p>
                <a class="button button-primary" href="<?php echo esc_url($settingsUrl); ?>">
                    <?php esc_html_e('Go to Caidance settings', 'caidance-ai-readiness'); ?>
                </a>
            </p>
            <?php
            return;
        }

        // Branch 3: scan has run — show score + band + top 3 fixes.
        $ranAt   = (string) ($latest['ran_at'] ?? '');
        $results = is_array($latest['results'] ?? null) ? $latest['results'] : [];
        $toolsUrl = admin_url('tools.php?page=' . ToolsPage::MENU_SLUG);
        $snapshotUrl = 'https://caidance.ai/snapshot/?utm_source=wp_plugin&utm_medium=dashboard_widget&utm_campaign=wp_org_v1';

        ?>
        <?php echo ResultRenderer::renderBlockedBannerForScan($latest); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        <p style="margin:0 0 10px;">
            <?php echo ResultRenderer::renderScoreBadgeForScan($latest); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </p>
        <p style="margin:0 0 12px;color:#646970;font-size:12px;">
            <?php
            printf(
                /* translators: %s is a scan timestamp. */
                esc_html__('Last scan: %s', 'caidance-ai-readiness'),
                '<code>' . esc_html($ranAt) . '</code>'
            );
            ?>
        </p>
        <?php echo ResultRenderer::renderTopFixes($results, 3); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        <p style="margin-top:14px;">
            <a href="<?php echo esc_url($toolsUrl); ?>"><?php esc_html_e('View full readout', 'caidance-ai-readiness'); ?></a>
            &nbsp;&middot;&nbsp;
            <a href="<?php echo esc_url($snapshotUrl); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Get the deeper Caidance snapshot', 'caidance-ai-readiness'); ?></a>
        </p>
        <?php
    }
}
