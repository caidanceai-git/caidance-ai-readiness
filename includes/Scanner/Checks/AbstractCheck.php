<?php
/**
 * Base class for all checks.
 *
 * Provides the SiteFetcher dependency every check needs to do HTTP work,
 * plus convenience methods for building each of the four CheckResult
 * statuses without repeating the id/label boilerplate, and the shared
 * fetchLooksBlocked() test that tells a scanner-blocked fetch apart from
 * a genuinely absent resource.
 *
 * @package Caidance\AiReadiness
 */

declare(strict_types=1);

namespace Caidance\AiReadiness\Scanner\Checks;

use Caidance\AiReadiness\Http\SiteFetcher;
use Caidance\AiReadiness\Scanner\CheckResult;

abstract class AbstractCheck implements CheckInterface
{
    /**
     * The shared fix hint for scanner-blocked (unverified) outcomes.
     */
    private const BLOCKED_FIX_HINT = 'Check your firewall or CDN bot settings (for example Cloudflare Bot Fight Mode) or allowlist requests from your own server, then re-run the scan.';

    public function __construct(protected readonly SiteFetcher $fetcher)
    {
    }

    protected function pass(string $explanation): CheckResult
    {
        return new CheckResult(
            $this->id(),
            $this->label(),
            CheckResult::STATUS_PASS,
            $explanation
        );
    }

    protected function partial(string $explanation, string $fixHint = ''): CheckResult
    {
        return new CheckResult(
            $this->id(),
            $this->label(),
            CheckResult::STATUS_PARTIAL,
            $explanation,
            $fixHint
        );
    }

    protected function fail(string $explanation, string $fixHint = ''): CheckResult
    {
        return new CheckResult(
            $this->id(),
            $this->label(),
            CheckResult::STATUS_FAIL,
            $explanation,
            $fixHint
        );
    }

    /**
     * The scanner-blocked outcome: the check could not read what it
     * needed because the scan requests were blocked, so nothing about
     * the site itself was proven. Excluded from the score by
     * LocalScanner — blocked is not the same as failing.
     */
    protected function unverified(string $explanation): CheckResult
    {
        return new CheckResult(
            $this->id(),
            $this->label(),
            CheckResult::STATUS_UNVERIFIED,
            $explanation,
            self::BLOCKED_FIX_HINT
        );
    }

    /**
     * True when a failed fetch looks like the scanner being blocked
     * rather than the resource being absent: the response carried a
     * firewall/CDN challenge signature, or the scan-level blockage
     * verdict is on and the status is not an authoritative not-found
     * (404/410 means the origin answered — a challenge would have
     * intercepted the request before the origin could).
     *
     * @param array{status_code: int, challenge_signal: string} $resp A SiteFetcher::get() result.
     */
    protected function fetchLooksBlocked(array $resp): bool
    {
        if ((string) ($resp['challenge_signal'] ?? '') !== '') {
            return true;
        }
        if (!$this->fetcher->scannerBlocked()) {
            return false;
        }
        return !in_array((int) ($resp['status_code'] ?? 0), [404, 410], true);
    }
}
