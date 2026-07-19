<?php
/**
 * Decides whether the scanner itself is being blocked by the site's own
 * firewall/CDN (bot protection challenging the loopback HTTP fetches),
 * as opposed to the site genuinely missing the signals being checked.
 *
 * Found by dogfooding: Cloudflare bot protection challenged the plugin's
 * own fetches (cf-mitigated: challenge + HTTP 403), every fetch-dependent
 * check reported "cannot fetch", and a 48/60 Leader site collapsed to a
 * 6/60 Starter with no content change. Blocked is not the same as
 * failing — this detector is how the scanner tells them apart.
 *
 * Three signals, checked in order of confidence:
 *   1. Challenge signature — the homepage or robots.txt response carries
 *      a cf-mitigated header or a known challenge-page body (matched by
 *      SiteFetcher at fetch time).
 *   2. Contrast — robots.txt is reachable while the homepage is not:
 *      a firewall filtering page requests from automated clients.
 *   3. History — the most recent non-blocked scan proves pages were
 *      fetchable (page-dependent checks scored), and now the homepage
 *      fetch fails: a sudden all-fetch failure is treated as blockage,
 *      not as regression.
 *
 * An authoritative not-found on the homepage (404/410) never counts as
 * blockage — a challenge would have intercepted the request before the
 * origin could answer.
 *
 * Local-only: works entirely from responses the scan already fetched
 * plus stored scan history. No extra HTTP requests, no external calls.
 *
 * @package Caidance\AiReadiness
 */

declare(strict_types=1);

namespace Caidance\AiReadiness\Scanner;

use Caidance\AiReadiness\Http\SiteFetcher;

final class BlockageDetector
{
    /**
     * Checks whose pass/partial status can only be reached after a page
     * was successfully fetched — proof, in a prior scan, that page
     * fetches used to work.
     */
    private const PAGE_FETCH_CHECKS = [
        'organization_schema',
        'website_schema',
        'open_graph',
        'canonical',
    ];

    /**
     * Returns a plain-English evidence sentence when the scanner looks
     * blocked, or '' when it does not. Reads the homepage and robots.txt
     * through the fetcher's cache — both are fetched by the checks
     * anyway, so this adds no extra HTTP requests to the scan.
     *
     * @param array<int, array<string, mixed>> $history Stored scans, newest first.
     */
    public static function evaluate(SiteFetcher $fetcher, array $history): string
    {
        $home   = $fetcher->get($fetcher->homeUrl());
        $robots = $fetcher->get($fetcher->urlFor('/robots.txt'));

        // Homepage readable → the scanner is not blocked at scan level.
        // (Path-scoped challenges on other URLs are still caught per
        // response via challenge_signal.)
        if (!empty($home['ok'])) {
            return '';
        }

        $homeCode = (int) ($home['status_code'] ?? 0);

        $homeSignal = (string) ($home['challenge_signal'] ?? '');
        if ($homeSignal !== '') {
            return 'The homepage fetch was challenged: ' . $homeSignal . '.';
        }

        $robotsSignal = (string) ($robots['challenge_signal'] ?? '');
        if ($robotsSignal !== '') {
            return 'The robots.txt fetch was challenged: ' . $robotsSignal . '.';
        }

        // 404/410 means the origin answered — not a challenge.
        if (in_array($homeCode, [404, 410], true)) {
            return '';
        }

        if (!empty($robots['ok'])) {
            return sprintf(
                'robots.txt is reachable but the homepage fetch fails (%s) — a firewall or CDN appears to be filtering page requests from automated clients.',
                $homeCode > 0 ? 'HTTP ' . $homeCode : 'connection error'
            );
        }

        if (self::pagesWereFetchableRecently($history)) {
            return sprintf(
                'Every fetch suddenly fails (%s) although the previous scan could read these same pages — treated as blockage, not as regression.',
                $homeCode > 0 ? 'HTTP ' . $homeCode : 'connection error'
            );
        }

        return '';
    }

    /**
     * True when the most recent scan that was not itself blocked has at
     * least one page-dependent check at pass or partial — statuses those
     * checks can only reach after successfully fetching a page.
     *
     * Only the newest non-blocked scan counts as the reference: older
     * scans may predate real site changes, and a run of consecutive
     * blocked scans should not erase the evidence from before them.
     *
     * @param array<int, array<string, mixed>> $history Stored scans, newest first.
     */
    private static function pagesWereFetchableRecently(array $history): bool
    {
        foreach ($history as $scan) {
            if (!is_array($scan)) {
                continue;
            }
            if (!empty($scan['scanner_blocked'])) {
                continue; // A blocked scan proves nothing either way.
            }
            foreach (($scan['results'] ?? []) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if (in_array((string) ($row['checkId'] ?? ''), self::PAGE_FETCH_CHECKS, true)
                    && in_array((string) ($row['status'] ?? ''), [CheckResult::STATUS_PASS, CheckResult::STATUS_PARTIAL], true)
                ) {
                    return true;
                }
            }
            return false;
        }
        return false;
    }
}
