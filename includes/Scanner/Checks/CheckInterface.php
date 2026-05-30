<?php
/**
 * Contract for a single AI-readiness check.
 *
 * Implementations are registered with LocalScanner. Each is responsible
 * for one specific aspect of AI-readiness and returns a CheckResult
 * describing what it found.
 *
 * @package Caidance\AiReadiness
 */

declare(strict_types=1);

namespace Caidance\AiReadiness\Scanner\Checks;

use Caidance\AiReadiness\Scanner\CheckResult;

interface CheckInterface
{
    /**
     * Stable string identifier for this check (e.g. 'llms_txt').
     * Used to key results in persistence and in the UI.
     */
    public function id(): string;

    /**
     * Human-readable label for this check, shown in the Tools page readout.
     */
    public function label(): string;

    /**
     * Run the check against the active site and return its CheckResult.
     */
    public function run(): CheckResult;
}
