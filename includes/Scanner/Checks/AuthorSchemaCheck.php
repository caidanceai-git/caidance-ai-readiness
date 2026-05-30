<?php
/**
 * Checks recent blog posts for structured Person authorship.
 *
 * AI agents weight content trust by author identity. A post whose
 * author is a structured Person schema (ideally with name + url to a
 * profile) signals E-E-A-T. A post whose author is a bare string
 * literal scores worse — AI agents see the name but cannot resolve a
 * profile.
 *
 * Reuses the same 3 recent posts as ArticleSchemaCheck. SiteFetcher
 * caches each post URL so this check makes zero additional HTTP
 * requests when run in the same scan.
 *
 *   pass    = ALL sampled posts have a Person author
 *   partial = SOME sampled posts have a Person author, the rest are
 *             string-only
 *   partial = All authors are string-only (named but not structured)
 *   partial = No published posts (cannot evaluate)
 *   fail    = Posts have Article schema but no author info, OR no
 *             Article schema at all
 *
 * @package Caidance\AiReadiness
 */

declare(strict_types=1);

namespace Caidance\AiReadiness\Scanner\Checks;

use Caidance\AiReadiness\Html\JsonLdExtractor;
use Caidance\AiReadiness\Scanner\CheckResult;

final class AuthorSchemaCheck extends AbstractCheck
{
    private const SAMPLE_SIZE = 3;
    private const ARTICLE_TYPES = ['Article', 'BlogPosting', 'NewsArticle'];

    public function id(): string
    {
        return 'author_schema';
    }

    public function label(): string
    {
        return 'Author / Person schema on posts';
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
                'No published blog posts found, so Author schema cannot be checked.',
                'When you publish blog posts, ensure each has a structured Person author with name and url to a profile page.'
            );
        }

        $articleSampled    = 0;
        $personAuthors     = 0;
        $stringOnlyAuthors = 0;

        foreach ($posts as $post) {
            $permalink = (string) get_permalink($post);
            if ($permalink === '') {
                continue;
            }

            $resp = $this->fetcher->get($permalink);
            if (!$resp['ok']) {
                continue;
            }

            $nodes    = JsonLdExtractor::extract($resp['body']);
            $articles = $this->findArticleNodes($nodes);

            if ($articles === []) {
                continue;
            }

            $articleSampled++;
            $authorState = $this->classifyAuthor($articles[0], $nodes);

            if ($authorState === 'person') {
                $personAuthors++;
            } elseif ($authorState === 'string') {
                $stringOnlyAuthors++;
            }
        }

        if ($articleSampled === 0) {
            return $this->fail(
                'No Article schema was found on recent posts to inspect for author. Fix Article schema first.',
                'Enable Article schema in your SEO plugin (see the Article Schema check). Author schema sits inside Article schema.'
            );
        }

        if ($personAuthors === $articleSampled) {
            return $this->pass(
                sprintf(
                    'Structured Person author found on every sampled post (%d checked). Strong E-E-A-T signals.',
                    $articleSampled
                )
            );
        }

        if ($personAuthors > 0) {
            return $this->partial(
                sprintf(
                    '%d of %d sampled posts have a Person author. The rest use a string author name only.',
                    $personAuthors,
                    $articleSampled
                ),
                'Ensure every post author is published as Person schema with at least name and url. Some SEO plugins do this automatically when authors have a populated profile.'
            );
        }

        if ($stringOnlyAuthors > 0) {
            return $this->partial(
                'Authors are named as plain strings, not as Person schema. AI agents see the name but cannot resolve an author profile.',
                'Upgrade to Person schema for authors. Each Person should have a name and ideally a url linking to a profile or about page.'
            );
        }

        return $this->fail(
            'Articles are present but have no author information at all.',
            'Add author information to each post — at minimum a name, ideally a Person schema with a profile URL.'
        );
    }

    /**
     * Returns 'person', 'string', or 'none' based on the article's
     * author shape.
     *
     * @param array<string, mixed> $article
     * @param array<int, array<string, mixed>> $allNodes
     */
    private function classifyAuthor(array $article, array $allNodes): string
    {
        $author = $article['author'] ?? null;

        if (is_string($author) && trim($author) !== '') {
            return 'string';
        }

        if (!is_array($author)) {
            return 'none';
        }

        // Inline author with @type.
        if (isset($author['@type'])) {
            if ($this->typeIncludes($author['@type'], 'Person')) {
                return 'person';
            }
            // Has @type but not Person (e.g. Organization-as-author). Treat
            // as structured but downgrade to 'string'-equivalent since it
            // isn't the Person profile signal AI agents weight.
            return 'string';
        }

        // Author is a {@id: "..."} reference. Resolve against the @graph.
        if (isset($author['@id'])) {
            $refId = (string) $author['@id'];
            foreach (JsonLdExtractor::findByType($allNodes, 'Person') as $personNode) {
                if (isset($personNode['@id']) && (string) $personNode['@id'] === $refId) {
                    return 'person';
                }
            }
        }

        // Array-of-authors shape: check each.
        foreach ($author as $key => $candidate) {
            if (is_string($key)) {
                continue; // Not an indexed list, already handled above.
            }
            if (is_array($candidate)) {
                if (isset($candidate['@type']) && $this->typeIncludes($candidate['@type'], 'Person')) {
                    return 'person';
                }
                if (isset($candidate['@id'])) {
                    $refId = (string) $candidate['@id'];
                    foreach (JsonLdExtractor::findByType($allNodes, 'Person') as $personNode) {
                        if (isset($personNode['@id']) && (string) $personNode['@id'] === $refId) {
                            return 'person';
                        }
                    }
                }
            }
        }

        return 'none';
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
     * @param mixed $type Could be a string or array of strings.
     */
    private function typeIncludes(mixed $type, string $expected): bool
    {
        $target = strtolower($expected);
        if (is_string($type)) {
            return strtolower($type) === $target;
        }
        if (is_array($type)) {
            foreach ($type as $value) {
                if (strtolower((string) $value) === $target) {
                    return true;
                }
            }
        }
        return false;
    }
}
