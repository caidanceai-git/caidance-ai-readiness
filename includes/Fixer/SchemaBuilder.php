<?php
/**
 * Builds the Organization and WebSite JSON-LD nodes the schema fixes
 * output — from the site's real settings only. Fields the site does
 * not have are omitted, never invented: no fabricated logos, socials,
 * addresses, or descriptions.
 *
 * Called live on every front-page render (via SchemaOutputter), so the
 * markup follows the site's settings automatically — set a logo later
 * and the Organization node gains it without re-applying.
 *
 * All methods static — no instance state.
 *
 * @package Caidance\AiReadiness
 */

declare(strict_types=1);

namespace Caidance\AiReadiness\Fixer;

final class SchemaBuilder
{
    private const PLACEHOLDER_TAGLINES = [
        'just another wordpress site',
        '',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function organizationNode(): array
    {
        $node = [
            '@context' => 'https://schema.org',
            '@type'    => 'Organization',
            'name'     => (string) get_bloginfo('name'),
            'url'      => (string) home_url('/'),
        ];

        $logo = self::logoUrl();
        if ($logo !== '') {
            $node['logo'] = $logo;
        }

        $tagline = trim((string) get_bloginfo('description'));
        if (!in_array(strtolower($tagline), self::PLACEHOLDER_TAGLINES, true)) {
            $node['description'] = $tagline;
        }

        return $node;
    }

    /**
     * @return array<string, mixed>
     */
    public static function webSiteNode(): array
    {
        return [
            '@context'        => 'https://schema.org',
            '@type'           => 'WebSite',
            'name'            => (string) get_bloginfo('name'),
            'url'             => (string) home_url('/'),
            'potentialAction' => [
                '@type'       => 'SearchAction',
                'target'      => [
                    '@type'       => 'EntryPoint',
                    'urlTemplate' => (string) home_url('/?s={search_term_string}'),
                ],
                'query-input' => 'required name=search_term_string',
            ],
        ];
    }

    /**
     * The site's real logo: the theme's custom logo first, the site
     * icon second, empty string when neither is set.
     */
    public static function logoUrl(): string
    {
        $logoId = (int) get_theme_mod('custom_logo', 0);
        if ($logoId > 0) {
            $url = wp_get_attachment_image_url($logoId, 'full');
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        $icon = get_site_icon_url();
        return is_string($icon) ? $icon : '';
    }
}
