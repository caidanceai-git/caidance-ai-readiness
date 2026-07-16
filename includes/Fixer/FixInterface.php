<?php
/**
 * Contract every Caidance fix implements.
 *
 * A fix repairs exactly one scanner check (id() matches the check's id).
 * The lifecycle is uniform across fixes — status → preview → approve →
 * apply → verify → revert — but each fix owns its own state machine,
 * safety guards, and panel copy. The Tools page and FixActions stay
 * generic: they discover fixes through FixRegistry and never contain
 * fix-specific logic.
 *
 * Safety invariant shared by every implementation: the exact prior
 * state can always be restored, and a fix refuses to touch anything
 * that changed since it touched it.
 *
 * @package Caidance\AiReadiness
 */

declare(strict_types=1);

namespace Caidance\AiReadiness\Fixer;

interface FixInterface
{
    /**
     * The scanner check id this fix repairs (e.g. 'llms_txt').
     */
    public function id(): string;

    /**
     * Short human label for notices and the evidence log.
     */
    public function label(): string;

    /**
     * Side-effect-free state read. May use the stored scan result to
     * avoid live HTTP; apply() must re-validate the world at write time.
     *
     * @param array<string, mixed>|null $latestCheck The stored check row.
     * @return array<string, mixed> At minimum ['state' => string].
     */
    public function status(?array $latestCheck): array;

    /**
     * Whether the Tools page should render a panel for this check row.
     *
     * @param array<string, mixed> $status
     * @param array<string, mixed> $latestCheck
     */
    public function wantsPanel(array $status, array $latestCheck): bool;

    /**
     * Inner panel HTML (forms included). The Tools page provides the
     * outer chrome; the fixer owns the copy for its own states.
     *
     * @param array<string, mixed> $status
     * @param array<string, mixed> $latestCheck
     */
    public function renderPanel(array $status, bool $previewing, array $latestCheck): string;

    /**
     * Applies the fix. Re-validates guards live, writes, verifies,
     * re-scans, records evidence.
     *
     * @return array{ok: bool, code: string, message: string, score_before: int|null, score_after: int|null}
     */
    public function apply(): array;

    /**
     * Reverts the fix — only when the current state still exactly
     * matches what apply() produced.
     *
     * @return array{ok: bool, code: string, message: string, score_before: int|null, score_after: int|null}
     */
    public function revert(): array;
}
