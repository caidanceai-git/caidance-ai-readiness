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
        $lastScan    = (string) get_option('caidance_air_last_scan', '');
        $settingsUrl = admin_url('options-general.php?page=' . SettingsPage::MENU_SLUG);

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Caidance — AI-Readiness Scan', 'caidance-ai-readiness'); ?></h1>

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
            <?php elseif ($lastScan === ''): ?>
                <p>
                    <?php esc_html_e('No scan has run yet. The scan engine ships in the next plugin update.', 'caidance-ai-readiness'); ?>
                </p>
            <?php else: ?>
                <p>
                    <?php
                    printf(
                        /* translators: %s is the last-scan timestamp wrapped in a code tag. */
                        esc_html__('Last scan: %s', 'caidance-ai-readiness'),
                        '<code>' . esc_html($lastScan) . '</code>'
                    );
                    ?>
                </p>
                <p>
                    <em><?php esc_html_e('Full results rendering ships with the scan engine in the next plugin update.', 'caidance-ai-readiness'); ?></em>
                </p>
            <?php endif; ?>

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
}
