<?php
/**
 * REST controller for triggering a scan from the wp-admin UI.
 *
 * Single endpoint:
 *   POST /wp-json/caidance-air/v1/scan
 *     - Permission: current user must have manage_options
 *     - Nonce: WP REST standard X-WP-Nonce header (handled by JS caller)
 *     - Body: none
 *     - Response: the full scan result, including results array, score,
 *                band, ran_at timestamp. Same shape returned by the
 *                WP-CLI command.
 *     - Side effect: scan is persisted to ScanHistoryRepository.
 *
 * @package Caidance\AiReadiness
 */

declare(strict_types=1);

namespace Caidance\AiReadiness\Rest;

use Caidance\AiReadiness\Scanner\LocalScanner;
use Caidance\AiReadiness\Storage\ScanHistoryRepository;

final class ScanController
{
    private const NAMESPACE = 'caidance-air/v1';
    private const ROUTE     = '/scan';

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, self::ROUTE, [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'runScan'],
            'permission_callback' => [$this, 'canRunScan'],
        ]);
    }

    /**
     * Only logged-in admins can trigger a scan.
     */
    public function canRunScan(\WP_REST_Request $request): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * Runs a scan, persists it, returns the result as JSON.
     */
    public function runScan(\WP_REST_Request $request): \WP_REST_Response
    {
        $scanner = LocalScanner::buildDefault();
        $result  = $scanner->run();

        (new ScanHistoryRepository())->saveScan($result);

        return new \WP_REST_Response($result, 200);
    }
}
