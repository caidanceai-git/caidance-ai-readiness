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
        $industry = (string) get_option('caidance_air_industry', '');
        $lastScan = (string) get_option('caidance_air_last_scan', '');

        // Branch 1: no industry picked yet.
        if ($industry === '') {
            $settingsUrl = admin_url('options-general.php?page=' . SettingsPage::MENU_SLUG);
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

        // Branch 2: industry set, no scan yet.
        if ($lastScan === '') {
            ?>
            <p><?php esc_html_e('Run your first scan to see your AI-readiness score.', 'caidance-ai-readiness'); ?></p>
            <p><em><?php esc_html_e('The scan engine ships in the next plugin update.', 'caidance-ai-readiness'); ?></em></p>
            <?php
            return;
        }

        // Branch 3: scan has run (placeholder until Scanner subsystem ships full rendering).
        ?>
        <p>
            <?php esc_html_e('Latest scan:', 'caidance-ai-readiness'); ?>
            <code><?php echo esc_html($lastScan); ?></code>
        </p>
        <p>
            <em><?php esc_html_e('Score and fix rendering ships with the scan engine in the next plugin update.', 'caidance-ai-readiness'); ?></em>
        </p>
        <?php
    }
}
