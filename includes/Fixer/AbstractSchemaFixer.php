<?php
/**
 * Shared implementation for the two homepage-schema fixes
 * (Organization, WebSite). A schema fix is an output toggle:
 *
 *   - apply  = enable the option; SchemaOutputter starts emitting the
 *              node (built live from real site settings) in wp_head on
 *              the front page. No files are touched.
 *   - revert = disable the option; the markup disappears immediately.
 *
 * Add-only + defer-always: the fix only ever offers when the node type
 * is entirely ABSENT from the homepage and no known SEO plugin is
 * active. An existing (even incomplete) node from a theme or another
 * tool is never duplicated or edited; a detected SEO plugin gets named
 * with guidance instead.
 *
 * @package Caidance\AiReadiness
 */

declare(strict_types=1);

namespace Caidance\AiReadiness\Fixer;

use Caidance\AiReadiness\Html\JsonLdExtractor;
use Caidance\AiReadiness\Http\SiteFetcher;

abstract class AbstractSchemaFixer extends AbstractFixer
{
    public const STATE_FIXABLE  = 'fixable';
    public const STATE_APPLIED  = 'applied_by_us';
    public const STATE_SEO_OWNS = 'seo_plugin_owns';
    public const STATE_FOREIGN  = 'foreign_exists';

    /**
     * The option SchemaOutputter reads for this node type.
     */
    abstract protected function enabledOption(): string;

    /**
     * The option holding the apply record (who/when/what was emitted).
     */
    abstract protected function markerOption(): string;

    /**
     * The schema.org type this fix adds ('Organization' or 'WebSite').
     */
    abstract protected function schemaType(): string;

    /**
     * @return array<string, mixed>
     */
    abstract protected function buildNode(): array;

    /**
     * Optional honesty note about completeness (e.g. missing logo).
     * Empty string when the node is complete.
     */
    abstract protected function completenessNote(): string;

    /**
     * @return array<string, mixed>|null
     */
    public function marker(): ?array
    {
        $marker = get_option($this->markerOption(), null);
        return is_array($marker) ? $marker : null;
    }

    /**
     * @param array<string, mixed>|null $latestCheck
     * @return array{state: string, owner: string, marker: array<string, mixed>|null, check_lagging: bool}
     */
    public function status(?array $latestCheck): array
    {
        $checkStatus = is_array($latestCheck) ? (string) ($latestCheck['status'] ?? '') : '';
        $base        = ['owner' => '', 'marker' => $this->marker(), 'check_lagging' => false];

        if (get_option($this->enabledOption(), '0') === '1') {
            return ['state' => self::STATE_APPLIED, 'check_lagging' => ($checkStatus !== 'pass')] + $base;
        }

        if ($checkStatus === 'partial') {
            return ['state' => self::STATE_FOREIGN] + $base;
        }

        $owner = SchemaOutputter::activeSeoPlugin();
        if ($owner !== '') {
            return ['state' => self::STATE_SEO_OWNS, 'owner' => $owner] + $base;
        }

        return ['state' => self::STATE_FIXABLE] + $base;
    }

    /**
     * Pretty-printed JSON of the exact node the outputter will emit.
     */
    public function previewContent(): string
    {
        $json = wp_json_encode($this->buildNode(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($json) ? $json : '';
    }

    /**
     * @param array<string, mixed> $status
     * @param array<string, mixed> $latestCheck
     */
    public function wantsPanel(array $status, array $latestCheck): bool
    {
        $passing = (($latestCheck['status'] ?? '') === 'pass');
        return !($passing && ($status['state'] ?? '') !== self::STATE_APPLIED);
    }

    /**
     * @param array<string, mixed> $status
     * @param array<string, mixed> $latestCheck
     */
    public function renderPanel(array $status, bool $previewing, array $latestCheck): string
    {
        $state = (string) ($status['state'] ?? '');
        $html  = '';

        switch ($state) {
            case self::STATE_APPLIED:
                $appliedAt = is_array($status['marker']) ? (string) ($status['marker']['applied_at'] ?? '') : '';
                $lead      = '<strong>' . esc_html__('Applied by Caidance', 'caidance-ai-readiness') . '</strong>';
                if ($appliedAt !== '') {
                    $lead .= ' ' . esc_html__('on', 'caidance-ai-readiness') . ' <code>' . esc_html($appliedAt) . '</code>';
                }
                $lead .= ' &mdash; ' . sprintf(
                    /* translators: %s is the schema type (Organization or WebSite). */
                    esc_html__('the %s JSON-LD is being output on your homepage, built live from your site settings.', 'caidance-ai-readiness'),
                    esc_html($this->schemaType())
                );
                $html .= $this->paragraph($lead);
                if (!empty($status['check_lagging'])) {
                    $html .= $this->paragraph(esc_html__('The last scan has not seen it yet — if your site uses page caching, clear the cache and re-run a scan.', 'caidance-ai-readiness'));
                }
                $html .= $this->revertForm(__('Revert this fix', 'caidance-ai-readiness'));
                $html .= $this->descriptionLine(__('Reverting switches the output off instantly — no files were ever written.', 'caidance-ai-readiness'));
                break;

            case self::STATE_SEO_OWNS:
                $html .= $this->paragraph(sprintf(
                    /* translators: 1: the SEO plugin name, 2: the schema type. */
                    esc_html__('You are running %1$s, which can output %2$s schema itself. Caidance never duplicates another tool\'s structured data — turn it on there instead (look for its Knowledge Graph, Site Info, or schema settings).', 'caidance-ai-readiness'),
                    esc_html((string) $status['owner']),
                    esc_html($this->schemaType())
                ));
                break;

            case self::STATE_FOREIGN:
                $html .= $this->paragraph(sprintf(
                    /* translators: %s is the schema type. */
                    esc_html__('Your homepage already has %s schema that Caidance did not add — from your theme or another tool — and the check above says it is incomplete. Caidance will not output a second copy; complete the existing one in the tool that owns it, using the fix hint above.', 'caidance-ai-readiness'),
                    esc_html($this->schemaType())
                ));
                break;

            case self::STATE_FIXABLE:
            default:
                if ($previewing) {
                    $html .= '<h4 style="margin:0 0 6px;">' . sprintf(
                        /* translators: %s is the schema type. */
                        esc_html__('The exact %s JSON-LD Caidance will output on your homepage', 'caidance-ai-readiness'),
                        esc_html($this->schemaType())
                    ) . '</h4>';
                    $html .= $this->preBlock($this->previewContent());
                    $note  = $this->completenessNote();
                    if ($note !== '') {
                        $html .= $this->paragraph(esc_html($note));
                    }
                    $html .= '<ul style="list-style:disc;margin:0 0 12px 20px;">'
                        . '<li>' . esc_html__('This is an output switch — no files are written, and revert removes the markup instantly.', 'caidance-ai-readiness') . '</li>'
                        . '<li>' . esc_html__('Built live from your real site settings, so it stays current automatically.', 'caidance-ai-readiness') . '</li>'
                        . '<li>' . esc_html__('If you ever install an SEO plugin, Caidance goes silent rather than double-marking the page.', 'caidance-ai-readiness') . '</li>'
                        . '<li>' . esc_html__('After applying, Caidance re-checks your site and records before/after evidence.', 'caidance-ai-readiness') . '</li>'
                        . '</ul>';
                    $html .= $this->approveForm(__('Approve & apply', 'caidance-ai-readiness'));
                    $html .= $this->cancelLink();
                } else {
                    $html .= $this->paragraph(
                        '<strong>' . esc_html__('Caidance can fix this one for you.', 'caidance-ai-readiness') . '</strong> '
                        . sprintf(
                            /* translators: %s is the schema type. */
                            esc_html__('It outputs a %s JSON-LD block on your homepage, built only from your real site settings — you see the exact markup before anything changes, and revert removes it instantly.', 'caidance-ai-readiness'),
                            esc_html($this->schemaType())
                        )
                    );
                    $html .= $this->previewLink(__('Preview the fix', 'caidance-ai-readiness'));
                }
                break;
        }

        return $html;
    }

    /**
     * @return array{ok: bool, code: string, message: string, score_before: int|null, score_after: int|null}
     */
    public function apply(): array
    {
        if (!current_user_can('manage_options')) {
            return $this->refuse('not_allowed', 'You do not have permission to apply fixes on this site.');
        }
        if (get_option($this->enabledOption(), '0') === '1') {
            return $this->refuse('already_applied', 'This schema output is already switched on.');
        }

        $owner = SchemaOutputter::activeSeoPlugin();
        if ($owner !== '') {
            return $this->refuse('seo_plugin_owns', 'You are running ' . $owner . ', which owns structured-data output. Enable ' . $this->schemaType() . ' schema there instead — Caidance never duplicates another tool.');
        }

        // Live conflict check: a fresh homepage read, in case a theme or
        // plugin started emitting this node since the last scan.
        $fetcher = new SiteFetcher();
        $home    = $fetcher->get($fetcher->homeUrl() . '/?caidance-air-verify=' . time());
        if ($home['ok'] && JsonLdExtractor::findByType(JsonLdExtractor::extract($home['body']), $this->schemaType()) !== []) {
            return $this->refuse('foreign_appeared', 'Your homepage now already has ' . $this->schemaType() . ' schema from another source. Nothing was changed — re-run a scan to refresh the results.');
        }

        $before = $this->latestCheckSnapshot();
        $node   = $this->buildNode();

        update_option($this->enabledOption(), '1', true);
        update_option($this->markerOption(), [
            'node_json'  => wp_json_encode($node, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'applied_at' => current_time('mysql'),
            'applied_by' => $this->currentUserLogin(),
        ], false);

        // Verify: fresh cache-busted homepage read for the node.
        $verify      = (new SiteFetcher())->get($fetcher->homeUrl() . '/?caidance-air-verify=' . (time() + 1));
        $urlVerified = $verify['ok'] && JsonLdExtractor::findByType(JsonLdExtractor::extract($verify['body']), $this->schemaType()) !== [];

        $after = $this->rescanAndSnapshot();

        $this->evidence()->append([
            'event'   => 'applied',
            'fix'     => $this->id(),
            'details' => sprintf(
                'Enabled %s JSON-LD output on the homepage (live-built from site settings). Visible in rendered HTML: %s.',
                $this->schemaType(),
                $urlVerified ? 'yes' : 'not yet (a page-cache layer may need clearing)'
            ),
            'before'  => $before,
            'after'   => $after,
        ]);

        $message = $this->schemaType() . ' schema is now output on your homepage.';
        if (!$urlVerified) {
            $message .= ' It is not visible in the rendered page yet — if you use a page cache, clear it and re-run a scan.';
        }
        $note = $this->completenessNote();
        if ($note !== '') {
            $message .= ' ' . $note;
        }

        return $this->succeed('applied', $message, $before, $after);
    }

    /**
     * @return array{ok: bool, code: string, message: string, score_before: int|null, score_after: int|null}
     */
    public function revert(): array
    {
        if (!current_user_can('manage_options')) {
            return $this->refuse('not_allowed', 'You do not have permission to revert fixes on this site.');
        }
        if (get_option($this->enabledOption(), '0') !== '1') {
            return $this->refuse('nothing_to_revert', 'This schema output is not switched on, so there is nothing to revert.');
        }

        delete_option($this->enabledOption());
        delete_option($this->markerOption());

        $before = $this->latestCheckSnapshot();
        $after  = $this->rescanAndSnapshot();

        $this->evidence()->append([
            'event'   => 'reverted',
            'fix'     => $this->id(),
            'details' => 'Switched off the ' . $this->schemaType() . ' JSON-LD output. No files were ever written.',
            'before'  => $before,
            'after'   => $after,
        ]);

        return $this->succeed('reverted', $this->schemaType() . ' schema output was switched off.', $before, $after);
    }
}
