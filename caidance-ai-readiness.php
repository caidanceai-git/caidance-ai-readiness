<?php
/**
 * Plugin Name: Caidance — AI-Readiness Score
 * Plugin URI:  https://caidance.ai/cdi/?utm_source=wp_plugin&utm_medium=plugin_header&utm_campaign=wp_org_v1
 * Description: See what AI says about your site — and fix it. Scans your WordPress site for AI-readiness signals, shows your CDI-aligned 0–60 score, and applies one-click fixes (llms.txt, AI-crawler access, homepage schema) with your approval — verified, reversible, drift-watched. Powered by Caidance.
 * Version:     1.3.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author:      Caidance
 * Author URI:  https://caidance.ai/?utm_source=wp_plugin&utm_medium=author_header&utm_campaign=wp_org_v1
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: caidance-ai-readiness
 *
 * @package Caidance\AiReadiness
 */

declare(strict_types=1);

namespace Caidance\AiReadiness;

if (!defined('ABSPATH')) {
    exit;
}

// Plugin metadata constants.
define('CAIDANCE_AIR_VERSION', '1.3.0');
define('CAIDANCE_AIR_FILE', __FILE__);
define('CAIDANCE_AIR_DIR', __DIR__);
define('CAIDANCE_AIR_URL', plugin_dir_url(__FILE__));
define('CAIDANCE_AIR_BASENAME', plugin_basename(__FILE__));

/**
 * PSR-4-style autoloader for Caidance\AiReadiness\* classes.
 * Maps namespace segments to /includes/ directory paths.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Caidance\\AiReadiness\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path     = CAIDANCE_AIR_DIR . '/includes/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

// Activation: write default options, schedule weekly scan.
register_activation_hook(__FILE__, static function (): void {
    Bootstrap::onActivation();
});

// Deactivation: clear scheduled events. Options preserved across deactivation.
register_deactivation_hook(__FILE__, static function (): void {
    Bootstrap::onDeactivation();
});

// Boot the plugin on every request once all plugins are loaded.
add_action('plugins_loaded', static function (): void {
    Bootstrap::boot();
});
