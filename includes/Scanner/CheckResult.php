<?php
/**
 * Immutable result of a single AI-readiness check.
 *
 * Status maps to score: pass=6, partial=3, fail=0. With 10 checks, the
 * max possible score is 60 — matching the CDI scale used across Caidance.
 *
 * @package Caidance\AiReadiness
 */

declare(strict_types=1);

namespace Caidance\AiReadiness\Scanner;

final class CheckResult
{
    public const STATUS_PASS    = 'pass';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_FAIL    = 'fail';

    private const SCORE_BY_STATUS = [
        self::STATUS_PASS    => 6,
        self::STATUS_PARTIAL => 3,
        self::STATUS_FAIL    => 0,
    ];

    public function __construct(
        public readonly string $checkId,
        public readonly string $checkLabel,
        public readonly string $status,
        public readonly string $explanation,
        public readonly string $fixHint = ''
    ) {
        if (!isset(self::SCORE_BY_STATUS[$status])) {
            throw new \InvalidArgumentException(
                'CheckResult: unknown status "' . esc_html($status) . '"'
            );
        }
    }

    public function score(): int
    {
        return self::SCORE_BY_STATUS[$this->status];
    }

    /**
     * @return array{checkId: string, checkLabel: string, status: string, score: int, explanation: string, fixHint: string}
     */
    public function toArray(): array
    {
        return [
            'checkId'     => $this->checkId,
            'checkLabel'  => $this->checkLabel,
            'status'      => $this->status,
            'score'       => $this->score(),
            'explanation' => $this->explanation,
            'fixHint'     => $this->fixHint,
        ];
    }
}
