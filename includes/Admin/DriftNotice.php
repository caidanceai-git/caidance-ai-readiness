<?php
/**
 * Admin notice for fix drift — a previously applied fix that stopped
 * holding (detected by the weekly scan, flags stored by Bootstrap).
 *
 * Deliberately quiet: shows only on the WordPress Dashboard and the
 * plugin's own Settings/Tools screens — never an admin-wide nag. The
 * Tools page carries the one-click re-apply.
 *
 * @package Caidance\AiReadiness
 */

declare(strict_types=1);

namespace Caidance\AiReadiness\Admin;

final class DriftNotice
{
    public const OPTION = 'caidance_air_drift_flags';

    private const SCREENS = [
        'dashboard',
        'settings_page_' . SettingsPage::MENU_SLUG,
        'tools_page_' . ToolsPage::MENU_SLUG,
    ];

    public function register(): void
    {
        add_action('admin_notices', [$this, 'maybeShow']);
    }

    public function maybeShow(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen === null || !in_array($screen->id, self::SCREENS, true)) {
            return;
        }

        $flags = get_option(self::OPTION, []);
        if (!is_array($flags) || $flags === []) {
            return;
        }

        $toolsUrl = add_query_arg(['page' => ToolsPage::MENU_SLUG], admin_url('tools.php'));
        ?>
        <div class="notice notice-warning">
            <p>
                <strong><?php esc_html_e('Caidance drift alert:', 'caidance-ai-readiness'); ?></strong>
                <?php esc_html_e('a fix Caidance applied earlier is no longer holding.', 'caidance-ai-readiness'); ?>
            </p>
            <ul style="list-style:disc;margin:4px 0 8px 24px;">
                <?php foreach ($flags as $flag): if (!is_array($flag)) { continue; } ?>
                    <li>
                        <strong><?php echo esc_html((string) ($flag['label'] ?? '')); ?></strong>
                        &mdash; <?php echo esc_html((string) ($flag['detail'] ?? '')); ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p>
                <a class="button button-primary" href="<?php echo esc_url($toolsUrl); ?>">
                    <?php esc_html_e('Review and re-apply on the Tools page', 'caidance-ai-readiness'); ?>
                </a>
            </p>
        </div>
        <?php
    }
}
