<?php
/**
 * Thin wrapper around wp_remote_get() with per-instance response caching.
 *
 * Multiple checks may need the same URL within a single scan run (e.g.
 * RobotsSitemapCheck and AiCrawlerCheck both read /robots.txt). The
 * cache lives only for the lifetime of a single SiteFetcher instance, so
 * subsequent scans get fresh data — we never want to serve stale cached
 * results across runs.
 *
 * Every response is also inspected for a firewall/CDN bot-challenge
 * signature (the cf-mitigated header Cloudflare attaches when it
 * mitigates a request, or a known challenge-page body on a non-2xx
 * response). The signature rides along in the result as
 * challenge_signal, and LocalScanner can flag the whole scan as
 * scanner-blocked via flagScannerBlocked() — checks consult both to
 * report "unverified" instead of a false "fail".
 *
 * Response shape is an associative array (not a value object) to keep
 * the public surface narrow and the file count for this batch tight.
 *
 * @package Caidance\AiReadiness
 */

declare(strict_types=1);

namespace Caidance\AiReadiness\Http;

final class SiteFetcher
{
    private const TIMEOUT_SECONDS = 8;
    private const USER_AGENT      = 'Caidance-AiReadiness-Scanner/1.0 (+https://caidance.ai/)';

    /**
     * Body substrings that identify a firewall/CDN bot-challenge page,
     * mapped to the vendor name used in the evidence sentence. Matched
     * case-insensitively, and ONLY on non-2xx responses — a normal page
     * that merely mentions these strings never trips the detector.
     */
    private const CHALLENGE_MARKERS = [
        'cdn-cgi/challenge-platform'       => 'Cloudflare',
        '_cf_chl_'                         => 'Cloudflare',
        'cf-turnstile'                     => 'Cloudflare',
        'just a moment...'                 => 'Cloudflare',
        'attention required! | cloudflare' => 'Cloudflare',
        '_incapsula_resource'              => 'Imperva Incapsula',
        'sucuri_cloudproxy'                => 'Sucuri',
        'captcha-delivery.com'             => 'DataDome',
        'px-captcha'                       => 'PerimeterX',
    ];

    /**
     * Per-instance cache of fetch results, keyed by URL.
     *
     * @var array<string, array{url: string, status_code: int, body: string, error: string, ok: bool, challenge_signal: string}>
     */
    private array $cache = [];

    /**
     * Scan-level blockage verdict, set once by LocalScanner (via
     * BlockageDetector) so every check can consult it. Empty string
     * means the scanner is not known to be blocked.
     */
    private string $blockageReason = '';

    /**
     * Returns the absolute home URL with no trailing slash.
     */
    public function homeUrl(): string
    {
        return untrailingslashit((string) home_url('/'));
    }

    /**
     * Builds an absolute URL for a path on this site (e.g. urlFor('/robots.txt')).
     */
    public function urlFor(string $path): string
    {
        return $this->homeUrl() . '/' . ltrim($path, '/');
    }

    /**
     * Performs (or returns cached) GET on the given URL.
     *
     * @return array{url: string, status_code: int, body: string, error: string, ok: bool, challenge_signal: string}
     */
    public function get(string $url): array
    {
        if (isset($this->cache[$url])) {
            return $this->cache[$url];
        }

        $response = wp_remote_get($url, [
            'timeout'     => self::TIMEOUT_SECONDS,
            'redirection' => 3,
            'user-agent'  => self::USER_AGENT,
            'sslverify'   => true,
        ]);

        if (is_wp_error($response)) {
            $result = [
                'url'              => $url,
                'status_code'      => 0,
                'body'             => '',
                'error'            => (string) $response->get_error_message(),
                'ok'               => false,
                'challenge_signal' => '',
            ];
        } else {
            $code = (int) wp_remote_retrieve_response_code($response);
            $body = (string) wp_remote_retrieve_body($response);
            $result = [
                'url'              => $url,
                'status_code'      => $code,
                'body'             => $body,
                'error'            => '',
                'ok'               => $code >= 200 && $code < 300,
                'challenge_signal' => $this->challengeSignalFor($response, $code, $body),
            ];
        }

        $this->cache[$url] = $result;
        return $result;
    }

    /**
     * Records the scan-level "scanner blocked" verdict. First reason
     * wins; later calls are ignored so the original evidence survives.
     */
    public function flagScannerBlocked(string $reason): void
    {
        if ($this->blockageReason === '' && $reason !== '') {
            $this->blockageReason = $reason;
        }
    }

    /**
     * True when this scan has been flagged as blocked by the site's own
     * firewall/CDN. Checks use it to report unverified instead of fail.
     */
    public function scannerBlocked(): bool
    {
        return $this->blockageReason !== '';
    }

    /**
     * The plain-English evidence sentence behind the blocked verdict,
     * or '' when the scanner is not known to be blocked.
     */
    public function blockageReason(): string
    {
        return $this->blockageReason;
    }

    /**
     * The first challenge signature recorded across all fetches in this
     * scan, or '' when none was seen. Lets LocalScanner surface evidence
     * even when blockage was only discovered mid-scan (e.g. the homepage
     * was readable but post fetches were challenged).
     */
    public function firstChallengeSignal(): string
    {
        foreach ($this->cache as $result) {
            if ($result['challenge_signal'] !== '') {
                return $result['challenge_signal'];
            }
        }
        return '';
    }

    /**
     * Builds the challenge-signature evidence sentence for a response,
     * or '' when the response carries no challenge evidence.
     *
     * Two independent signatures:
     *   1. The cf-mitigated header — Cloudflare attaches it whenever it
     *      mitigates a request (e.g. cf-mitigated: challenge).
     *   2. A known challenge-page marker in a non-2xx body.
     *
     * @param mixed $response The non-WP_Error wp_remote_get() response.
     */
    private function challengeSignalFor(mixed $response, int $code, string $body): string
    {
        $mitigated = wp_remote_retrieve_header($response, 'cf-mitigated');
        if (is_array($mitigated)) {
            $mitigated = implode(', ', array_map('strval', $mitigated));
        }
        $mitigated = trim((string) $mitigated);
        if ($mitigated !== '') {
            return sprintf(
                'Cloudflare answered with its bot-mitigation header (cf-mitigated: %s, HTTP %d)',
                $mitigated,
                $code
            );
        }

        if ($code >= 200 && $code < 300) {
            return '';
        }

        foreach (self::CHALLENGE_MARKERS as $marker => $vendor) {
            if (stripos($body, $marker) !== false) {
                return sprintf('the response is a %s bot-challenge page (HTTP %d)', $vendor, $code);
            }
        }

        return '';
    }
}
