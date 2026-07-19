<?php
/**
 * Checks for FAQPage JSON-LD schema with substantive Q&A content.
 *
 * AI agents lift FAQPage entries verbatim into their answers, so a
 * well-formed FAQPage is one of the highest-yield AI-visibility moves.
 *
 * Coverage strategy: check the homepage AND any page resolved at the
 * common FAQ slugs (/faq, /faqs, /frequently-asked-questions). First
 * hit wins. We avoid scanning every page for performance.
 *
 *   pass    = FAQPage with ≥3 Question entries
 *   partial = FAQPage with 1–2 Question entries
 *   fail    = No FAQPage schema anywhere we looked
 *
 * @package Caidance\AiReadiness
 */

declare(strict_types=1);

namespace Caidance\AiReadiness\Scanner\Checks;

use Caidance\AiReadiness\Html\JsonLdExtractor;
use Caidance\AiReadiness\Scanner\CheckResult;

final class FaqSchemaCheck extends AbstractCheck
{
    private const COMMON_FAQ_SLUGS = ['faq', 'faqs', 'frequently-asked-questions'];

    public function id(): string
    {
        return 'faq_schema';
    }

    public function label(): string
    {
        return 'FAQPage schema with Q&A entries';
    }

    public function run(): CheckResult
    {
        $urls           = $this->candidateUrls();
        $fetched        = 0;
        $blockedFetches = 0;

        foreach ($urls as $url) {
            $resp = $this->fetcher->get($url);
            if (!$resp['ok']) {
                if ($this->fetchLooksBlocked($resp)) {
                    $blockedFetches++;
                }
                continue;
            }

            $fetched++;

            $nodes    = JsonLdExtractor::extract($resp['body']);
            $faqPages = JsonLdExtractor::findByType($nodes, 'FAQPage');

            if ($faqPages === []) {
                continue;
            }

            $questionCount = $this->countQuestions($faqPages[0]);

            if ($questionCount >= 3) {
                return $this->pass(
                    sprintf(
                        'FAQPage schema found with %d Q&A entries. AI agents can lift answers directly into their responses.',
                        $questionCount
                    )
                );
            }

            return $this->partial(
                sprintf(
                    'FAQPage schema is present but only has %d Question entries. AI agents need at least 3 to consider it substantive.',
                    $questionCount
                ),
                'Add more questions to your FAQ page. Aim for 5–10 of the questions your customers actually ask.'
            );
        }

        if ($fetched === 0 && $blockedFetches > 0) {
            return $this->unverified(
                'Could not verify FAQPage schema: the scan requests appear to be blocked by your firewall or CDN, so this check is excluded from your score.'
            );
        }

        return $this->fail(
            'No FAQPage schema was found on your homepage or at common FAQ URLs (/faq, /faqs, /frequently-asked-questions).',
            'Publish an FAQ page with FAQPage schema. SEO plugins like Yoast and Rank Math include FAQ blocks that emit valid schema.'
        );
    }

    /**
     * Returns the URLs to scan: homepage + any of /faq, /faqs,
     * /frequently-asked-questions that actually resolve to a published
     * page or post.
     *
     * @return array<int, string>
     */
    private function candidateUrls(): array
    {
        $urls = [$this->fetcher->homeUrl()];

        foreach (self::COMMON_FAQ_SLUGS as $slug) {
            $page = get_page_by_path($slug, OBJECT, ['page', 'post']);
            if ($page instanceof \WP_Post) {
                $permalink = (string) get_permalink($page);
                if ($permalink !== '') {
                    $urls[] = $permalink;
                }
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * Counts Question entries in a FAQPage's mainEntity field.
     * mainEntity can be either a single Question OR an array of Questions.
     *
     * @param array<string, mixed> $faqPage
     */
    private function countQuestions(array $faqPage): int
    {
        $main = $faqPage['mainEntity'] ?? null;

        if (!is_array($main)) {
            return 0;
        }

        if (isset($main['@type'])) {
            return $this->isQuestion($main) ? 1 : 0;
        }

        $count = 0;
        foreach ($main as $entity) {
            if (is_array($entity) && $this->isQuestion($entity)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function isQuestion(array $node): bool
    {
        $type = $node['@type'] ?? null;
        if (is_string($type)) {
            return strcasecmp($type, 'Question') === 0;
        }
        if (is_array($type)) {
            foreach ($type as $value) {
                if (strcasecmp((string) $value, 'Question') === 0) {
                    return true;
                }
            }
        }
        return false;
    }
}
