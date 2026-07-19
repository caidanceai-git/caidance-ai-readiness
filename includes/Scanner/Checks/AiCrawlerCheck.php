<?php
/**
 * Checks whether robots.txt allows the major AI crawlers from the site root.
 *
 * The five user-agents most commonly cited by AI vendors:
 *   GPTBot          (OpenAI training)
 *   ClaudeBot       (Anthropic)
 *   PerplexityBot   (Perplexity)
 *   OAI-SearchBot   (OpenAI search/ChatGPT browsing)
 *   Google-Extended (Google AI / Gemini training)
 *
 * Some sites deliberately block these for privacy or contractual reasons.
 * The user can toggle this check off in Settings — in that case it does
 * not run at all (LocalScanner::buildDefault handles the opt-out).
 *
 *   pass    = all 5 crawlers are allowed (or robots.txt is missing)
 *   partial = 1–2 are blocked from /
 *   fail    = 3+ are blocked from /
 *
 * Parser is intentionally conservative: it only flags an explicit
 * "Disallow: /" inside the named user-agent's block. Path-specific
 * disallows do not count as a full block.
 *
 * @package Caidance\AiReadiness
 */

declare(strict_types=1);

namespace Caidance\AiReadiness\Scanner\Checks;

use Caidance\AiReadiness\Scanner\CheckResult;

final class AiCrawlerCheck extends AbstractCheck
{
    private const CRAWLERS = [
        'GPTBot',
        'ClaudeBot',
        'PerplexityBot',
        'OAI-SearchBot',
        'Google-Extended',
    ];

    public function id(): string
    {
        return 'ai_crawler_access';
    }

    public function label(): string
    {
        return 'AI crawlers are allowed';
    }

    public function run(): CheckResult
    {
        $robots = $this->fetcher->get($this->fetcher->urlFor('/robots.txt'));

        if (!$robots['ok']) {
            // A blocked fetch proves nothing about robots.txt — without
            // this test, a firewall challenge here would count as a
            // spurious pass (the "everything allowed" branch below).
            if ($this->fetchLooksBlocked($robots)) {
                return $this->unverified(
                    'Could not verify AI crawler access: the robots.txt scan request appears to be blocked by your firewall or CDN, so this check is excluded from your score.'
                );
            }

            // No robots.txt means everything is allowed by default per the spec.
            return $this->pass(
                'No robots.txt is served, so all crawlers — including AI crawlers — are allowed by default.'
            );
        }

        $blocked = [];
        foreach (self::CRAWLERS as $crawler) {
            if ($this->isCrawlerDisallowedFromRoot($robots['body'], $crawler)) {
                $blocked[] = $crawler;
            }
        }

        $blockedCount = count($blocked);
        $totalCount   = count(self::CRAWLERS);

        if ($blockedCount === 0) {
            return $this->pass(
                sprintf(
                    'All %d major AI crawlers are allowed in robots.txt.',
                    $totalCount
                )
            );
        }

        $blockedList = implode(', ', $blocked);

        if ($blockedCount <= 2) {
            return $this->partial(
                sprintf(
                    '%d of %d major AI crawlers are blocked from your site root: %s.',
                    $blockedCount,
                    $totalCount,
                    $blockedList
                ),
                'If the blocks are intentional, turn this check off in Settings. Otherwise, remove the Disallow: / rules for these user-agents in robots.txt.'
            );
        }

        return $this->fail(
            sprintf(
                '%d of %d major AI crawlers are blocked from your site root: %s.',
                $blockedCount,
                $totalCount,
                $blockedList
            ),
            'If the blocks are intentional, turn this check off in Settings. Otherwise, remove the Disallow: / rules for these user-agents in robots.txt.'
        );
    }

    /**
     * Returns true if robots.txt explicitly blocks the given crawler from
     * the site root via "Disallow: /" inside its own User-agent block.
     *
     * Block-level only — path-specific disallows (e.g. Disallow: /admin/)
     * do not count as a full block.
     */
    private function isCrawlerDisallowedFromRoot(string $robotsBody, string $crawler): bool
    {
        $lines   = explode("\n", $robotsBody);
        $inBlock = false;

        foreach ($lines as $rawLine) {
            $line = trim($rawLine);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (stripos($line, 'User-agent:') === 0) {
                $ua      = trim(substr($line, strlen('User-agent:')));
                $inBlock = (strcasecmp($ua, $crawler) === 0);
                continue;
            }

            if (!$inBlock) {
                continue;
            }

            if (stripos($line, 'Disallow:') === 0) {
                $path = trim(substr($line, strlen('Disallow:')));
                if ($path === '/') {
                    return true;
                }
            }
        }

        return false;
    }
}
