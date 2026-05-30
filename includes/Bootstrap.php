<?php
/**
 * Plugin orchestrator. Handles lifecycle hooks (activation, deactivation)
 * and per-request hook registration via boot().
 *
 * @package Caidance\AiReadiness
 */

declare(strict_types=1);

namespace Caidance\AiReadiness;

use Caidance\AiReadiness\Admin\DashboardWidget;
use Caidance\AiReadiness\Admin\SettingsPage;
use Caidance\AiReadiness\Admin\ToolsPage;
use Caidance\AiReadiness\Scanner\LocalScanner;

final class Bootstrap
{
    /**
     * Fired once when the plugin is activated. Sets default options and
     * schedules the weekly re-scan cron event. add_option() only writes
     * the key if it does not already exist — safe across reactivations.
     */
    public static function onActivation(): void
    {
        add_option('caidance_air_industry', '');
        add_option('caidance_air_ai_crawler_check_enabled', '1');
        add_option('caidance_air_last_scan', '');
        add_option('caidance_air_scan_history', []);
        add_option('caidance_air_activated_at', current_time('mysql'));
        add_option('caidance_air_welcome_dismissed', '0');

        if (wp_next_scheduled('caidance_air_weekly_scan') === false) {
            wp_schedule_event(time() + DAY_IN_SECONDS, 'weekly', 'caidance_air_weekly_scan');
        }
    }

    /**
     * Fired when the plugin is deactivated. Clears scheduled events but
     * preserves options so settings survive a reactivation.
     */
    public static function onDeactivation(): void
    {
        $timestamp = wp_next_scheduled('caidance_air_weekly_scan');
        if ($timestamp !== false) {
            wp_unschedule_event($timestamp, 'caidance_air_weekly_scan');
        }
    }

    /**
     * Per-request boot. Loads translations and registers admin surfaces.
     * Runs on every request via the plugins_loaded action.
     */
    public static function boot(): void
    {
        load_plugin_textdomain(
            'caidance-ai-readiness',
            false,
            dirname(CAIDANCE_AIR_BASENAME) . '/languages'
        );

        if (is_admin()) {
            (new SettingsPage())->register();
            (new DashboardWidget())->register();
            (new ToolsPage())->register();
        }

        // WP-CLI smoke-test command. Lets us run a scan from SSH and
        // see the raw JSON output, before any UI button is wired.
        if (defined('WP_CLI') && WP_CLI && class_exists('\WP_CLI')) {
            \WP_CLI::add_command(
                'caidance-air scan',
                [LocalScanner::class, 'cliRun']
            );
        }
    }
}
