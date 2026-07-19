<?php
/**
 * Builds the proposed llms.txt content for this site.
 *
 * Deterministic and fully local: the file is assembled from the site's
 * own real data (name, tagline, declared industry, published pages and
 * posts, product taxonomies, sitemap URL). No AI inference, no remote
 * calls, no invented facts — what the owner previews is exactly what
 * gets written, and the same inputs always produce the same file (so
 * the revert hash check stays meaningful).
 *
 * The key-page list is curated, not dumped. Utility and transactional
 * pages (cart, checkout, account, legal boilerplate, thank-you pages)
 * are excluded — detected by the assigned WordPress/WooCommerce page
 * IDs where available, by slug pattern otherwise, so leftover
 * duplicates like cart-2 are caught too. Homepage aliases collapse
 * into the one canonical Home line. Meaningful pages (about, contact,
 * services, resources) are preferred, and on WooCommerce stores the
 * shop page and the top product category / brand archives lead the
 * list — the pages an AI agent should actually be steered to.
 *
 * Format follows the llms.txt convention (llmstxt.org): an H1 title, a
 * blockquote summary, then markdown link sections.
 *
 * @package Caidance\AiReadiness
 */

declare(strict_types=1);

namespace Caidance\AiReadiness\Fixer;

use Caidance\AiReadiness\Admin\SettingsPage;
use WP_Term;

final class LlmsTxtContentBuilder
{
    private const MAX_KEY_PAGES       = 10;
    private const MAX_CANDIDATE_PAGES = 100;
    private const MAX_CATEGORIES      = 4;
    private const MAX_BRANDS          = 2;
    private const MAX_POSTS           = 3;

    // Ranking tiers for key-page candidates: higher sorts first, ties
    // keep source order (usort is stable on PHP 8). The store-industry
    // hint lifts category/brand archives above the preferred pages —
    // it never decides whether they appear.
    private const PRIORITY_SHOP            = 100;
    private const PRIORITY_CATEGORY_BIASED = 90;
    private const PRIORITY_BRAND_BIASED    = 85;
    private const PRIORITY_STORE_SLUG      = 70;
    private const PRIORITY_PREFERRED       = 60;
    private const PRIORITY_CATEGORY        = 40;
    private const PRIORITY_BRAND           = 35;
    private const PRIORITY_PAGE            = 0;

    /**
     * WordPress default taglines that carry no information — skipped
     * rather than published to AI agents.
     */
    private const PLACEHOLDER_TAGLINES = [
        'just another wordpress site',
        '',
    ];

    /**
     * Utility and transactional slugs an AI agent should never be
     * steered to: commerce plumbing, auth, legal boilerplate,
     * post-purchase pages, and the WP starter page. Matched on the
     * base slug (underscores normalized, "-2" duplicate suffix
     * stripped). The assigned page IDs are excluded separately in
     * excludedPageIds() — this list catches the unassigned strays.
     */
    private const UTILITY_SLUGS = [
        'cart', 'checkout', 'my-account', 'account',
        'privacy-policy', 'privacy', 'terms', 'terms-of-service',
        'terms-and-conditions', 'terms-conditions', 'terms-of-use',
        'refund-returns', 'refund-and-returns-policy', 'returns-policy', 'refund-policy',
        'thank-you', 'thankyou', 'thanks', 'order-received', 'order-confirmation',
        'login', 'log-in', 'logout', 'register', 'sign-up', 'signup', 'sign-in',
        'lost-password', 'password-reset', 'sample-page',
    ];

    /**
     * Homepage-alias slugs — pages that restate the front page under
     * another URL. The canonical Home line build() always emits makes
     * every one of these redundant.
     */
    private const HOME_ALIAS_SLUGS = ['home', 'homepage', 'home-page', 'front-page', 'welcome', 'main'];

    /**
     * The pages AI agents are usually sent for — surfaced ahead of
     * anything without a named priority.
     */
    private const PREFERRED_SLUGS = [
        'about', 'about-us', 'contact', 'contact-us', 'services', 'our-services',
        'brands', 'resources', 'faq', 'faqs', 'pricing', 'blog', 'news',
        'menu', 'locations', 'support', 'team', 'reviews', 'testimonials',
    ];

    /**
     * Store-landing slugs, for shops whose catalog page is not the
     * assigned WooCommerce shop page (or not WooCommerce at all).
     */
    private const STORE_SLUGS = ['shop', 'store', 'products'];

    /**
     * Brand taxonomies in the order tried: WooCommerce core (9.6+) /
     * WooCommerce Brands, then Perfect Brands for WooCommerce.
     */
    private const BRAND_TAXONOMIES = ['product_brand', 'pwb-brand'];

    /**
     * Industry picks that mark this site as a store — see
     * SettingsPage::INDUSTRIES for the vocabulary.
     */
    private const STORE_INDUSTRIES = ['ecommerce', 'local-business'];

    /**
     * Assembles the full llms.txt content as plain text (markdown).
     */
    public function build(): string
    {
        $name    = $this->cleanLine((string) get_bloginfo('name'));
        $home    = untrailingslashit((string) home_url('/'));
        $summary = $this->buildSummary($name);

        $lines   = [];
        $lines[] = '# ' . ($name !== '' ? $name : $home);
        $lines[] = '';
        $lines[] = '> ' . $summary;
        $lines[] = '';
        $lines[] = '## Key pages';
        $lines[] = '';
        $lines[] = '- [Home](' . $home . '/): main site';

        foreach ($this->keyPages() as $page) {
            $line = '- [' . $page['title'] . '](' . $page['url'] . ')';
            if ($page['note'] !== '') {
                $line .= ': ' . $page['note'];
            }
            $lines[] = $line;
        }

        $posts = $this->recentPosts();
        if ($posts !== []) {
            $lines[] = '';
            $lines[] = '## Recent posts';
            $lines[] = '';
            foreach ($posts as $post) {
                $lines[] = '- [' . $post['title'] . '](' . $post['url'] . ')';
            }
        }

        $sitemap = $this->sitemapUrl();
        if ($sitemap !== '') {
            $lines[] = '';
            $lines[] = '## Sitemap';
            $lines[] = '';
            $lines[] = '- [XML sitemap](' . $sitemap . '): full index of published content';
        }

        $lines[] = '';
        $lines[] = 'This file was created with the site owner\'s approval using the Caidance AI-Readiness plugin for WordPress.';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * One-sentence summary: tagline when it is a real one, plus the
     * declared industry when set. Never fabricates specifics.
     */
    private function buildSummary(string $name): string
    {
        $tagline  = $this->cleanLine((string) get_bloginfo('description'));
        $industry = (string) get_option('caidance_air_industry', '');
        $label    = SettingsPage::INDUSTRIES[$industry] ?? '';

        $parts = [];

        if ($tagline !== '' && !in_array(strtolower($tagline), self::PLACEHOLDER_TAGLINES, true)) {
            $parts[] = rtrim($tagline, '.') . '.';
        }

        if ($industry !== '' && $label !== '') {
            $subject = $name !== '' ? $name : 'This site';
            $parts[] = $subject . ' operates in the ' . $label . ' industry.';
        }

        if ($parts === []) {
            $subject = $name !== '' ? $name : 'This site';
            $parts[] = $subject . ' publishes its key pages below for AI agents and search systems.';
        }

        return implode(' ', $parts);
    }

    /**
     * The curated key-page list: page and store-archive candidates
     * ranked by priority, deduped against each other and the home URL,
     * capped at MAX_KEY_PAGES.
     *
     * @return array<int, array{title: string, url: string, note: string}>
     */
    private function keyPages(): array
    {
        $candidates = array_merge($this->pageCandidates(), $this->storeCandidates());

        usort($candidates, static function (array $a, array $b): int {
            return $b['priority'] <=> $a['priority'];
        });

        $seen  = [$this->urlKey((string) home_url('/')) => true];
        $items = [];

        foreach ($candidates as $candidate) {
            $key = $this->urlKey($candidate['url']);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $items[] = [
                'title' => $candidate['title'],
                'url'   => $candidate['url'],
                'note'  => $candidate['note'],
            ];
            if (count($items) === self::MAX_KEY_PAGES) {
                break;
            }
        }

        return $items;
    }

    /**
     * Published pages from the candidate pool, minus the assigned
     * utility pages, the slug-pattern strays, and homepage aliases.
     *
     * @return array<int, array{title: string, url: string, note: string, priority: int}>
     */
    private function pageCandidates(): array
    {
        $pages = get_pages([
            'sort_column' => 'menu_order,post_title',
            'post_status' => 'publish',
            'number'      => self::MAX_CANDIDATE_PAGES,
        ]);

        if (!is_array($pages)) {
            return [];
        }

        $excludedIds = $this->excludedPageIds();
        $shopId      = (int) get_option('woocommerce_shop_page_id', 0);

        $candidates = [];
        foreach ($pages as $page) {
            $id = (int) $page->ID;
            if (isset($excludedIds[$id])) {
                continue;
            }

            $slug  = $this->baseSlug((string) $page->post_name);
            $title = $this->cleanLine((string) get_the_title($page));
            $url   = (string) get_permalink($page);
            if ($title === '' || $url === '') {
                continue;
            }
            if (in_array($slug, self::UTILITY_SLUGS, true)) {
                continue;
            }
            if (in_array($slug, self::HOME_ALIAS_SLUGS, true) || $this->isHomeAliasTitle($title)) {
                continue;
            }

            $isShop   = ($shopId > 0 && $id === $shopId);
            $priority = self::PRIORITY_PAGE;
            if ($isShop) {
                $priority = self::PRIORITY_SHOP;
            } elseif (in_array($slug, self::STORE_SLUGS, true)) {
                $priority = self::PRIORITY_STORE_SLUG;
            } elseif (in_array($slug, self::PREFERRED_SLUGS, true)) {
                $priority = self::PRIORITY_PREFERRED;
            }

            $candidates[] = [
                'title'    => $title,
                'url'      => $url,
                'note'     => $isShop ? 'all products' : '',
                'priority' => $priority,
            ];
        }

        return $candidates;
    }

    /**
     * Page IDs assigned to utility roles in WordPress or WooCommerce
     * settings — the most reliable signal, since a renamed cart page
     * is still the cart. The options simply do not exist on sites
     * without WooCommerce. The front page is excluded here too: the
     * canonical Home line already covers it.
     *
     * @return array<int, true> Keyed by page ID.
     */
    private function excludedPageIds(): array
    {
        $options = [
            'page_on_front',
            'wp_page_for_privacy_policy',
            'woocommerce_cart_page_id',
            'woocommerce_checkout_page_id',
            'woocommerce_myaccount_page_id',
            'woocommerce_terms_page_id',
            'woocommerce_refund_returns_page_id',
        ];

        $ids = [];
        foreach ($options as $option) {
            $id = (int) get_option($option, 0);
            if ($id > 0) {
                $ids[$id] = true;
            }
        }

        return $ids;
    }

    /**
     * WooCommerce term archives: the top product categories and brand
     * terms by product count. Non-store sites (no taxonomy) return [].
     *
     * @return array<int, array{title: string, url: string, note: string, priority: int}>
     */
    private function storeCandidates(): array
    {
        $bias = in_array((string) get_option('caidance_air_industry', ''), self::STORE_INDUSTRIES, true);

        $candidates = $this->termCandidates(
            'product_cat',
            self::MAX_CATEGORIES,
            'product category',
            $bias ? self::PRIORITY_CATEGORY_BIASED : self::PRIORITY_CATEGORY
        );

        foreach (self::BRAND_TAXONOMIES as $taxonomy) {
            if (!taxonomy_exists($taxonomy)) {
                continue;
            }
            $candidates = array_merge($candidates, $this->termCandidates(
                $taxonomy,
                self::MAX_BRANDS,
                'brand',
                $bias ? self::PRIORITY_BRAND_BIASED : self::PRIORITY_BRAND
            ));
            break;
        }

        return $candidates;
    }

    /**
     * Top terms of one taxonomy by product count, ties broken by name
     * so the same catalog always produces the same file. Skips the
     * store's default "Uncategorized" term.
     *
     * @return array<int, array{title: string, url: string, note: string, priority: int}>
     */
    private function termCandidates(string $taxonomy, int $limit, string $note, int $priority): array
    {
        if (!taxonomy_exists($taxonomy)) {
            return [];
        }

        $terms = get_terms([
            'taxonomy'   => $taxonomy,
            'hide_empty' => true,
            'orderby'    => 'count',
            'order'      => 'DESC',
            'number'     => $limit * 3,
        ]);

        if (!is_array($terms)) {
            return [];
        }

        $defaultCategory = $taxonomy === 'product_cat' ? (int) get_option('default_product_cat', 0) : 0;

        $usable = [];
        foreach ($terms as $term) {
            if (!($term instanceof WP_Term)) {
                continue;
            }
            if ((int) $term->term_id === $defaultCategory || $term->slug === 'uncategorized') {
                continue;
            }
            $title = $this->cleanLine((string) $term->name);
            $url   = get_term_link($term);
            if ($title === '' || !is_string($url) || $url === '') {
                continue;
            }
            $usable[] = ['title' => $title, 'url' => $url, 'count' => (int) $term->count];
        }

        usort($usable, static function (array $a, array $b): int {
            return ($b['count'] <=> $a['count']) ?: strcasecmp($a['title'], $b['title']);
        });

        $candidates = [];
        foreach (array_slice($usable, 0, $limit) as $term) {
            $candidates[] = [
                'title'    => $term['title'],
                'url'      => $term['url'],
                'note'     => $note,
                'priority' => $priority,
            ];
        }

        return $candidates;
    }

    /**
     * Lowercased slug with underscores normalized to hyphens and any
     * "-2"-style duplicate suffix removed — so cart-2, the classic
     * leftover duplicate, matches cart.
     */
    private function baseSlug(string $slug): string
    {
        $slug = str_replace('_', '-', strtolower(trim($slug)));
        return preg_replace('/-\d+$/', '', $slug) ?? $slug;
    }

    /**
     * True when a page title is just another name for the homepage —
     * the site name, the bare domain, or a Home/Homepage variant
     * (optionally prefixed with either). Those entries would duplicate
     * the canonical Home line.
     */
    private function isHomeAliasTitle(string $title): bool
    {
        $normalized = strtolower(trim($title));

        $host  = strtolower((string) wp_parse_url((string) home_url('/'), PHP_URL_HOST));
        $names = array_filter([
            strtolower($this->cleanLine((string) get_bloginfo('name'))),
            $host,
            $host !== '' ? 'www.' . $host : '',
            $host !== '' ? (string) preg_replace('/^www\./', '', $host) : '',
        ], static function (string $name): bool {
            return $name !== '';
        });

        // Longest first, so "www.example.com" strips before "example.com".
        usort($names, static function (string $a, string $b): int {
            return strlen($b) <=> strlen($a);
        });

        foreach ($names as $name) {
            if (str_starts_with($normalized, $name)) {
                $normalized = trim(substr($normalized, strlen($name)));
                break;
            }
        }

        $separators = '/^[\s\-\x{2013}\x{2014}:|.]+|[\s\-\x{2013}\x{2014}:|.]+$/u';
        $normalized = preg_replace($separators, '', $normalized) ?? $normalized;

        return in_array($normalized, ['', 'home', 'homepage', 'home page', 'welcome'], true);
    }

    /**
     * Canonical comparison key for dedupe: lowercased, trailing slash
     * dropped, scheme collapsed — so http://Site.com/a/ and
     * https://site.com/a count as the same destination.
     */
    private function urlKey(string $url): string
    {
        $key = strtolower(untrailingslashit(trim($url)));
        return preg_replace('#^https?://#', '', $key) ?? $key;
    }

    /**
     * Up to MAX_POSTS most recent published posts (empty on page-only sites).
     *
     * @return array<int, array{title: string, url: string}>
     */
    private function recentPosts(): array
    {
        $recent = wp_get_recent_posts([
            'numberposts' => self::MAX_POSTS,
            'post_status' => 'publish',
        ]);

        if (!is_array($recent)) {
            return [];
        }

        $items = [];
        foreach ($recent as $post) {
            $id = isset($post['ID']) ? (int) $post['ID'] : 0;
            if ($id === 0) {
                continue;
            }
            $title = $this->cleanLine((string) get_the_title($id));
            $url   = (string) get_permalink($id);
            if ($title === '' || $url === '') {
                continue;
            }
            $items[] = ['title' => $title, 'url' => $url];
        }

        return $items;
    }

    private function sitemapUrl(): string
    {
        if (!function_exists('get_sitemap_url')) {
            return '';
        }
        $url = get_sitemap_url('index');
        return is_string($url) ? $url : '';
    }

    /**
     * Strips tags/control characters and the markdown-link-breaking
     * bracket characters from a single line of site data.
     */
    private function cleanLine(string $value): string
    {
        $value = wp_strip_all_tags($value, true);
        $value = str_replace(['[', ']', '(', ')'], ['', '', '', ''], $value);
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        return trim($value);
    }
}
