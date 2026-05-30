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

use Caidance\AiReadiness\Rendering\ResultRenderer;
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
                    if (is_array($result)) {
                        echo ResultRenderer::renderResultRow($result); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    }
                }

                echo ResultRenderer::renderScoreHistory($history, 4); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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
}
