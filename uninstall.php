<?php
/**
 * Uninstall handler for Caidance — AI-Readiness Score.
 *
 * Fires when the user deletes the plugin via the Plugins screen. Cleans up
 * all plugin-owned options and scheduled events. Options are deliberately
 * preserved through deactivation but removed here on uninstall.
 *
 * @package Caidance\AiReadiness
 */

declare(strict_types=1);

// Guard: only run via the standard uninstall flow.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Plugin-owned options (mirrors Bootstrap::onActivation defaults).
$caidance_air_options = [
    'caidance_air_industry',
    'caidance_air_ai_crawler_check_enabled',
    'caidance_air_last_scan',
    'caidance_air_scan_history',
    'caidance_air_activated_at',
    'caidance_air_welcome_dismissed',
    'caidance_air_llms_txt_marker',
    'caidance_air_evidence_log',
    'caidance_air_robots_fix_marker',
    'caidance_air_org_schema_enabled',
    'caidance_air_org_schema_marker',
    'caidance_air_website_schema_enabled',
    'caidance_air_website_schema_marker',
    'caidance_air_drift_flags',
];

// An llms.txt file created via the First Fix is deliberately NOT deleted
// here — the owner approved its creation and it is site content. Revert
// from Tools → Caidance Scan before uninstalling to remove it.

foreach ($caidance_air_options as $caidance_air_option) {
    delete_option($caidance_air_option);
}

// Clear the weekly re-scan cron event.
$caidance_air_timestamp = wp_next_scheduled('caidance_air_weekly_scan');
if ($caidance_air_timestamp !== false) {
    wp_unschedule_event($caidance_air_timestamp, 'caidance_air_weekly_scan');
}
