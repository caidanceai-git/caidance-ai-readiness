<?php
/**
 * Registry of shipped fixes, keyed by the check id each one repairs.
 *
 * The single place a new fix gets wired. ToolsPage renders panels and
 * FixActions routes apply/revert exclusively through this registry —
 * neither ever names a concrete fixer class.
 *
 * @package Caidance\AiReadiness
 */

declare(strict_types=1);

namespace Caidance\AiReadiness\Fixer;

final class FixRegistry
{
    /**
     * @return array<string, FixInterface> Keyed by check id.
     */
    public static function all(): array
    {
        return [
            LlmsTxtFixer::CHECK_ID        => new LlmsTxtFixer(),
            RobotsAiAccessFixer::CHECK_ID => new RobotsAiAccessFixer(),
        ];
    }

    public static function get(string $checkId): ?FixInterface
    {
        $fixes = self::all();
        return $fixes[$checkId] ?? null;
    }
}
