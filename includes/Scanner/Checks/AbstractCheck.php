<?php
/**
 * Base class for all checks.
 *
 * Provides the SiteFetcher dependency every check needs to do HTTP work,
 * plus convenience methods for building each of the three CheckResult
 * statuses without repeating the id/label boilerplate.
 *
 * @package Caidance\AiReadiness
 */

declare(strict_types=1);

namespace Caidance\AiReadiness\Scanner\Checks;

use Caidance\AiReadiness\Http\SiteFetcher;
use Caidance\AiReadiness\Scanner\CheckResult;

abstract class AbstractCheck implements CheckInterface
{
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
}
