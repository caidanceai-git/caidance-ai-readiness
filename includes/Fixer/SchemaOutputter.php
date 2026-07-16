<?php
/**
 * Front-end renderer for the schema fixes.
 *
 * Emits the Organization and/or WebSite JSON-LD nodes in wp_head on the
 * front page only, and only while the matching option is enabled — the
 * schema fixes are pure output toggles: no files are written, revert is
 * instant, deactivating the plugin removes the markup completely.
 *
 * Standing conflict guard: if a known SEO plugin is active — even one
 * installed AFTER a schema fix was applied — this outputter goes silent
 * rather than double-marking the page. Caidance never fights another
 * tool for the same structured data.
 *
 * @package Caidance\AiReadiness
 */

declare(strict_types=1);

namespace Caidance\AiReadiness\Fixer;

final class SchemaOutputter
{
    public const ORG_OPTION  = 'caidance_air_org_schema_enabled';
    public const SITE_OPTION = 'caidance_air_website_schema_enabled';

    /**
     * SEO plugins that own structured-data output when active, keyed by
     * directory slug. Shared by the schema fixers' step-aside detection
     * and this outputter's standing guard.
     */
    public const SEO_PLUGINS = [
        'wordpress-seo'       => 'Yoast SEO',
        'seo-by-rank-math'    => 'Rank Math SEO',
        'all-in-one-seo-pack' => 'All in One SEO',
        'autodescription'     => 'The SEO Framework',
        'wp-seopress'         => 'SEOPress',
        'slim-seo'            => 'Slim SEO',
    ];

    public static function register(): void
    {
        add_action('wp_head', [self::class, 'output'], 5);
    }

    public static function output(): void
    {
        if (!is_front_page()) {
            return;
        }

        $orgEnabled  = get_option(self::ORG_OPTION, '0') === '1';
        $siteEnabled = get_option(self::SITE_OPTION, '0') === '1';
        if (!$orgEnabled && !$siteEnabled) {
            return;
        }

        if (self::activeSeoPlugin() !== '') {
            return;
        }

        if ($orgEnabled) {
            self::emit(SchemaBuilder::organizationNode());
        }
        if ($siteEnabled) {
            self::emit(SchemaBuilder::webSiteNode());
        }
    }

    /**
     * Returns the display name of the first active known SEO plugin,
     * or empty string when none is active.
     */
    public static function activeSeoPlugin(): string
    {
        $active = get_option('active_plugins', []);
        if (!is_array($active)) {
            return '';
        }
        foreach ($active as $pluginFile) {
            $slug = dirname((string) $pluginFile);
            if (isset(self::SEO_PLUGINS[$slug])) {
                return self::SEO_PLUGINS[$slug];
            }
        }
        return '';
    }

    /**
     * @param array<string, mixed> $node
     */
    private static function emit(array $node): void
    {
        $json = wp_json_encode($node, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return;
        }
        echo "\n" . '<script type="application/ld+json" id="caidance-air-schema-' . esc_attr(strtolower((string) ($node['@type'] ?? 'node'))) . '">' . $json . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode output inside a JSON script tag; HTML-escaping would corrupt the JSON.
    }
}
