<?php
/**
 * Fix for the robots_sitemap check: declares the site's sitemap in
 * robots.txt with a single `Sitemap:` line.
 *
 * Why this fix exists: SEO plugins that replace the WordPress core
 * sitemaps also remove core's automatic Sitemap line from the generated
 * robots.txt — and most do not re-add their own. The check fails, the
 * sitemap is fine, and the owner has no idea a one-line declaration is
 * all that is missing.
 *
 * Safety model:
 *
 *   1. VERIFIED TARGET ONLY. The sitemap URL is detected live — the
 *      active SEO plugin's sitemap first (e.g. Yoast /sitemap_index.xml),
 *      else the core /wp-sitemap.xml — and must respond with real
 *      sitemap XML before an approve button ever renders. No verified
 *      sitemap, no fix: the panel explains why instead.
 *   2. NEVER A DUPLICATE. If robots.txt already declares any Sitemap
 *      line — core, an SEO plugin, a physical file, the owner — nothing
 *      is offered and nothing is added. The standing outputter carries
 *      the same guard permanently.
 *   3. TWO SITE STATES, TWO PROVEN PATTERNS. A physical robots.txt gets
 *      the line appended with modify-with-exact-restore semantics (the
 *      complete original stored first, byte-for-byte restore, refuses
 *      if edited since — same as the AI-crawler fix). A WordPress-
 *      generated robots.txt gets a standing filter line instead (pure
 *      output switch, no file written, instant revert — same as the
 *      schema fixes), via RobotsSitemapOutputter.
 *   4. VERIFIED + LOGGED. After applying, the served robots.txt is
 *      re-fetched (cache-busted), the full scan re-runs, and everything
 *      lands in the evidence log. The weekly drift watch flags the fix
 *      if the line ever stops being served.
 *
 * @package Caidance\AiReadiness
 */

declare(strict_types=1);

namespace Caidance\AiReadiness\Fixer;

use Caidance\AiReadiness\Http\SiteFetcher;

final class RobotsSitemapFixer extends AbstractFixer
{
    public const CHECK_ID = 'robots_sitemap';

    public const STATE_FIXABLE      = 'fixable';
    public const STATE_APPLIED      = 'applied_by_us';
    public const STATE_EDITED       = 'modified_after_apply';
    public const STATE_FOREIGN_LINE = 'foreign_sitemap_line';
    public const STATE_NO_SITEMAP   = 'no_sitemap_source';
    public const STATE_NOT_PUBLIC   = 'site_not_public';
    public const STATE_NOT_WRITABLE = 'not_writable';
    public const STATE_UNSUPPORTED  = 'unsupported_install';
    public const STATE_TOO_LARGE    = 'file_too_large';

    public const MARKER_OPTION = 'caidance_air_robots_sitemap_marker';

    private const MAX_BYTES = 65536;

    /**
     * Sitemap index paths for the SEO plugins in
     * SchemaOutputter::SEO_PLUGINS (same slugs — keep the lists in
     * sync). Candidates only: every path is verified live (HTTP 2xx +
     * real sitemap XML) before it is ever declared, so a wrong guess
     * simply falls through to the core sitemap probe.
     */
    private const SEO_SITEMAP_PATHS = [
        'wordpress-seo'       => '/sitemap_index.xml',
        'seo-by-rank-math'    => '/sitemap_index.xml',
        'all-in-one-seo-pack' => '/sitemap.xml',
        'autodescription'     => '/sitemap.xml',
        'wp-seopress'         => '/sitemaps.xml',
        'slim-seo'            => '/sitemap.xml',
    ];

    private const CORE_SITEMAP_PATH = '/wp-sitemap.xml';

    public function id(): string
    {
        return self::CHECK_ID;
    }

    public function label(): string
    {
        return 'Sitemap declaration (robots.txt)';
    }

    public function path(): string
    {
        return trailingslashit(ABSPATH) . 'robots.txt';
    }

    public function isStandardInstall(): bool
    {
        return untrailingslashit((string) home_url('/')) === untrailingslashit((string) site_url('/'));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function marker(): ?array
    {
        $marker = get_option(self::MARKER_OPTION, null);
        return is_array($marker) ? $marker : null;
    }

    /**
     * Side-effect-free state read — local file + options + the stored
     * check row only, no HTTP. The live sitemap verification happens at
     * preview and again at apply, so a stale read here can never cause
     * a bad write; at worst the panel copy is one scan behind.
     *
     * 'mode' says which pattern applies right now: 'file' (physical
     * robots.txt, append with exact-restore) or 'virtual' (WordPress-
     * generated robots.txt, standing output line).
     *
     * @param array<string, mixed>|null $latestCheck
     * @return array{state: string, path: string, marker: array<string, mixed>|null, mode: string, source: string, expected_url: string, stale_marker: bool, file_appeared: bool, check_lagging: bool}
     */
    public function status(?array $latestCheck): array
    {
        $path        = $this->path();
        $marker      = $this->marker();
        $checkStatus = is_array($latestCheck) ? (string) ($latestCheck['status'] ?? '') : '';
        $base        = [
            'path'          => $path,
            'marker'        => $marker,
            'mode'          => '',
            'source'        => '',
            'expected_url'  => '',
            'stale_marker'  => false,
            'file_appeared' => false,
            'check_lagging' => false,
        ];

        clearstatcache();
        $fileExists = file_exists($path);
        $markerMode = is_array($marker) ? (string) ($marker['mode'] ?? '') : '';

        // File-mode apply record. apply() never lets a file record and
        // the virtual option coexist, so this branch is checked first.
        if ($markerMode === 'file') {
            if ($fileExists && (string) sha1_file($path) === (string) ($marker['modified_hash'] ?? '')) {
                return ['state' => self::STATE_APPLIED, 'mode' => 'file', 'check_lagging' => in_array($checkStatus, ['fail', 'partial'], true)] + $base;
            }
            if ($fileExists) {
                $content = (string) file_get_contents($path);
                if (RobotsSitemapOutputter::hasSitemapLine($content)) {
                    // Edited since, but a Sitemap line survives — the
                    // declaration still stands; only the restore is off.
                    return ['state' => self::STATE_EDITED, 'mode' => 'file'] + $base;
                }
                // Overwritten outside the plugin and the line is gone —
                // the drift signal; the panel offers a re-apply.
                return ['state' => self::STATE_FIXABLE, 'mode' => 'file', 'stale_marker' => true] + $this->expectedSitemap() + $base;
            }
            // File removed entirely — WordPress generates robots.txt
            // again, so a re-apply would go the virtual route.
            return ['state' => self::STATE_FIXABLE, 'mode' => 'virtual', 'stale_marker' => true] + $this->expectedSitemap() + $base;
        }

        // Virtual-mode apply record (the standing output line).
        if (get_option(RobotsSitemapOutputter::ENABLED_OPTION, '0') === '1') {
            return [
                'state'         => self::STATE_APPLIED,
                'mode'          => 'virtual',
                'file_appeared' => $fileExists,
                'check_lagging' => in_array($checkStatus, ['fail', 'partial'], true),
            ] + $base;
        }

        // No Caidance record from here down.
        if ((string) get_option('blog_public', '1') === '0') {
            return ['state' => self::STATE_NOT_PUBLIC] + $base;
        }

        // Check partial = a Sitemap line exists but its URL is not
        // responding. Not ours; never add a second line next to it.
        if ($checkStatus === 'partial') {
            return ['state' => self::STATE_FOREIGN_LINE] + $base;
        }

        $extras = $this->expectedSitemap();
        if ($extras['source'] === '') {
            return ['state' => self::STATE_NO_SITEMAP] + $base;
        }

        if ($fileExists) {
            if (!$this->isStandardInstall()) {
                return ['state' => self::STATE_UNSUPPORTED] + $extras + $base;
            }
            $size = filesize($path);
            if (!is_int($size) || $size > self::MAX_BYTES) {
                return ['state' => self::STATE_TOO_LARGE] + $extras + $base;
            }
            $content = (string) file_get_contents($path);
            if (RobotsSitemapOutputter::hasSitemapLine($content)) {
                // Fresher than the stored scan: a line exists now.
                return ['state' => self::STATE_FOREIGN_LINE] + $base;
            }
            if (!wp_is_writable(ABSPATH) || !wp_is_writable($path)) {
                return ['state' => self::STATE_NOT_WRITABLE] + $extras + $base;
            }
            return ['state' => self::STATE_FIXABLE, 'mode' => 'file'] + $extras + $base;
        }

        return ['state' => self::STATE_FIXABLE, 'mode' => 'virtual'] + $extras + $base;
    }

    /**
     * Live preview: verifies the sitemap target (HTTP 2xx + real
     * sitemap XML), reads the current robots.txt (file or as served),
     * and builds the exact resulting content via the same appendLine()
     * the write path uses — preview always equals apply. When ok is
     * false, 'reason' says why the fix is not being offered.
     *
     * @return array{ok: bool, reason: string, mode: string, sitemap_url: string, source: string, probed: array<int, string>, line: string, content: string, status_code: int}
     */
    public function previewData(): array
    {
        $fetcher = new SiteFetcher();
        $found   = $this->detectSitemapLive($fetcher);
        $base    = [
            'mode'        => '',
            'sitemap_url' => $found['url'],
            'source'      => $found['source'],
            'probed'      => $found['probed'],
            'line'        => '',
            'content'     => '',
            'status_code' => 0,
        ];

        if ($found['url'] === '') {
            return ['ok' => false, 'reason' => $found['blocked'] ? 'scanner_blocked' : 'no_sitemap_verified'] + $base;
        }
        $base['line'] = 'Sitemap: ' . $found['url'];

        clearstatcache();
        if (file_exists($this->path())) {
            $current = (string) file_get_contents($this->path());
            if (RobotsSitemapOutputter::hasSitemapLine($current)) {
                return ['ok' => false, 'reason' => 'already_declared', 'mode' => 'file'] + $base;
            }
            return [
                'ok'      => true,
                'reason'  => '',
                'mode'    => 'file',
                'content' => RobotsSitemapOutputter::appendLine($current, $found['url']),
            ] + $base;
        }

        $served = $fetcher->get($fetcher->urlFor('/robots.txt') . '?caidance-air-verify=' . time());
        if (!$served['ok']) {
            $reason = ((string) ($served['challenge_signal'] ?? '') !== '') ? 'scanner_blocked' : 'robots_not_served';
            return ['ok' => false, 'reason' => $reason, 'mode' => 'virtual', 'status_code' => $served['status_code']] + $base;
        }
        if (RobotsSitemapOutputter::hasSitemapLine($served['body'])) {
            return ['ok' => false, 'reason' => 'already_declared', 'mode' => 'virtual'] + $base;
        }
        return [
            'ok'      => true,
            'reason'  => '',
            'mode'    => 'virtual',
            'content' => RobotsSitemapOutputter::appendLine($served['body'], $found['url']),
        ] + $base;
    }

    /**
     * @param array<string, mixed> $status
     * @param array<string, mixed> $latestCheck
     */
    public function wantsPanel(array $status, array $latestCheck): bool
    {
        $checkStatus = (string) ($latestCheck['status'] ?? '');
        // Scanner blocked = nothing about the site was proven, and the
        // fix's own live probes would be challenged too — offer nothing
        // on unproven ground. Only an applied fix keeps its panel, so
        // revert stays reachable.
        if ($checkStatus === 'unverified') {
            return ($status['state'] ?? '') === self::STATE_APPLIED;
        }
        return !(($checkStatus === 'pass') && ($status['state'] ?? '') !== self::STATE_APPLIED);
    }

    /**
     * @param array<string, mixed> $status
     * @param array<string, mixed> $latestCheck
     */
    public function renderPanel(array $status, bool $previewing, array $latestCheck): string
    {
        $state = (string) ($status['state'] ?? '');
        $mode  = (string) ($status['mode'] ?? '');
        $html  = '';

        switch ($state) {
            case self::STATE_APPLIED:
                $marker    = is_array($status['marker']) ? $status['marker'] : [];
                $appliedAt = (string) ($marker['applied_at'] ?? '');
                $line      = (string) ($marker['added_line'] ?? '');
                $lead      = '<strong>' . esc_html__('Applied by Caidance', 'caidance-ai-readiness') . '</strong>';
                if ($appliedAt !== '') {
                    $lead .= ' ' . esc_html__('on', 'caidance-ai-readiness') . ' <code>' . esc_html($appliedAt) . '</code>';
                }
                $lead .= ' &mdash; ' . ($mode === 'virtual'
                    ? esc_html__('a standing Sitemap line is appended to the WordPress-generated robots.txt. No files were written.', 'caidance-ai-readiness')
                    : esc_html__('the Sitemap line was appended to robots.txt; every original line was kept byte-for-byte.', 'caidance-ai-readiness'));
                $html .= $this->paragraph($lead);
                if ($line !== '') {
                    $html .= $this->preBlock($line);
                }
                if ($mode === 'virtual' && !empty($status['file_appeared'])) {
                    $html .= $this->paragraph('<strong>' . esc_html__('Heads up:', 'caidance-ai-readiness') . '</strong> ' . esc_html__('a physical robots.txt file now exists, and it overrides the WordPress-generated output — the standing line is no longer what visitors see. Revert this fix, then run it again to append the line to the file itself.', 'caidance-ai-readiness'));
                } elseif ((string) ($latestCheck['status'] ?? '') === 'unverified') {
                    $html .= $this->paragraph(esc_html__('The last scan could not verify this: the scan requests were blocked by your firewall or CDN. That is the scanner being blocked, not the fix failing — adjust the bot protection or allowlist your own server, then re-run a scan.', 'caidance-ai-readiness'));
                } elseif (!empty($status['check_lagging'])) {
                    $html .= $this->paragraph(esc_html__('The last scan has not seen it yet — if your site uses page caching, clear the cache and re-run a scan.', 'caidance-ai-readiness'));
                }
                if ($mode === 'virtual') {
                    $html .= $this->revertForm(__('Revert this fix', 'caidance-ai-readiness'));
                    $html .= $this->descriptionLine(__('Reverting switches the output off instantly — no files were ever written.', 'caidance-ai-readiness'));
                } else {
                    $html .= $this->revertForm(__('Restore the original robots.txt', 'caidance-ai-readiness'));
                    $html .= $this->descriptionLine(__('Restore puts back the exact original file — Caidance stored its full content before changing anything. It refuses if robots.txt was edited since.', 'caidance-ai-readiness'));
                }
                break;

            case self::STATE_EDITED:
                $html .= $this->paragraph(esc_html__('robots.txt has been edited since Caidance added the Sitemap line, so the one-click restore is disabled — your edits are yours. A Sitemap line is still declared in the file, and the stored original is kept in the fix record if you ever need it.', 'caidance-ai-readiness'));
                break;

            case self::STATE_FOREIGN_LINE:
                $html .= $this->paragraph(esc_html__('robots.txt already declares a Sitemap line that Caidance did not add — but the check above is not passing, which usually means the declared URL does not respond (or a cache/CDN layer serves a different robots.txt). Caidance never adds a second Sitemap line next to an existing one: fix the existing declaration in the tool that owns it, using the fix hint above.', 'caidance-ai-readiness'));
                break;

            case self::STATE_NO_SITEMAP:
                $html .= $this->paragraph(esc_html__('Caidance could not find a sitemap to declare. WordPress core sitemaps appear to be switched off (an SEO plugin or theme usually does this), and no known SEO-plugin sitemap is active. Declaring a Sitemap line that points at a dead URL would hurt, not help — enable the XML sitemap in your SEO plugin (or re-enable core sitemaps), re-run a scan, and this fix will light up.', 'caidance-ai-readiness'));
                break;

            case self::STATE_NOT_PUBLIC:
                $html .= $this->paragraph(esc_html__('This site is set to discourage search engines (Settings → Reading → Search engine visibility), so Caidance will not declare a sitemap — your site is currently asking crawlers to stay away. If that is unintentional, switch it off there and re-run a scan.', 'caidance-ai-readiness'));
                break;

            case self::STATE_NOT_WRITABLE:
            case self::STATE_UNSUPPORTED:
            case self::STATE_TOO_LARGE:
                $html .= $this->paragraph(esc_html(
                    $state === self::STATE_NOT_WRITABLE
                        ? __('Caidance found your sitemap, but robots.txt is not writable from WordPress on this host. Add this line to the file manually (check that the sitemap URL loads first):', 'caidance-ai-readiness')
                        : __('Caidance will not auto-edit this robots.txt (unusual install layout or a very large file). Add this line manually (check that the sitemap URL loads first):', 'caidance-ai-readiness')
                ));
                $html .= $this->preBlock('Sitemap: ' . (string) ($status['expected_url'] ?? ''));
                break;

            case self::STATE_FIXABLE:
            default:
                $html .= $this->renderFixablePanel($status, $previewing, $mode);
                break;
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $status
     */
    private function renderFixablePanel(array $status, bool $previewing, string $mode): string
    {
        $html = '';

        if (!empty($status['stale_marker'])) {
            $html .= $this->paragraph('<strong>' . esc_html__('Drift detected:', 'caidance-ai-readiness') . '</strong> ' . ($mode === 'virtual'
                ? esc_html__('the robots.txt file Caidance modified has since been removed, and WordPress is generating robots.txt again. You can re-apply the fix below — this time as a standing line on the generated output.', 'caidance-ai-readiness')
                : esc_html__('robots.txt has been replaced since Caidance added the Sitemap line, and the line is gone — a deploy or another plugin may have rewritten the file. You can re-apply it below.', 'caidance-ai-readiness')));
        }

        if (!$previewing) {
            $html .= $this->paragraph(
                '<strong>' . esc_html__('Caidance can fix this one for you.', 'caidance-ai-readiness') . '</strong> '
                . esc_html__('Your robots.txt never mentions your sitemap, so crawlers and AI agents have to guess where your content map lives.', 'caidance-ai-readiness') . ' '
                . ($mode === 'virtual'
                    ? esc_html__('WordPress generates your robots.txt, and Caidance can append one Sitemap line to that output — no file is written, and revert switches it off instantly.', 'caidance-ai-readiness')
                    : esc_html__('Caidance can append one Sitemap line to the file — the complete original is stored first, and one click restores it byte-for-byte.', 'caidance-ai-readiness'))
            );
            $expectedUrl = (string) ($status['expected_url'] ?? '');
            $source      = (string) ($status['source'] ?? '');
            if ($expectedUrl !== '' && $source !== '') {
                $html .= $this->paragraph(sprintf(
                    /* translators: 1: the sitemap URL, 2: the sitemap source (an SEO plugin name or "WordPress core"). */
                    esc_html__('The sitemap it expects to declare: %1$s (%2$s). Preview first verifies that this sitemap actually loads — Caidance never declares a URL it could not verify.', 'caidance-ai-readiness'),
                    '<code>' . esc_html($expectedUrl) . '</code>',
                    esc_html($source)
                ));
            }
            $html .= $this->previewLink(__('Preview the fix', 'caidance-ai-readiness'));
            return $html;
        }

        $preview = $this->previewData();

        if (!$preview['ok']) {
            switch ($preview['reason']) {
                case 'scanner_blocked':
                    $html .= $this->paragraph(esc_html__('Caidance could not verify anything just now: its own requests are being challenged by your firewall or CDN (for example Cloudflare Bot Fight Mode). That is the scanner being blocked, not your site failing — allowlist requests from your own server or adjust the bot protection, then preview again.', 'caidance-ai-readiness'));
                    break;
                case 'no_sitemap_verified':
                    $html .= $this->paragraph(esc_html__('Caidance looked for a working sitemap just now and found none. It will not declare a sitemap URL it could not load — that would point crawlers at a dead end. These URLs were checked:', 'caidance-ai-readiness'));
                    $html .= $this->preBlock(implode("\n", array_map('strval', $preview['probed'])));
                    $html .= $this->paragraph(esc_html__('Enable the XML sitemap in your SEO plugin (or WordPress core sitemaps), then preview again.', 'caidance-ai-readiness'));
                    break;
                case 'robots_not_served':
                    $html .= $this->paragraph(esc_html__('Your site is not serving robots.txt at all right now — WordPress normally generates one, so a server rule or hosting layer may be intercepting it. Caidance cannot append a line to output that is not being served. Resolve robots.txt first, then re-run a scan.', 'caidance-ai-readiness'));
                    break;
                case 'already_declared':
                default:
                    $html .= $this->paragraph(esc_html__('A Sitemap line has appeared in robots.txt since the last scan — there is nothing for Caidance to add (it never declares a second one). Re-run a scan to refresh the results.', 'caidance-ai-readiness'));
                    break;
            }
            $html .= $this->cancelLink();
            return $html;
        }

        $html .= '<h4 style="margin:0 0 6px;">' . esc_html__('The exact line Caidance will add', 'caidance-ai-readiness') . '</h4>';
        $html .= $this->preBlock($preview['line']);
        $html .= $this->paragraph(sprintf(
            /* translators: 1: the sitemap URL, 2: the sitemap source (an SEO plugin name or "WordPress core"). */
            esc_html__('Verified just now: %1$s responds and contains real sitemap XML (%2$s).', 'caidance-ai-readiness'),
            '<code>' . esc_html($preview['sitemap_url']) . '</code>',
            esc_html($preview['source'])
        ));
        $html .= '<p style="margin:0 0 8px;">' . ($preview['mode'] === 'virtual'
            ? esc_html__('The resulting robots.txt, exactly as WordPress will serve it:', 'caidance-ai-readiness')
            : esc_html__('The resulting file — every original line is byte-for-byte identical:', 'caidance-ai-readiness')) . '</p>';
        $html .= $this->preBlock($preview['content']);

        $bullets = ['<li>' . esc_html__('Nothing changes until you click approve.', 'caidance-ai-readiness') . '</li>'];
        if ($preview['mode'] === 'virtual') {
            $bullets[] = '<li>' . esc_html__('No file is written — this is an output switch, and revert removes the line instantly.', 'caidance-ai-readiness') . '</li>';
            $bullets[] = '<li>' . esc_html__('If anything else ever declares a sitemap in robots.txt, Caidance steps back automatically rather than adding a second line.', 'caidance-ai-readiness') . '</li>';
        } else {
            $bullets[] = '<li>' . esc_html__('The complete original file is stored first — restore puts back the exact original bytes.', 'caidance-ai-readiness') . '</li>';
        }
        $bullets[] = '<li>' . esc_html__('After the change, Caidance re-checks your site and records before/after evidence.', 'caidance-ai-readiness') . '</li>';
        $html .= '<ul style="list-style:disc;margin:0 0 12px 20px;">' . implode('', $bullets) . '</ul>';

        $html .= $this->approveForm(__('Approve & apply', 'caidance-ai-readiness'));
        $html .= $this->cancelLink();

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
        if ((string) get_option('blog_public', '1') === '0') {
            return $this->refuse('site_not_public', 'This site is set to discourage search engines (Settings → Reading), so Caidance will not declare a sitemap — crawlers are being asked to stay away. Make the site public first.');
        }

        $fetcher = new SiteFetcher();
        $found   = $this->detectSitemapLive($fetcher);
        if ($found['url'] === '' && $found['blocked']) {
            return $this->refuse('scanner_blocked', 'Caidance could not verify your sitemap: its own scan requests are being challenged by your firewall or CDN. That is the scanner being blocked, not your sitemap failing. Allowlist requests from your own server or adjust the bot protection, then try again.');
        }
        if ($found['url'] === '') {
            return $this->refuse('no_sitemap_verified', 'No working sitemap could be verified (checked: ' . implode(', ', $found['probed']) . '). Caidance will not declare a sitemap URL it could not load. Enable the XML sitemap in your SEO plugin (or WordPress core sitemaps), then try again.');
        }

        clearstatcache();
        if (file_exists($this->path())) {
            return $this->applyToFile($fetcher, $found['url'], $found['source']);
        }
        return $this->applyVirtual($fetcher, $found['url'], $found['source']);
    }

    /**
     * File mode: append the line with modify-with-exact-restore
     * semantics — the complete original stored first.
     *
     * @return array{ok: bool, code: string, message: string, score_before: int|null, score_after: int|null}
     */
    private function applyToFile(SiteFetcher $fetcher, string $sitemapUrl, string $source): array
    {
        if (!$this->isStandardInstall()) {
            return $this->refuse('unsupported_install', 'This WordPress install serves the site from a different address than WordPress itself, so the plugin cannot safely edit robots.txt. Add the Sitemap line manually.');
        }
        $size = filesize($this->path());
        if (!is_int($size) || $size > self::MAX_BYTES) {
            return $this->refuse('file_too_large', 'robots.txt is unusually large; Caidance will not auto-edit it. Add the Sitemap line manually.');
        }

        $original = (string) file_get_contents($this->path());
        if (RobotsSitemapOutputter::hasSitemapLine($original)) {
            return $this->refuse('already_declared', 'robots.txt now already declares a Sitemap line. Nothing was added — Caidance never declares a second one. Re-run a scan to refresh the results.');
        }

        $notes = [];
        if (get_option(RobotsSitemapOutputter::ENABLED_OPTION, '0') === '1') {
            // A physical file overrides the generated output, so the old
            // standing-line record is dead weight — clear it.
            delete_option(RobotsSitemapOutputter::ENABLED_OPTION);
            $notes[] = 'Cleared the earlier standing-output record (a physical robots.txt now exists and overrides it).';
        }
        $priorMarker = $this->marker();
        if ($priorMarker !== null) {
            $notes[] = 'Replaced the fix record from ' . (string) ($priorMarker['applied_at'] ?? 'an earlier apply') . ' (robots.txt had been changed outside the plugin since).';
        }

        $line     = 'Sitemap: ' . $sitemapUrl;
        $modified = RobotsSitemapOutputter::appendLine($original, $sitemapUrl);

        $before     = $this->latestCheckSnapshot();
        $filesystem = $this->filesystem();
        if ($filesystem === null) {
            return $this->refuse('filesystem_unavailable', 'WordPress could not get direct filesystem access on this host. Add the previewed Sitemap line to robots.txt manually.');
        }
        if (!$filesystem->put_contents($this->path(), $modified, FS_CHMOD_FILE)) {
            return $this->refuse('write_failed', 'robots.txt could not be written on this host. Add the previewed Sitemap line manually.');
        }

        update_option(self::MARKER_OPTION, [
            'mode'             => 'file',
            'sitemap_url'      => $sitemapUrl,
            'sitemap_source'   => $source,
            'added_line'       => $line,
            'original_content' => $original,
            'original_hash'    => sha1($original),
            'modified_hash'    => sha1($modified),
            'applied_at'       => current_time('mysql'),
            'applied_by'       => $this->currentUserLogin(),
        ], false);

        // Verify: disk hash, then the served file (cache-busted).
        clearstatcache();
        $fileVerified = file_exists($this->path()) && sha1_file($this->path()) === sha1($modified);
        $servedCheck  = $fetcher->get($fetcher->urlFor('/robots.txt') . '?caidance-air-verify=' . time());
        $urlVerified  = $servedCheck['ok'] && RobotsSitemapOutputter::hasSitemapLineFor($servedCheck['body'], $sitemapUrl);

        $after = $this->rescanAndSnapshot();

        $this->evidence()->append([
            'event'   => 'applied',
            'fix'     => $this->id(),
            'details' => sprintf(
                'Appended "%s" to %s (%s sitemap, verified live before the write). Original stored for restore. Disk verified: %s. Served: %s.%s',
                $line,
                $this->path(),
                $source,
                $fileVerified ? 'yes' : 'NO',
                $urlVerified ? 'yes' : (((string) ($servedCheck['challenge_signal'] ?? '') !== '') ? 'not verifiable (scan request challenged by firewall/CDN)' : 'not yet (a cache layer may need a few minutes)'),
                $notes !== [] ? ' ' . implode(' ', $notes) : ''
            ),
            'before'  => $before,
            'after'   => $after,
        ]);

        $message = 'The Sitemap line was appended to robots.txt; the original file is stored for one-click restore.';
        if (!$urlVerified && $fileVerified) {
            $message .= ' The served copy has not refreshed yet — a cache layer may need a few minutes.';
        }

        return $this->succeed('applied', $message, $before, $after);
    }

    /**
     * Virtual mode: switch on the standing robots_txt filter line.
     * No files are written; RobotsSitemapOutputter does the serving.
     *
     * @return array{ok: bool, code: string, message: string, score_before: int|null, score_after: int|null}
     */
    private function applyVirtual(SiteFetcher $fetcher, string $sitemapUrl, string $source): array
    {
        $served = $fetcher->get($fetcher->urlFor('/robots.txt') . '?caidance-air-verify=' . time());
        if (!$served['ok'] && (string) ($served['challenge_signal'] ?? '') !== '') {
            return $this->refuse('scanner_blocked', 'Caidance could not read your robots.txt: its own scan request was challenged by your firewall or CDN. That is the scanner being blocked, not robots.txt being absent. Allowlist requests from your own server or adjust the bot protection, then try again.');
        }
        if (!$served['ok']) {
            return $this->refuse('robots_not_served', 'Your site is not serving robots.txt at all right now (HTTP ' . $served['status_code'] . '). WordPress normally generates one — a server rule may be intercepting it. Caidance cannot append a line to output that is not being served.');
        }
        if (RobotsSitemapOutputter::hasSitemapLine($served['body'])) {
            return $this->refuse('already_declared', 'The served robots.txt now already declares a Sitemap line. Nothing was changed — Caidance never declares a second one. Re-run a scan to refresh the results.');
        }
        if (get_option(RobotsSitemapOutputter::ENABLED_OPTION, '0') === '1') {
            return $this->refuse('already_applied', 'The standing Sitemap line is already switched on. If it is not being served, a cache layer may be in the way — clear it and re-run a scan.');
        }

        $notes       = [];
        $priorMarker = $this->marker();
        if ($priorMarker !== null) {
            $notes[] = 'Replaced the fix record from ' . (string) ($priorMarker['applied_at'] ?? 'an earlier apply') . ' (the robots.txt file it modified is gone).';
        }

        $line   = 'Sitemap: ' . $sitemapUrl;
        $before = $this->latestCheckSnapshot();

        update_option(RobotsSitemapOutputter::ENABLED_OPTION, '1', true);
        update_option(self::MARKER_OPTION, [
            'mode'           => 'virtual',
            'sitemap_url'    => $sitemapUrl,
            'sitemap_source' => $source,
            'added_line'     => $line,
            'applied_at'     => current_time('mysql'),
            'applied_by'     => $this->currentUserLogin(),
        ], false);

        // Verify: fresh cache-busted read of the served robots.txt.
        $verify      = (new SiteFetcher())->get($fetcher->urlFor('/robots.txt') . '?caidance-air-verify=' . (time() + 1));
        $urlVerified = $verify['ok'] && RobotsSitemapOutputter::hasSitemapLineFor($verify['body'], $sitemapUrl);

        $after = $this->rescanAndSnapshot();

        $this->evidence()->append([
            'event'   => 'applied',
            'fix'     => $this->id(),
            'details' => sprintf(
                'Enabled the standing Sitemap line on the WordPress-generated robots.txt: "%s" (%s sitemap, verified live). No files written. Served: %s.%s',
                $line,
                $source,
                $urlVerified ? 'yes' : (((string) ($verify['challenge_signal'] ?? '') !== '') ? 'not verifiable (scan request challenged by firewall/CDN)' : 'not yet (a cache layer may need a few minutes)'),
                $notes !== [] ? ' ' . implode(' ', $notes) : ''
            ),
            'before'  => $before,
            'after'   => $after,
        ]);

        $message = 'The Sitemap line is now part of your robots.txt — no files were written.';
        if (!$urlVerified) {
            $message .= ' It is not visible in the served copy yet — a cache layer may need a few minutes.';
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

        $marker     = $this->marker();
        $markerMode = is_array($marker) ? (string) ($marker['mode'] ?? '') : '';

        // Virtual record: switching the option off is the whole revert.
        if (get_option(RobotsSitemapOutputter::ENABLED_OPTION, '0') === '1' || $markerMode === 'virtual') {
            delete_option(RobotsSitemapOutputter::ENABLED_OPTION);
            delete_option(self::MARKER_OPTION);

            $before = $this->latestCheckSnapshot();
            $after  = $this->rescanAndSnapshot();

            $this->evidence()->append([
                'event'   => 'reverted',
                'fix'     => $this->id(),
                'details' => 'Switched off the standing Sitemap line on the WordPress-generated robots.txt. No files were ever written.',
                'before'  => $before,
                'after'   => $after,
            ]);

            return $this->succeed('reverted', 'The standing Sitemap line was switched off.', $before, $after);
        }

        if ($markerMode !== 'file') {
            return $this->refuse('nothing_to_revert', 'Caidance has no record of applying this fix, so there is nothing to revert.');
        }

        clearstatcache();
        if (!file_exists($this->path())) {
            delete_option(self::MARKER_OPTION);
            $this->evidence()->append([
                'event'   => 'reverted',
                'fix'     => $this->id(),
                'details' => 'robots.txt was already gone (removed outside the plugin). Cleared the fix record.',
            ]);
            return $this->succeed('reverted', 'robots.txt was already removed. Caidance cleared its fix record.', null, null);
        }

        if ((string) sha1_file($this->path()) !== (string) ($marker['modified_hash'] ?? '')) {
            return $this->refuse('edited_since_apply', 'robots.txt has been edited since Caidance modified it, so it will not be overwritten — your edits are yours. The stored original remains in the fix record.');
        }

        $original   = (string) ($marker['original_content'] ?? '');
        $filesystem = $this->filesystem();
        if ($filesystem === null || !$filesystem->put_contents($this->path(), $original, FS_CHMOD_FILE)) {
            return $this->refuse('write_failed', 'The original robots.txt could not be restored on this host. Remove the Sitemap line manually.');
        }

        delete_option(self::MARKER_OPTION);

        $before = $this->latestCheckSnapshot();
        $after  = $this->rescanAndSnapshot();

        $this->evidence()->append([
            'event'   => 'reverted',
            'fix'     => $this->id(),
            'details' => 'Restored the original robots.txt byte-for-byte (' . strlen($original) . ' bytes).',
            'before'  => $before,
            'after'   => $after,
        ]);

        return $this->succeed('reverted', 'The original robots.txt was restored exactly as it was.', $before, $after);
    }

    /**
     * Live sitemap detection ladder: the active SEO plugin's sitemap
     * path first, then the WordPress core sitemap. A candidate counts
     * only if it responds 2xx AND the body is real sitemap XML — a
     * soft-404 page served with 200 never qualifies. First verified
     * candidate wins; 'probed' lists every URL checked (for honest
     * copy when none qualifies), and 'blocked' reports whether any
     * probe was challenged by a firewall/CDN — a blocked probe proves
     * nothing about the sitemap, and the copy must say so.
     *
     * @return array{url: string, source: string, probed: array<int, string>, blocked: bool}
     */
    public function detectSitemapLive(SiteFetcher $fetcher): array
    {
        $home       = $fetcher->homeUrl();
        $candidates = [];

        $slug = $this->activeSeoPluginSlug();
        if ($slug !== '') {
            $candidates[] = [
                'url'    => $home . self::SEO_SITEMAP_PATHS[$slug],
                'source' => (string) (SchemaOutputter::SEO_PLUGINS[$slug] ?? 'your SEO plugin'),
            ];
        }
        $candidates[] = ['url' => $home . self::CORE_SITEMAP_PATH, 'source' => 'WordPress core'];

        $probed  = [];
        $blocked = false;
        foreach ($candidates as $candidate) {
            $probed[] = $candidate['url'];
            $response = $fetcher->get($candidate['url']);
            if ((string) ($response['challenge_signal'] ?? '') !== '') {
                $blocked = true;
            }
            if ($response['ok'] && $this->looksLikeSitemapXml($response['body'])) {
                return ['url' => $candidate['url'], 'source' => $candidate['source'], 'probed' => $probed, 'blocked' => $blocked];
            }
        }

        return ['url' => '', 'source' => '', 'probed' => $probed, 'blocked' => $blocked];
    }

    /**
     * Local, no-HTTP expectation of where the sitemap should live —
     * drives the pre-preview panel copy and the manual-guidance line.
     * Empty source = no sitemap provider detected at all.
     *
     * @return array{source: string, expected_url: string}
     */
    private function expectedSitemap(): array
    {
        $home = untrailingslashit((string) home_url('/'));

        $slug = $this->activeSeoPluginSlug();
        if ($slug !== '') {
            return [
                'source'       => (string) (SchemaOutputter::SEO_PLUGINS[$slug] ?? 'your SEO plugin'),
                'expected_url' => $home . self::SEO_SITEMAP_PATHS[$slug],
            ];
        }

        if ($this->coreSitemapsEnabled()) {
            return ['source' => 'WordPress core', 'expected_url' => $home . self::CORE_SITEMAP_PATH];
        }

        return ['source' => '', 'expected_url' => ''];
    }

    /**
     * First active SEO plugin with a known sitemap path, by directory
     * slug. Empty string when none is active.
     */
    private function activeSeoPluginSlug(): string
    {
        $active = get_option('active_plugins', []);
        if (!is_array($active)) {
            return '';
        }
        foreach ($active as $pluginFile) {
            $slug = dirname((string) $pluginFile);
            if (isset(self::SEO_SITEMAP_PATHS[$slug])) {
                return $slug;
            }
        }
        return '';
    }

    private function coreSitemapsEnabled(): bool
    {
        if (!function_exists('wp_sitemaps_get_server')) {
            return false;
        }
        $server = wp_sitemaps_get_server();
        return is_object($server) && method_exists($server, 'sitemaps_enabled') && (bool) $server->sitemaps_enabled();
    }

    private function looksLikeSitemapXml(string $body): bool
    {
        $head = substr($body, 0, self::MAX_BYTES);
        return stripos($head, '<sitemapindex') !== false || stripos($head, '<urlset') !== false;
    }
}
