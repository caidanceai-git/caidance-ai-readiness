<?php
/**
 * admin-post.php handlers for applying and reverting fixes.
 *
 * Thin, fully generic transport layer: capability check + per-fix nonce,
 * resolve the fix through FixRegistry, delegate, stash the result in a
 * short per-user transient, redirect back to the Tools page (which
 * renders the notice). No fix logic lives here — each fixer owns every
 * guard and every write.
 *
 * @package Caidance\AiReadiness
 */

declare(strict_types=1);

namespace Caidance\AiReadiness\Admin;

use Caidance\AiReadiness\Fixer\FixInterface;
use Caidance\AiReadiness\Fixer\FixRegistry;

final class FixActions
{
    public const APPLY_ACTION  = 'caidance_air_apply_fix';
    public const REVERT_ACTION = 'caidance_air_revert_fix';

    private const NOTICE_TTL_SECONDS = 60;

    public function register(): void
    {
        add_action('admin_post_' . self::APPLY_ACTION, [$this, 'handleApply']);
        add_action('admin_post_' . self::REVERT_ACTION, [$this, 'handleRevert']);
    }

    /**
     * Per-user transient key carrying the last fix result to the Tools
     * page notice (rich messages never travel via the URL).
     */
    public static function noticeKey(): string
    {
        return 'caidance_air_fix_notice_' . get_current_user_id();
    }

    public function handleApply(): void
    {
        $fix = $this->authorize(self::APPLY_ACTION);
        $this->finish($fix->apply());
    }

    public function handleRevert(): void
    {
        $fix = $this->authorize(self::REVERT_ACTION);
        $this->finish($fix->revert());
    }

    /**
     * Capability + fix resolution + per-fix nonce. Dies on any failure.
     */
    private function authorize(string $action): FixInterface
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage fixes on this site.', 'caidance-ai-readiness'));
        }

        $fixId = isset($_POST['fix']) ? sanitize_key((string) wp_unslash($_POST['fix'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified immediately below, scoped to this fix id.
        $fix   = FixRegistry::get($fixId);
        if ($fix === null) {
            wp_die(esc_html__('Unknown fix.', 'caidance-ai-readiness'));
        }

        check_admin_referer($action . '_' . $fix->id());

        return $fix;
    }

    /**
     * @param array{ok: bool, code: string, message: string, score_before: int|null, score_after: int|null} $result
     */
    private function finish(array $result): void
    {
        set_transient(self::noticeKey(), $result, self::NOTICE_TTL_SECONDS);
        wp_safe_redirect(add_query_arg(['page' => ToolsPage::MENU_SLUG], admin_url('tools.php')));
        exit;
    }
}
