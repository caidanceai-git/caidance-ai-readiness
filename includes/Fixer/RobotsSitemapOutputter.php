<?php
/**
 * Front-end renderer for the Sitemap-declaration fix (virtual mode).
 *
 * When the site has no physical robots.txt, WordPress generates one on
 * the fly. Filtering that output is the only correct way to add a line
 * to it — so this class appends the approved `Sitemap:` line via the
 * robots_txt filter while the enabled option is on. A pure output
 * switch, exactly like the schema fixes: no files are written, revert
 * is instant, deactivating the plugin removes the line completely.
 *
 * Standing never-duplicate guard: if the generated output already
 * contains ANY Sitemap line — core sitemaps re-enabled, an SEO plugin
 * that started declaring one, anything — this outputter goes silent
 * rather than declaring a second sitemap. Caidance never fights another
 * tool for the same output. It also respects the blog_public flag the
 * same way core does: a site that discourages search engines gets no
 * sitemap declaration.
 *
 * Runs late (priority 99) so core and SEO-plugin robots_txt filters
 * have already spoken by the time the guard looks at the output.
 *
 * Also home of the shared line helpers (hasSitemapLine / appendLine)
 * used by the fixer's preview and file-mode apply — one code path
 * builds the resulting content everywhere, so preview always equals
 * what gets written or served.
 *
 * @package Caidance\AiReadiness
 */

declare(strict_types=1);

namespace Caidance\AiReadiness\Fixer;

final class RobotsSitemapOutputter
{
    public const ENABLED_OPTION = 'caidance_air_robots_sitemap_enabled';

    public static function register(): void
    {
        add_filter('robots_txt', [self::class, 'filter'], 99, 2);
    }

    /**
     * robots_txt filter callback.
     *
     * @param mixed $output The generated robots.txt output so far.
     * @param mixed $public The blog_public option value ('1'/'0').
     */
    public static function filter($output, $public): string
    {
        $output = (string) $output;

        if ((string) $public === '0') {
            return $output;
        }
        if (get_option(self::ENABLED_OPTION, '0') !== '1') {
            return $output;
        }

        $url = self::storedSitemapUrl();
        if ($url === '') {
            return $output;
        }

        if (self::hasSitemapLine($output)) {
            return $output;
        }

        return self::appendLine($output, $url);
    }

    /**
     * The sitemap URL approved at apply time (recorded in the fixer's
     * marker). Empty string when no virtual apply is on record.
     */
    public static function storedSitemapUrl(): string
    {
        $marker = get_option(RobotsSitemapFixer::MARKER_OPTION, null);
        return is_array($marker) ? (string) ($marker['sitemap_url'] ?? '') : '';
    }

    /**
     * Whether the robots.txt content declares any Sitemap: line.
     * Case-insensitive on the directive name per the spec — the same
     * parse RobotsSitemapCheck uses, so fix and check always agree.
     */
    public static function hasSitemapLine(string $robots): bool
    {
        foreach (explode("\n", $robots) as $rawLine) {
            if (stripos(trim($rawLine), 'Sitemap:') === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether the robots.txt content declares exactly this sitemap URL.
     * Used by apply() to verify the served file after the change.
     */
    public static function hasSitemapLineFor(string $robots, string $url): bool
    {
        foreach (explode("\n", $robots) as $rawLine) {
            $trimmed = trim($rawLine);
            if (stripos($trimmed, 'Sitemap:') === 0
                && trim(substr($trimmed, strlen('Sitemap:'))) === $url
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Appends the Sitemap line to robots.txt content. Every existing
     * byte is kept untouched — the only mutation is the append: a
     * newline terminator if the content lacks one, one blank separator
     * line, then the Sitemap line.
     */
    public static function appendLine(string $robots, string $url): string
    {
        $line = 'Sitemap: ' . $url;

        if (trim($robots) === '') {
            return $line . "\n";
        }

        $out = $robots;
        if (!str_ends_with($out, "\n")) {
            $out .= "\n";
        }
        if (!str_ends_with($out, "\n\n")) {
            $out .= "\n";
        }

        return $out . $line . "\n";
    }
}
