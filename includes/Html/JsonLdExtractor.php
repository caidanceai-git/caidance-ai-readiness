<?php
/**
 * Extracts JSON-LD nodes from an HTML document.
 *
 * Handles three real-world shapes:
 *   1. A naked typed object (one node)
 *   2. An @graph wrapper (many nodes inside one script block)
 *   3. Multiple script[type=application/ld+json] blocks on the same page
 *
 * @type can be a string OR an array; we normalize to an array in every
 * returned node so callers don't have to branch. JSON decode failures
 * are silently skipped — a malformed block on the page should never
 * crash the scanner.
 *
 * All methods are static — no instance state.
 *
 * @package Caidance\AiReadiness
 */

declare(strict_types=1);

namespace Caidance\AiReadiness\Html;

final class JsonLdExtractor
{
    /**
     * Returns every JSON-LD node found in $html, @graph wrappers expanded.
     * Each returned node has @type normalized to an array of strings.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function extract(string $html): array
    {
        $nodes = [];

        $matched = preg_match_all(
            '/<script\b[^>]*\btype\s*=\s*["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is',
            $html,
            $matches
        );

        if ($matched === false || $matched === 0) {
            return $nodes;
        }

        foreach ($matches[1] as $jsonText) {
            $decoded = json_decode(trim((string) $jsonText), true);
            if (!is_array($decoded)) {
                continue;
            }

            if (isset($decoded['@graph']) && is_array($decoded['@graph'])) {
                foreach ($decoded['@graph'] as $graphNode) {
                    if (is_array($graphNode)) {
                        $nodes[] = self::normalizeTypes($graphNode);
                    }
                }
            } else {
                $nodes[] = self::normalizeTypes($decoded);
            }
        }

        return $nodes;
    }

    /**
     * Returns nodes whose @type list contains $type (case-insensitive).
     *
     * @param array<int, array<string, mixed>> $nodes
     * @return array<int, array<string, mixed>>
     */
    public static function findByType(array $nodes, string $type): array
    {
        $target = strtolower($type);
        $hits   = [];

        foreach ($nodes as $node) {
            $types = $node['@type'] ?? [];
            if (!is_array($types)) {
                continue;
            }
            foreach ($types as $nodeType) {
                if (strtolower((string) $nodeType) === $target) {
                    $hits[] = $node;
                    break;
                }
            }
        }

        return $hits;
    }

    /**
     * Normalizes a node's @type field to an array of strings.
     *
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private static function normalizeTypes(array $node): array
    {
        $raw = $node['@type'] ?? [];

        if (is_string($raw)) {
            $raw = [$raw];
        }

        if (!is_array($raw)) {
            $node['@type'] = [];
            return $node;
        }

        $normalized = [];
        foreach ($raw as $value) {
            $string = (string) $value;
            if ($string !== '') {
                $normalized[] = $string;
            }
        }

        $node['@type'] = $normalized;
        return $node;
    }
}
