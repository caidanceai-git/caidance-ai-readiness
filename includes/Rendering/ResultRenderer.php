<?php
/**
 * Shared HTML rendering for scan results.
 *
 * Inline styles only — keeps the plugin self-contained without an
 * enqueued CSS file. Uses native WordPress admin classes (notice,
 * button, etc.) where applicable so the result surfaces look at home
 * in wp-admin.
 *
 * All methods static — no instance state.
 *
 * @package Caidance\AiReadiness
 */

declare(strict_types=1);

namespace Caidance\AiReadiness\Rendering;

final class ResultRenderer
{
    private const BAND_PRESENTATION = [
        'starter'  => ['color' => '#b32d2e', 'label' => 'Starter'],
        'builder'  => ['color' => '#d63638', 'label' => 'Builder'],
        'operator' => ['color' => '#2271b1', 'label' => 'Operator'],
        'leader'   => ['color' => '#00a32a', 'label' => 'Leader'],
    ];

    private const STATUS_PRESENTATION = [
        'pass'    => ['color' => '#00a32a', 'label' => 'Pass'],
        'partial' => ['color' => '#dba617', 'label' => 'Partial'],
        'fail'    => ['color' => '#d63638', 'label' => 'Fail'],
    ];

    private const CALCULATOR_BRIDGE_LEADS = [
        'starter'  => 'Sites at the Starter level tend to forfeit the most AI-driven demand — when buyers ask ChatGPT, Claude, or Perplexity for a recommendation, they rarely come up at all.',
        'builder'  => 'Sites at the Builder level are visible to AI tools but easy to pass over — a meaningful share of AI-driven demand likely flows to competitors that read more clearly.',
        'operator' => 'Sites at the Operator level have the fundamentals in place — what is left unfixed is usually what separates being listed from being recommended.',
        'leader'   => 'Leader-level sites have done the hard work — the open question is what the few remaining gaps are worth in dollars each year.',
    ];

    /**
     * Renders the headline score chip: "39 / 60 — Operator" with band-colored background.
     */
    public static function renderScoreBadge(int $score, int $max, string $band): string
    {
        $preset = self::BAND_PRESENTATION[$band] ?? ['color' => '#646970', 'label' => ucfirst($band)];

        return sprintf(
            '<div style="display:inline-block;padding:12px 20px;border-radius:8px;background:%1$s;color:#fff;font-weight:600;">'
            . '<span style="font-size:24px;line-height:1;">%2$d / %3$d</span>'
            . ' <span style="margin-left:8px;opacity:0.9;">&mdash; %4$s</span>'
            . '</div>',
            esc_attr((string) $preset['color']),
            $score,
            $max,
            esc_html((string) $preset['label'])
        );
    }

    /**
     * Renders a single check result as a card: status pill, label, score,
     * explanation, and (if present) fix hint.
     *
     * @param array<string, mixed> $checkResult
     */
    public static function renderResultRow(array $checkResult): string
    {
        $status        = (string) ($checkResult['status'] ?? 'fail');
        $statusPreset  = self::STATUS_PRESENTATION[$status] ?? ['color' => '#646970', 'label' => 'Unknown'];
        $checkLabel    = (string) ($checkResult['checkLabel'] ?? '');
        $explanation   = (string) ($checkResult['explanation'] ?? '');
        $fixHint       = (string) ($checkResult['fixHint'] ?? '');
        $score         = (int) ($checkResult['score'] ?? 0);

        $html  = '<div style="border:1px solid #c3c4c7;border-left-width:4px;border-left-color:'
               . esc_attr((string) $statusPreset['color'])
               . ';border-radius:4px;padding:12px 14px;margin-bottom:10px;background:#fff;">';

        $html .= '<div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">';
        $html .= sprintf(
            '<span style="display:inline-block;padding:2px 10px;border-radius:10px;background:%1$s;color:#fff;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;">%2$s</span>',
            esc_attr((string) $statusPreset['color']),
            esc_html((string) $statusPreset['label'])
        );
        $html .= sprintf(
            '<strong style="flex:1;">%s</strong>',
            esc_html($checkLabel)
        );
        $html .= sprintf(
            '<span style="color:#646970;font-variant-numeric:tabular-nums;">%d / 6</span>',
            $score
        );
        $html .= '</div>';

        if ($explanation !== '') {
            $html .= sprintf(
                '<p style="margin:6px 0 0;color:#1d2327;">%s</p>',
                esc_html($explanation)
            );
        }

        if ($fixHint !== '') {
            $html .= sprintf(
                '<p style="margin:8px 0 0;padding:8px 12px;background:#f6f7f7;border-left:3px solid #2271b1;color:#1d2327;font-size:13px;"><strong>Fix:</strong> %s</p>',
                esc_html($fixHint)
            );
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * Renders the top-N failing/partial checks as an ordered list of fix
     * priorities. Used by the Dashboard widget.
     *
     * @param array<int, array<string, mixed>> $results
     */
    public static function renderTopFixes(array $results, int $limit = 3): string
    {
        $needsFix = array_values(array_filter(
            $results,
            static fn(array $r): bool => in_array($r['status'] ?? '', ['fail', 'partial'], true)
        ));

        usort(
            $needsFix,
            static fn(array $a, array $b): int => ((int) ($a['score'] ?? 0)) <=> ((int) ($b['score'] ?? 0))
        );

        $top = array_slice($needsFix, 0, $limit);

        if ($top === []) {
            return '<p style="color:#00a32a;font-weight:600;">All checks passing — no fixes needed.</p>';
        }

        $html = '<p style="margin:0 0 6px;color:#1d2327;font-weight:600;">Top fixes:</p>';
        $html .= '<ol style="margin:0 0 0 20px;padding:0;">';
        foreach ($top as $check) {
            $html .= sprintf(
                '<li style="margin-bottom:4px;color:#1d2327;">%s</li>',
                esc_html((string) ($check['checkLabel'] ?? ''))
            );
        }
        $html .= '</ol>';
        return $html;
    }

    /**
     * Renders a compact text-form score history: timestamp + score.
     * Used by the Tools page.
     *
     * @param array<int, array<string, mixed>> $history Newest-first.
     */
    public static function renderScoreHistory(array $history, int $limit = 4): string
    {
        $entries = array_slice($history, 0, $limit);
        if ($entries === []) {
            return '<p><em>No scan history yet.</em></p>';
        }

        $html  = '<h3>Recent scans</h3>';
        $html .= '<ul style="margin:0;padding:0;list-style:none;">';
        foreach ($entries as $entry) {
            $ranAt = (string) ($entry['ran_at'] ?? '');
            $score = (int) ($entry['total_score'] ?? 0);
            $max   = (int) ($entry['max_possible'] ?? 0);
            $band  = (string) ($entry['band'] ?? '');
            $bandLabel = self::BAND_PRESENTATION[$band]['label'] ?? ucfirst($band);

            $html .= sprintf(
                '<li style="padding:6px 0;border-bottom:1px solid #f0f0f1;color:#1d2327;">'
                . '<code style="margin-right:12px;">%s</code>'
                . '<strong>%d / %d</strong>'
                . ' &mdash; <span>%s</span>'
                . '</li>',
                esc_html($ranAt),
                $score,
                $max,
                esc_html($bandLabel)
            );
        }
        $html .= '</ul>';
        return $html;
    }

    /**
     * Renders the band-aware bridge card to the AI Visibility Cost
     * Calculator on caidance.ai. Qualitative by design — the plugin has no
     * revenue inputs, so it never shows a dollar amount itself; the
     * calculator (a free crawl, no sign-up) is where the estimate happens.
     */
    public static function renderCalculatorBridge(string $band): string
    {
        $lead = self::CALCULATOR_BRIDGE_LEADS[$band]
            ?? 'Every gap in these checks is a share of AI-driven demand your site may be forfeiting.';

        $url = 'https://caidance.ai/ai-visibility-calculator/'
            . '?utm_source=wp_plugin&utm_medium=plugin_results'
            . '&utm_campaign=wp_v11_at_risk_teaser&utm_content=' . rawurlencode($band);

        $html  = '<div style="border:1px solid #c3c4c7;border-radius:4px;padding:16px 18px;margin:18px 0 0;background:#fff;max-width:640px;">';
        $html .= '<h3 style="margin:0 0 8px;">What is this score costing you?</h3>';
        $html .= sprintf('<p style="margin:0 0 10px;color:#1d2327;">%s</p>', esc_html($lead));
        $html .= '<p style="margin:0 0 12px;color:#1d2327;">See your estimated dollar figure &mdash; Caidance crawls your live site and translates the gap into an annual revenue number, in about a minute. Estimated, always &mdash; and no sign-up needed.</p>';
        $html .= sprintf(
            '<a class="button button-primary" href="%s" target="_blank" rel="noopener noreferrer">See the dollar figure</a>',
            esc_url($url)
        );
        $html .= '</div>';

        return $html;
    }

    /**
     * Renders the Caidance Pilot connect card. Copy is the frozen message
     * spine adapted verbatim. Optional by design — no locked results, no
     * admin-wide nags; the plugin stays fully useful without it.
     */
    public static function renderPilotConnect(string $band): string
    {
        $url = 'https://caidance.ai/start-monitoring/'
            . '?utm_source=wp_plugin&utm_medium=plugin_results'
            . '&utm_campaign=wp_v11_pilot_connect&utm_content=' . rawurlencode($band);

        $html  = '<div style="border:1px solid #c3c4c7;border-radius:4px;padding:16px 18px;margin:12px 0 0;background:#f6f7f7;max-width:640px;">';
        $html .= '<h3 style="margin:0 0 8px;">Put your score on autopilot</h3>';
        $html .= '<p style="margin:0 0 12px;color:#1d2327;">Caidance watches how AI sees your business, tells you what it&#8217;s costing you, and guides the fixes &mdash; for $9.95 a month. Cancel anytime.</p>';
        $html .= sprintf(
            '<a class="button" href="%s" target="_blank" rel="noopener noreferrer">Connect Caidance Monitoring &mdash; $9.95/mo</a>',
            esc_url($url)
        );
        $html .= '</div>';

        return $html;
    }
}
