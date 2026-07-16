<?php
/**
 * Append-only evidence log for fixes the plugin applies or reverts.
 *
 * Every apply/revert (and every refusal) is recorded with who, when,
 * what changed, and the before/after check state — the receipts that
 * make an applied fix trustworthy. Stored newest-first in a single
 * non-autoloaded option, trimmed to MAX_ENTRIES.
 *
 * Entries are never edited or deleted individually; the log only ever
 * gains entries (until uninstall removes the whole option).
 *
 * @package Caidance\AiReadiness
 */

declare(strict_types=1);

namespace Caidance\AiReadiness\Storage;

final class EvidenceLog
{
    public const MAX_ENTRIES = 50;

    private const OPTION = 'caidance_air_evidence_log';

    /**
     * Appends an entry, stamping timestamp and user if not provided.
     *
     * @param array<string, mixed> $entry Expected keys: event, fix,
     *        details; optional: before, after.
     */
    public function append(array $entry): void
    {
        if (!isset($entry['at'])) {
            $entry['at'] = current_time('mysql');
        }
        if (!isset($entry['by'])) {
            $user        = wp_get_current_user();
            $entry['by'] = ($user instanceof \WP_User && $user->exists()) ? $user->user_login : 'system';
        }

        $log = $this->all();
        array_unshift($log, $entry);
        if (count($log) > self::MAX_ENTRIES) {
            $log = array_slice($log, 0, self::MAX_ENTRIES);
        }

        // autoload = false — evidence bytes should not ride every request.
        update_option(self::OPTION, $log, false);
    }

    /**
     * All entries, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $raw = get_option(self::OPTION, []);
        return is_array($raw) ? array_values($raw) : [];
    }

    /**
     * Removes the whole log. Called from uninstall.php only.
     */
    public function clear(): void
    {
        delete_option(self::OPTION);
    }
}
