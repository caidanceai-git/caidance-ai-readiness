<?php
/**
 * Extracts meta tags and the canonical link from an HTML document.
 *
 * Handles three real-world shapes:
 *   <meta name="…" content="…">
 *   <meta property="…" content="…">      (Open Graph uses "property")
 *   <link rel="canonical" href="…">
 *
 * Attributes can appear in any order, with either single or double
 * quotes, and the meta/link tags themselves are typically self-closing
 * in modern HTML5. We parse permissively but conservatively — bad
 * markup should never crash the scanner.
 *
 * All methods are static — no instance state.
 *
 * @package Caidance\AiReadiness
 */

declare(strict_types=1);

namespace Caidance\AiReadiness\Html;

final class HtmlMetaExtractor
{
    /**
     * Returns a flat map of meta-key → meta-content found in $html.
     *
     * Both `name` and `property` attributes resolve into the same map
     * (with the original key preserved). When the same key appears
     * multiple times, the first occurrence wins.
     *
     * @return array<string, string>
     */
    public static function extractMetas(string $html): array
    {
        $metas = [];

        $matched = preg_match_all(
            '/<meta\b([^>]+)\/?>/i',
            $html,
            $matches
        );

        if ($matched === false || $matched === 0) {
            return $metas;
        }

        foreach ($matches[1] as $attributesBlob) {
            $key     = self::firstAttribute($attributesBlob, ['property', 'name']);
            $content = self::extractAttribute($attributesBlob, 'content');

            if ($key === null || $content === null) {
                continue;
            }

            $normalizedKey = strtolower($key);
            if (!isset($metas[$normalizedKey])) {
                $metas[$normalizedKey] = $content;
            }
        }

        return $metas;
    }

    /**
     * Returns the canonical URL from <link rel="canonical">, or null if
     * none is present.
     */
    public static function extractCanonical(string $html): ?string
    {
        $matched = preg_match_all(
            '/<link\b([^>]+)\/?>/i',
            $html,
            $matches
        );

        if ($matched === false || $matched === 0) {
            return null;
        }

        foreach ($matches[1] as $attributesBlob) {
            $rel = self::extractAttribute($attributesBlob, 'rel');
            if ($rel === null || strtolower(trim($rel)) !== 'canonical') {
                continue;
            }
            $href = self::extractAttribute($attributesBlob, 'href');
            if ($href !== null && $href !== '') {
                return $href;
            }
        }

        return null;
    }

    /**
     * Returns the value of the first attribute in $candidates that
     * appears in $attributesBlob, or null if none of them is present.
     *
     * @param array<int, string> $candidates
     */
    private static function firstAttribute(string $attributesBlob, array $candidates): ?string
    {
        foreach ($candidates as $name) {
            $value = self::extractAttribute($attributesBlob, $name);
            if ($value !== null) {
                return $value;
            }
        }
        return null;
    }

    /**
     * Extracts a single attribute value from an HTML tag attributes blob.
     * Supports single quotes, double quotes, or unquoted values.
     * Returns null if the attribute is not present.
     */
    private static function extractAttribute(string $attributesBlob, string $name): ?string
    {
        $pattern = '/\b' . preg_quote($name, '/') . '\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i';
        if (preg_match($pattern, $attributesBlob, $m) !== 1) {
            return null;
        }
        // Pick whichever capture group matched.
        for ($i = 2; $i <= 4; $i++) {
            if (isset($m[$i]) && $m[$i] !== '') {
                return $m[$i];
            }
            if (isset($m[$i]) && $m[$i] === '' && $i === 2 && isset($m[1]) && $m[1] === '""') {
                return '';
            }
        }
        return null;
    }
}
