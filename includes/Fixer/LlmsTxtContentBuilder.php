<?php
/**
 * Builds the proposed llms.txt content for this site.
 *
 * Deterministic and fully local: the file is assembled from the site's
 * own real data (name, tagline, declared industry, published pages and
 * posts, sitemap URL). No AI inference, no remote calls, no invented
 * facts — what the owner previews is exactly what gets written, and the
 * same inputs always produce the same file (so the revert hash check
 * stays meaningful).
 *
 * Format follows the llms.txt convention (llmstxt.org): an H1 title, a
 * blockquote summary, then markdown link sections.
 *
 * @package Caidance\AiReadiness
 */

declare(strict_types=1);

namespace Caidance\AiReadiness\Fixer;

use Caidance\AiReadiness\Admin\SettingsPage;

final class LlmsTxtContentBuilder
{
    private const MAX_PAGES = 8;
    private const MAX_POSTS = 3;

    /**
     * WordPress default taglines that carry no information — skipped
     * rather than published to AI agents.
     */
    private const PLACEHOLDER_TAGLINES = [
        'just another wordpress site',
        '',
    ];

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
            $lines[] = '- [' . $page['title'] . '](' . $page['url'] . ')';
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
     * Published pages by menu order, capped at MAX_PAGES.
     *
     * @return array<int, array{title: string, url: string}>
     */
    private function keyPages(): array
    {
        $pages = get_pages([
            'sort_column' => 'menu_order,post_title',
            'post_status' => 'publish',
            'number'      => self::MAX_PAGES,
        ]);

        if (!is_array($pages)) {
            return [];
        }

        $items = [];
        foreach ($pages as $page) {
            $title = $this->cleanLine((string) get_the_title($page));
            $url   = (string) get_permalink($page);
            if ($title === '' || $url === '') {
                continue;
            }
            $items[] = ['title' => $title, 'url' => $url];
        }

        return $items;
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
