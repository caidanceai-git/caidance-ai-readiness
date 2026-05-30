<?php
/**
 * Checks recent blog posts for Article/BlogPosting/NewsArticle schema
 * with author and datePublished.
 *
 * Article schema is what gets blog content cited by AI agents — the
 * combination of structured authorship and publication date is the
 * baseline trust signal.
 *
 * Samples up to 3 most recent published posts. If a site has fewer
 * posts, samples what exists. SiteFetcher caches each post URL so
 * AuthorSchemaCheck reuses the fetched HTML for free.
 *
 *   pass    = ALL sampled posts have Article schema with author + datePublished
 *   partial = SOME sampled posts have complete Article schema
 *   partial = No published posts (cannot evaluate)
 *   fail    = No sampled post has Article schema at all
 *
 * @package Caidance\AiReadiness
 */

declare(strict_types=1);

namespace Caidance\AiReadiness\Scanner\Checks;

use Caidance\AiReadiness\Html\JsonLdExtractor;
use Caidance\AiReadiness\Scanner\CheckResult;

final class ArticleSchemaCheck extends AbstractCheck
{
    private const SAMPLE_SIZE = 3;
    private const ARTICLE_TYPES = ['Article', 'BlogPosting', 'NewsArticle'];

    public function id(): string
    {
        return 'article_schema';
    }

    public function label(): string
    {
        return 'Article schema on recent posts';
    }

    public function run(): CheckResult
    {
        $posts = get_posts([
            'post_type'   => 'post',
            'post_status' => 'publish',
            'numberposts' => self::SAMPLE_SIZE,
            'orderby'     => 'date',
            'order'       => 'DESC',
        ]);

        if ($posts === []) {
            return $this->partial(
                'No published blog posts found, so Article schema cannot be checked.',
                'When you publish blog posts, ensure each has Article or BlogPosting schema with author and datePublished. Most SEO plugins handle this automatically.'
            );
        }

        $sampled         = 0;
        $withCompleteSchema = 0;

        foreach ($posts as $post) {
            $permalink = (string) get_permalink($post);
            if ($permalink === '') {
                continue;
            }

            $resp = $this->fetcher->get($permalink);
            if (!$resp['ok']) {
                continue;
            }

            $sampled++;
            $nodes    = JsonLdExtractor::extract($resp['body']);
            $articles = $this->findArticleNodes($nodes);

            if ($articles === []) {
                continue;
            }

            $first = $articles[0];
            $hasAuthor = $this->hasNonEmpty($first, 'author');
            $hasDate   = $this->hasNonEmpty($first, 'datePublished');

            if ($hasAuthor && $hasDate) {
                $withCompleteSchema++;
            }
        }

        if ($sampled === 0) {
            return $this->fail(
                'Unable to fetch any of your recent posts to inspect schema.',
                'Verify your post URLs are publicly accessible.'
            );
        }

        if ($withCompleteSchema === $sampled) {
            return $this->pass(
                sprintf(
                    'Article schema with author and datePublished is present on every sampled recent post (%d checked). AI agents can cite your blog content.',
                    $sampled
                )
            );
        }

        if ($withCompleteSchema > 0) {
            return $this->partial(
                sprintf(
                    'Article schema is incomplete: %d of %d sampled recent posts have author and datePublished. The rest are missing one or both.',
                    $withCompleteSchema,
                    $sampled
                ),
                'Ensure every published post has Article (or BlogPosting) schema with author and datePublished. SEO plugins handle this once enabled site-wide.'
            );
        }

        return $this->fail(
            sprintf(
                'No sampled recent posts have complete Article schema (%d checked).',
                $sampled
            ),
            'Enable Article schema in your SEO plugin (Yoast → Search Appearance → Content Types → Posts; Rank Math → Titles & Meta → Posts).'
        );
    }

    /**
     * Returns nodes typed Article OR BlogPosting OR NewsArticle.
     *
     * @param array<int, array<string, mixed>> $nodes
     * @return array<int, array<string, mixed>>
     */
    private function findArticleNodes(array $nodes): array
    {
        $hits = [];
        foreach (self::ARTICLE_TYPES as $type) {
            foreach (JsonLdExtractor::findByType($nodes, $type) as $node) {
                $hits[] = $node;
            }
        }
        return $hits;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function hasNonEmpty(array $node, string $key): bool
    {
        if (!isset($node[$key])) {
            return false;
        }
        $value = $node[$key];
        if (is_string($value)) {
            return trim($value) !== '';
        }
        if (is_array($value)) {
            return $value !== [];
        }
        return $value !== null;
    }
}
