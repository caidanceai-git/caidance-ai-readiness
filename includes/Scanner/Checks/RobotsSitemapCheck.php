<?php
/**
 * Checks that robots.txt declares a Sitemap: directive AND that the
 * declared sitemap URL actually responds.
 *
 * AI agents and search crawlers use the Sitemap directive in robots.txt
 * as the canonical way to discover all of a site's content. A missing
 * sitemap declaration usually means content gets missed.
 *
 *   pass    = robots.txt has a Sitemap: line and the URL responds
 *   partial = robots.txt has a Sitemap: line but the URL is unreachable
 *   fail    = robots.txt has no Sitemap: line, or robots.txt is missing
 *
 * @package Caidance\AiReadiness
 */

declare(strict_types=1);

namespace Caidance\AiReadiness\Scanner\Checks;

use Caidance\AiReadiness\Scanner\CheckResult;

final class RobotsSitemapCheck extends AbstractCheck
{
    public function id(): string
    {
        return 'robots_sitemap';
    }

    public function label(): string
    {
        return 'robots.txt declares a Sitemap';
    }

    public function run(): CheckResult
    {
        $robots = $this->fetcher->get($this->fetcher->urlFor('/robots.txt'));

        if (!$robots['ok']) {
            if ($this->fetchLooksBlocked($robots)) {
                return $this->unverified(
                    'Could not verify robots.txt: the scan request appears to be blocked by your firewall or CDN, so this check is excluded from your score.'
                );
            }
            return $this->fail(
                'Your site does not serve a /robots.txt file, so a Sitemap directive cannot be declared.',
                'Generate a robots.txt that includes a Sitemap: line pointing at your XML sitemap.'
            );
        }

        $sitemapUrls = $this->extractSitemapUrls($robots['body']);

        if ($sitemapUrls === []) {
            return $this->fail(
                'robots.txt is served but does not declare a Sitemap: directive. Crawlers may miss content.',
                'Add a "Sitemap: https://yoursite.com/sitemap.xml" line to robots.txt pointing at your XML sitemap. SEO plugins that replace the WordPress core sitemaps often drop this line without telling you — Caidance can add it back with one click on the Tools page.'
            );
        }

        $reachable      = false;
        $sitemapBlocked = false;
        foreach ($sitemapUrls as $url) {
            $resp = $this->fetcher->get($url);
            if ($resp['ok']) {
                $reachable = true;
                break;
            }
            if ($this->fetchLooksBlocked($resp)) {
                $sitemapBlocked = true;
            }
        }

        if (!$reachable) {
            if ($sitemapBlocked) {
                return $this->unverified(
                    'robots.txt declares a Sitemap, but the scan request for it appears to be blocked by your firewall or CDN, so this check is excluded from your score.'
                );
            }
            return $this->partial(
                'robots.txt declares a Sitemap, but the URL does not respond. AI agents will not be able to fetch it.',
                'Verify your sitemap URL is correct and publicly accessible (no auth, no firewall block).'
            );
        }

        return $this->pass(
            'robots.txt declares a working sitemap. Crawlers can discover all your content.'
        );
    }

    /**
     * Extracts Sitemap: URLs from robots.txt body. Case-insensitive on
     * the directive name per the spec.
     *
     * @return array<int, string>
     */
    private function extractSitemapUrls(string $robotsBody): array
    {
        $urls = [];
        foreach (explode("\n", $robotsBody) as $line) {
            $trimmed = trim($line);
            if (stripos($trimmed, 'Sitemap:') === 0) {
                $url = trim(substr($trimmed, strlen('Sitemap:')));
                if ($url !== '') {
                    $urls[] = $url;
                }
            }
        }
        return $urls;
    }
}
