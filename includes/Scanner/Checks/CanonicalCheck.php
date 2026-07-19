<?php
/**
 * Checks <link rel="canonical"> on homepage + sample post.
 *
 * Canonical tags tell AI agents which URL is the authoritative one for
 * a piece of content. A missing or mismatched canonical lets AI agents
 * pick the "wrong" URL to cite (a tag archive, a tracking-parameter
 * variant, an http: version, a trailing-slash mismatch).
 *
 * Normalization for the URL match:
 *   - Compare without trailing slashes
 *   - Compare case-insensitive on scheme + host
 *   - Strip query strings and fragments before comparison
 *
 *   pass    = Canonical present on both pages AND both match their URL
 *   partial = Canonical present but mismatched on at least one page
 *   fail    = Canonical missing on at least one page
 *
 * @package Caidance\AiReadiness
 */

declare(strict_types=1);

namespace Caidance\AiReadiness\Scanner\Checks;

use Caidance\AiReadiness\Html\HtmlMetaExtractor;
use Caidance\AiReadiness\Scanner\CheckResult;

final class CanonicalCheck extends AbstractCheck
{
    public function id(): string
    {
        return 'canonical';
    }

    public function label(): string
    {
        return 'Canonical tags';
    }

    public function run(): CheckResult
    {
        $checked        = 0;
        $missing        = 0;
        $mismatched     = 0;
        $mismatchUrl    = '';
        $blockedFetches = 0;

        foreach ($this->urlsToCheck() as $url) {
            $resp = $this->fetcher->get($url);
            if (!$resp['ok']) {
                if ($this->fetchLooksBlocked($resp)) {
                    $blockedFetches++;
                }
                continue;
            }

            $checked++;
            $canonical = HtmlMetaExtractor::extractCanonical($resp['body']);

            if ($canonical === null || trim($canonical) === '') {
                $missing++;
                continue;
            }

            if (!$this->canonicalMatchesUrl($canonical, $url)) {
                $mismatched++;
                if ($mismatchUrl === '') {
                    $mismatchUrl = $url;
                }
            }
        }

        if ($checked === 0) {
            if ($blockedFetches > 0) {
                return $this->unverified(
                    'Could not verify canonical tags: the scan requests appear to be blocked by your firewall or CDN, so this check is excluded from your score.'
                );
            }
            return $this->fail(
                'Could not fetch any pages to inspect canonical tags.',
                'Verify your homepage is publicly accessible.'
            );
        }

        if ($missing > 0) {
            return $this->fail(
                sprintf(
                    'Canonical tag is missing on %d of %d checked pages. AI agents may cite the wrong URL for your content.',
                    $missing,
                    $checked
                ),
                'Ensure every page emits <link rel="canonical"> pointing at its own URL. SEO plugins do this automatically.'
            );
        }

        if ($mismatched > 0) {
            return $this->partial(
                sprintf(
                    'Canonical tags are present on all %d pages but %d point at a different URL than the rendered page. (Example: %s)',
                    $checked,
                    $mismatched,
                    $mismatchUrl
                ),
                'Verify your canonical strategy. Canonicals should point at the rendered URL unless the page is intentionally duplicated content.'
            );
        }

        return $this->pass(
            sprintf(
                'Canonical tags are clean on all %d checked pages. AI agents can cite the right URLs.',
                $checked
            )
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

    /**
     * Returns true if the canonical URL points at the same resource as
     * $url. Normalizes scheme + host + path for comparison.
     */
    private function canonicalMatchesUrl(string $canonical, string $url): bool
    {
        $a = $this->normalizeUrlForCompare($canonical);
        $b = $this->normalizeUrlForCompare($url);
        return $a !== '' && $a === $b;
    }

    private function normalizeUrlForCompare(string $url): string
    {
        $parts = wp_parse_url($url);
        if (!is_array($parts)) {
            return '';
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host   = strtolower((string) ($parts['host'] ?? ''));
        $path   = rtrim((string) ($parts['path'] ?? ''), '/');
        if ($path === '') {
            $path = '/';
        }
        return $scheme . '://' . $host . $path;
    }
}
