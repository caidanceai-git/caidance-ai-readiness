<?php
/**
 * Persistence layer for scan results.
 *
 * Stores the most recent MAX_HISTORY scans in a single wp_options row
 * (caidance_air_scan_history) with append-and-trim semantics. The
 * latest scan's timestamp is also mirrored to caidance_air_last_scan
 * for cheap freshness queries (so the Dashboard widget and Settings
 * page don't have to deserialize the full history just to show a
 * timestamp).
 *
 * Option is NOT autoloaded — keeping it off the autoload table means
 * the history bytes don't ride along on every WP request.
 *
 * @package Caidance\AiReadiness
 */

declare(strict_types=1);

namespace Caidance\AiReadiness\Storage;

final class ScanHistoryRepository
{
    public const MAX_HISTORY = 12;

    private const OPTION_HISTORY = 'caidance_air_scan_history';
    private const OPTION_LATEST  = 'caidance_air_last_scan';

    /**
     * Prepends a scan to history, trimming the tail to MAX_HISTORY.
     *
     * @param array<string, mixed> $scanResult
     */
    public function saveScan(array $scanResult): void
    {
        $history = $this->getHistory();
        array_unshift($history, $scanResult);
        if (count($history) > self::MAX_HISTORY) {
            $history = array_slice($history, 0, self::MAX_HISTORY);
        }

        // autoload = false so we don't bloat every wp request.
        update_option(self::OPTION_HISTORY, $history, false);

        $timestamp = isset($scanResult['ran_at']) ? (string) $scanResult['ran_at'] : current_time('mysql');
        update_option(self::OPTION_LATEST, $timestamp);
    }

    /**
     * Returns the most recent scan, or null if no scan has run.
     *
     * @return array<string, mixed>|null
     */
    public function getLatest(): ?array
    {
        $history = $this->getHistory();
        return $history[0] ?? null;
    }

    /**
     * Returns all stored scans, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getHistory(): array
    {
        $raw = get_option(self::OPTION_HISTORY, []);
        return is_array($raw) ? array_values($raw) : [];
    }

    /**
     * Removes all stored scan history. Called from uninstall.php.
     */
    public function clearHistory(): void
    {
        delete_option(self::OPTION_HISTORY);
        delete_option(self::OPTION_LATEST);
    }
}
