<?php
/**
 * Byte-preserving robots.txt group analysis and surgical removal.
 *
 * The only mutation this class ever performs is deleting WHOLE LINES:
 * the lines of a user-agent group whose agents are exclusively the
 * targeted AI crawlers, plus that group's directly attached preceding
 * comment lines and one trailing blank line. Every kept line survives
 * byte-for-byte (including \r line endings) — content is split on "\n"
 * and rejoined on "\n", an identity transform for untouched input.
 *
 * Groups that mix targeted crawlers with other user-agents are NEVER
 * removed (that would change behavior for the other agents) — they are
 * reported separately so the UI can show manual guidance instead.
 *
 * All methods static — no instance state.
 *
 * @package Caidance\AiReadiness
 */

declare(strict_types=1);

namespace Caidance\AiReadiness\Fixer;

final class RobotsTxtSurgeon
{
    /**
     * Parses content into user-agent groups.
     *
     * A group starts at a User-agent line (consecutive User-agent lines
     * share one group), collects following directive lines, and closes
     * at a blank line, the next group's first User-agent line, or EOF.
     * Comments never close a group and never join its line list.
     *
     * @return array<int, array{start: int, end: int, uas: array<int, string>, lines: array<int, int>, root_disallow: bool}>
     */
    public static function parseGroups(string $content): array
    {
        $lines     = explode("\n", $content);
        $groups    = [];
        $current   = null;
        $lastWasUa = false;

        foreach ($lines as $i => $raw) {
            $line = trim($raw);

            if ($line === '') {
                if ($current !== null) {
                    $groups[] = $current;
                    $current  = null;
                }
                $lastWasUa = false;
                continue;
            }

            if (str_starts_with($line, '#')) {
                $lastWasUa = false;
                continue;
            }

            if (stripos($line, 'User-agent:') === 0) {
                $ua = trim(substr($line, strlen('User-agent:')));
                if ($current === null || !$lastWasUa) {
                    if ($current !== null) {
                        $groups[] = $current;
                    }
                    $current = ['start' => $i, 'end' => $i, 'uas' => [], 'lines' => [], 'root_disallow' => false];
                }
                $current['uas'][]   = $ua;
                $current['lines'][] = $i;
                $current['end']     = $i;
                $lastWasUa          = true;
                continue;
            }

            $lastWasUa = false;
            if ($current !== null) {
                $current['lines'][] = $i;
                $current['end']     = $i;
                if (stripos($line, 'Disallow:') === 0 && trim(substr($line, strlen('Disallow:'))) === '/') {
                    $current['root_disallow'] = true;
                }
            }
        }

        if ($current !== null) {
            $groups[] = $current;
        }

        return $groups;
    }

    /**
     * Groups that block from root AND whose user-agents are exclusively
     * within $targets (case-insensitive) — safe to remove whole.
     *
     * @param array<int, string> $targets
     * @return array<int, array{start: int, end: int, uas: array<int, string>, lines: array<int, int>, root_disallow: bool}>
     */
    public static function removableGroups(string $content, array $targets): array
    {
        return array_values(array_filter(
            self::parseGroups($content),
            static fn(array $g): bool => $g['root_disallow'] && $g['uas'] !== [] && self::uasSubsetOf($g['uas'], $targets)
        ));
    }

    /**
     * Groups that block from root and include at least one target BUT
     * also cover other agents — never auto-removed, reported for manual
     * guidance.
     *
     * @param array<int, string> $targets
     * @return array<int, array{start: int, end: int, uas: array<int, string>, lines: array<int, int>, root_disallow: bool}>
     */
    public static function mixedBlockingGroups(string $content, array $targets): array
    {
        return array_values(array_filter(
            self::parseGroups($content),
            static fn(array $g): bool => $g['root_disallow']
                && self::uasIntersect($g['uas'], $targets)
                && !self::uasSubsetOf($g['uas'], $targets)
        ));
    }

    /**
     * Removes the given groups from content: each group's span, its
     * directly attached preceding comment lines (contiguous, no blank
     * between), and one trailing blank line. Returns the new content
     * plus the exact removed lines (1-based numbering) for the preview
     * and the evidence log.
     *
     * @param array<int, array{start: int, end: int, uas: array<int, string>, lines: array<int, int>, root_disallow: bool}> $groups
     * @return array{content: string, removed: array<int, string>}
     */
    public static function removeGroups(string $content, array $groups): array
    {
        $lines  = explode("\n", $content);
        $drop   = [];

        foreach ($groups as $group) {
            // The group's own span (headers + directives, comments inside included).
            for ($i = $group['start']; $i <= $group['end']; $i++) {
                $drop[$i] = true;
            }
            // Directly attached preceding comment lines.
            for ($i = $group['start'] - 1; $i >= 0; $i--) {
                $trimmed = trim($lines[$i]);
                if ($trimmed !== '' && str_starts_with($trimmed, '#')) {
                    $drop[$i] = true;
                    continue;
                }
                break;
            }
            // One trailing blank line, so removal does not leave double
            // blanks. The FINAL empty element of explode() is the file's
            // newline terminator, not a blank line — never drop it.
            $next = $group['end'] + 1;
            $last = count($lines) - 1;
            if (isset($lines[$next]) && trim($lines[$next]) === ''
                && !($next === $last && $lines[$next] === '')
            ) {
                $drop[$next] = true;
            }
        }

        $kept    = [];
        $removed = [];
        foreach ($lines as $i => $raw) {
            if (isset($drop[$i])) {
                $removed[] = 'line ' . ($i + 1) . ': ' . rtrim($raw, "\r");
            } else {
                $kept[] = $raw;
            }
        }

        return ['content' => implode("\n", $kept), 'removed' => $removed];
    }

    /**
     * @param array<int, string> $uas
     * @param array<int, string> $targets
     */
    private static function uasSubsetOf(array $uas, array $targets): bool
    {
        $lower = array_map('strtolower', $targets);
        foreach ($uas as $ua) {
            if (!in_array(strtolower($ua), $lower, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<int, string> $uas
     * @param array<int, string> $targets
     */
    private static function uasIntersect(array $uas, array $targets): bool
    {
        $lower = array_map('strtolower', $targets);
        foreach ($uas as $ua) {
            if (in_array(strtolower($ua), $lower, true)) {
                return true;
            }
        }
        return false;
    }
}
