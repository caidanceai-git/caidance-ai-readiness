<?php
/**
 * Checks for Open Graph + Twitter Card meta tags on homepage and a
 * sample post.
 *
 * OG tags drive how AI agents (and social platforms) generate previews
 * when surfacing your site. The minimum useful set is:
 *   og:title, og:description, og:image, twitter:card
 *
 * Coverage strategy: check the homepage AND the most recent post. If
 * a site has no posts, the homepage check stands alone.
 *
 *   pass    = ALL 4 tags present on both pages (or homepage only if no posts)
 *   partial = SOME tags present
 *   fail    = Most or all tags missing
 *
 * @package Caidance\AiReadiness
 */

declare(strict_types=1);

namespace Caidance\AiReadiness\Scanner\Checks;

use Caidance\AiReadiness\Html\HtmlMetaExtractor;
use Caidance\AiReadiness\Scanner\CheckResult;

final class OpenGraphCheck extends AbstractCheck
{
    private const REQUIRED_KEYS = [
        'og:title',
        'og:description',
        'og:image',
        'twitter:card',
    ];

    public function id(): string
    {
        return 'open_graph';
    }

    public function label(): string
    {
        return 'Open Graph + Twitter cards';
    }

    public function run(): CheckResult
    {
        $pagesChecked     = 0;
        $totalKeysFound   = 0;
        $totalKeysNeeded  = 0;
        $pagesWithAllKeys = 0;
        $blockedFetches   = 0;

        foreach ($this->urlsToCheck() as $url) {
            $resp = $this->fetcher->get($url);
            if (!$resp['ok']) {
                if ($this->fetchLooksBlocked($resp)) {
                    $blockedFetches++;
                }
                continue;
            }

            $pagesChecked++;
            $metas        = HtmlMetaExtractor::extractMetas($resp['body']);
            $foundOnPage  = 0;

            foreach (self::REQUIRED_KEYS as $key) {
                if (isset($metas[$key]) && trim($metas[$key]) !== '') {
                    $foundOnPage++;
                    $totalKeysFound++;
                }
                $totalKeysNeeded++;
            }

            if ($foundOnPage === count(self::REQUIRED_KEYS)) {
                $pagesWithAllKeys++;
            }
        }

        if ($pagesChecked === 0) {
            if ($blockedFetches > 0) {
                return $this->unverified(
                    'Could not verify Open Graph tags: the scan requests appear to be blocked by your firewall or CDN, so this check is excluded from your score.'
                );
            }
            return $this->fail(
                'Could not fetch your homepage to inspect Open Graph tags.',
                'Verify your homepage is publicly accessible.'
            );
        }

        if ($pagesWithAllKeys === $pagesChecked) {
            return $this->pass(
                sprintf(
                    'All %d checked pages have complete Open Graph + Twitter card tags. AI agents can generate clean previews.',
                    $pagesChecked
                )
            );
        }

        if ($totalKeysFound === 0) {
            return $this->fail(
                'No Open Graph or Twitter card tags found on your homepage or recent post.',
                'Enable Open Graph in your SEO plugin (Yoast → Settings → Site features → Social; Rank Math → Titles & Meta → Social).'
            );
        }

        $missingCount = $totalKeysNeeded - $totalKeysFound;
        return $this->partial(
            sprintf(
                'Open Graph tags are partially present — %d of %d required tags missing across the %d checked pages.',
                $missingCount,
                $totalKeysNeeded,
                $pagesChecked
            ),
            'Ensure og:title, og:description, og:image, and twitter:card are all present site-wide. SEO plugins handle this once social settings are enabled.'
        );
    }

    /**
     * Returns homepage + most recent published post URL (if any).
     *
     * @return array<int, string>
     */
    private function urlsToCheck(): array
    {
        $urls = [$this->fetcher->homeUrl()];

        $posts = get_posts([
            'post_type'   => 'post',
            'post_status' => 'publish',
            'numberposts' => 1,
            'orderby'     => 'date',
            'order'       => 'DESC',
        ]);

        if ($posts !== []) {
            $permalink = (string) get_permalink($posts[0]);
            if ($permalink !== '') {
                $urls[] = $permalink;
            }
        }

        return array_values(array_unique($urls));
    }
}
