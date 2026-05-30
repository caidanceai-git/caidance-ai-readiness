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
     * Per-instance cache of fetch results, keyed by URL.
     *
     * @var array<string, array{url: string, status_code: int, body: string, error: string, ok: bool}>
     */
    private array $cache = [];

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
     * @return array{url: string, status_code: int, body: string, error: string, ok: bool}
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
                'url'         => $url,
                'status_code' => 0,
                'body'        => '',
                'error'       => (string) $response->get_error_message(),
                'ok'          => false,
            ];
        } else {
            $code = (int) wp_remote_retrieve_response_code($response);
            $body = (string) wp_remote_retrieve_body($response);
            $result = [
                'url'         => $url,
                'status_code' => $code,
                'body'        => $body,
                'error'       => '',
                'ok'          => $code >= 200 && $code < 300,
            ];
        }

        $this->cache[$url] = $result;
        return $result;
    }
}
