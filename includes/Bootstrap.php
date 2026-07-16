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
use Caidance\AiReadiness\Admin\FixActions;
use Caidance\AiReadiness\Admin\SettingsPage;
use Caidance\AiReadiness\Admin\ToolsPage;
use Caidance\AiReadiness\Fixer\SchemaOutputter;
use Caidance\AiReadiness\Rest\ScanController;
use Caidance\AiReadiness\Scanner\LocalScanner;
use Caidance\AiReadiness\Storage\ScanHistoryRepository;

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
        // Translation loading: WP.org auto-loads translations from the
        // plugin slug since WP 4.6 — no manual load_plugin_textdomain()
        // call needed when the plugin is hosted on WP.org.

        // REST endpoint must register on every request (wp-json context
        // is not is_admin). Permission_callback gates actual usage.
        (new ScanController())->register();

        // Schema-fix front-end output (Organization / WebSite JSON-LD on
        // the front page while the matching option is enabled).
        SchemaOutputter::register();

        // Weekly cron handler. Scheduled in onActivation; this is the
        // listener that actually runs the scan when WP-Cron fires.
        add_action('caidance_air_weekly_scan', [self::class, 'runScheduledScan']);

        if (is_admin()) {
            (new SettingsPage())->register();
            (new DashboardWidget())->register();
            (new ToolsPage())->register();
            (new FixActions())->register();
        }

        // WP-CLI smoke-test command. Lets us run a scan from SSH and
        // see the raw JSON output, before any UI button is wired.
        // Closure form bypasses WP-CLI's CommandFactory (which would
        // try to instantiate LocalScanner with no args and fatal).
        if (defined('WP_CLI') && WP_CLI && class_exists('\WP_CLI')) {
            \WP_CLI::add_command(
                'caidance-air scan',
                static function (array $args, array $assoc_args): void {
                    LocalScanner::cliRun($args, $assoc_args);
                }
            );
        }
    }

    /**
     * Cron callback for caidance_air_weekly_scan.
     *
     * Skips if no industry is set (a scan would be unhelpful without
     * industry context). Also throttles to at most one auto-scan per
     * 6 days, so accidental WP-Cron double-fires don't double-scan and
     * burn the history slots.
     *
     * Manual scans via the Settings page / REST endpoint / WP-CLI
     * bypass this throttle — they're explicit user actions.
     */
    public static function runScheduledScan(): void
    {
        $industry = (string) get_option('caidance_air_industry', '');
        if ($industry === '') {
            return;
        }

        $repo   = new ScanHistoryRepository();
        $latest = $repo->getLatest();

        if (is_array($latest) && isset($latest['ran_at'])) {
            $lastRanAt = strtotime((string) $latest['ran_at']);
            if ($lastRanAt !== false && (time() - $lastRanAt) < (6 * DAY_IN_SECONDS)) {
                return;
            }
        }

        $result = LocalScanner::buildDefault()->run();
        $repo->saveScan($result);
    }
}
