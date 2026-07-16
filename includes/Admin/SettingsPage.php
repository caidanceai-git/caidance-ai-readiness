<?php
/**
 * Settings → Caidance menu page.
 *
 * Renders the industry picker, the AI-crawler-check toggle, the last-scan
 * badge, and the footer CTA to caidance.ai/snapshot. Surfaces the
 * post-activation welcome notice elsewhere in wp-admin until an industry
 * is picked.
 *
 * The "Run a scan" trigger is a placeholder until the Scanner subsystem
 * ships in the next batch — copy says so honestly.
 *
 * @package Caidance\AiReadiness
 */

declare(strict_types=1);

namespace Caidance\AiReadiness\Admin;

use Caidance\AiReadiness\Rendering\ResultRenderer;
use Caidance\AiReadiness\Storage\ScanHistoryRepository;

final class SettingsPage
{
    public const MENU_SLUG    = 'caidance-ai-readiness';
    private const NONCE_ACTION = 'caidance_air_save_settings';

    /**
     * The 11 Caidance industries — mirrors the parent set in the
     * mu-plugin Industries constant. Sub-verticals are not exposed in v1.
     * Public: LlmsTxtContentBuilder reads it for the industry label.
     *
     * @var array<string, string>
     */
    public const INDUSTRIES = [
        ''                      => '— Select your industry —',
        'financial-services'    => 'Financial Services',
        'healthcare'            => 'Healthcare',
        'legal'                 => 'Legal',
        'home-services'         => 'Home Services',
        'nonprofit'             => 'Nonprofit',
        'professional-services' => 'Professional Services',
        'manufacturing'         => 'Manufacturing',
        'ecommerce'             => 'eCommerce',
        'saas'                  => 'SaaS',
        'education'             => 'Education',
        'local-business'        => 'Local Retail & Restaurants',
    ];

    public function register(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_init', [$this, 'handleFormSubmit']);
        add_action('admin_notices', [$this, 'maybeShowWelcomeNotice']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    /**
     * Enqueues the Run-scan JS only on our Settings → Caidance screen.
     * Per WP.org plugin guidelines, inline <script> tags are not
     * permitted in plugins; JS must be registered + enqueued so
     * WordPress can manage dependencies, versioning, and async/defer.
     *
     * @param string $hookSuffix The current admin screen hook suffix.
     */
    public function enqueueAssets(string $hookSuffix): void
    {
        if ($hookSuffix !== 'settings_page_' . self::MENU_SLUG) {
            return;
        }

        wp_enqueue_script(
            'caidance-air-admin-scan',
            plugins_url('assets/js/admin-scan.js', CAIDANCE_AIR_FILE),
            [],
            CAIDANCE_AIR_VERSION,
            true
        );
    }

    public function registerMenu(): void
    {
        add_options_page(
            __('Caidance — AI-Readiness', 'caidance-ai-readiness'),
            __('Caidance', 'caidance-ai-readiness'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'renderPage']
        );
    }

    /**
     * Handles the settings form POST. Saves industry + AI-crawler toggle.
     */
    public function handleFormSubmit(): void
    {
        if (!isset($_POST['caidance_air_settings_nonce'])) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }
        $nonce = sanitize_text_field(wp_unslash((string) $_POST['caidance_air_settings_nonce']));
        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            return;
        }

        $industry = isset($_POST['caidance_air_industry'])
            ? sanitize_key((string) wp_unslash($_POST['caidance_air_industry']))
            : '';
        if (!array_key_exists($industry, self::INDUSTRIES)) {
            $industry = '';
        }
        update_option('caidance_air_industry', $industry);

        $crawlerCheck = isset($_POST['caidance_air_ai_crawler_check_enabled']) ? '1' : '0';
        update_option('caidance_air_ai_crawler_check_enabled', $crawlerCheck);

        wp_safe_redirect(add_query_arg(
            ['page' => self::MENU_SLUG, 'caidance-air-saved' => '1'],
            admin_url('options-general.php')
        ));
        exit;
    }

    public function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $industry       = (string) get_option('caidance_air_industry', '');
        $crawlerEnabled = get_option('caidance_air_ai_crawler_check_enabled', '1') === '1';
        $lastScan       = (string) get_option('caidance_air_last_scan', '');
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag for the post-save redirect notice; no data processing.
        $justSaved      = isset($_GET['caidance-air-saved']) && $_GET['caidance-air-saved'] === '1';

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Caidance — AI-Readiness Score', 'caidance-ai-readiness'); ?></h1>

            <?php if ($justSaved): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Settings saved.', 'caidance-ai-readiness'); ?></p>
                </div>
            <?php endif; ?>

            <p>
                <?php esc_html_e('Pick your industry, then run a scan to see what AI says about your site.', 'caidance-ai-readiness'); ?>
            </p>

            <form method="post" action="">
                <?php wp_nonce_field(self::NONCE_ACTION, 'caidance_air_settings_nonce'); ?>

                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="caidance_air_industry"><?php esc_html_e('Your industry', 'caidance-ai-readiness'); ?></label>
                            </th>
                            <td>
                                <select name="caidance_air_industry" id="caidance_air_industry">
                                    <?php foreach (self::INDUSTRIES as $value => $label): ?>
                                        <option value="<?php echo esc_attr($value); ?>" <?php selected($industry, $value); ?>>
                                            <?php echo esc_html($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">
                                    <?php esc_html_e('Tailors your fix list to what matters most in your industry.', 'caidance-ai-readiness'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('AI crawler check', 'caidance-ai-readiness'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="caidance_air_ai_crawler_check_enabled" value="1" <?php checked($crawlerEnabled); ?> />
                                    <?php esc_html_e('Include the AI-crawler access check (GPTBot, ClaudeBot, PerplexityBot, OAI-SearchBot, Google-Extended).', 'caidance-ai-readiness'); ?>
                                </label>
                                <p class="description">
                                    <?php esc_html_e('Turn off if your site intentionally blocks AI crawlers.', 'caidance-ai-readiness'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Last scan', 'caidance-ai-readiness'); ?></th>
                            <td>
                                <?php if ($lastScan !== ''): ?>
                                    <code><?php echo esc_html($lastScan); ?></code>
                                <?php else: ?>
                                    <em><?php esc_html_e('No scan has run yet.', 'caidance-ai-readiness'); ?></em>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <?php submit_button(__('Save settings', 'caidance-ai-readiness')); ?>
            </form>

            <hr />

            <h2><?php esc_html_e('Run a scan', 'caidance-ai-readiness'); ?></h2>
            <?php $this->renderScanSection($industry); ?>

            <hr />

            <p>
                <?php
                printf(
                    /* translators: %s is an HTML link to the Caidance snapshot URL. */
                    esc_html__('Want the full off-site picture too? Caidance offers a free 60-second AI snapshot at %s.', 'caidance-ai-readiness'),
                    '<a href="' . esc_url('https://caidance.ai/snapshot/?utm_source=wp_plugin&utm_medium=settings_footer&utm_campaign=wp_org_v1') . '" target="_blank" rel="noopener noreferrer">caidance.ai/snapshot</a>'
                );
                ?>
            </p>
        </div>
        <?php
    }

    /**
     * Renders the Run-scan surface: empty state if no industry is set,
     * otherwise the button + container for results + the latest scan's
     * results pulled from storage (so a refresh shows what was already
     * scanned).
     */
    private function renderScanSection(string $industry): void
    {
        if ($industry === '') {
            ?>
            <p><em><?php esc_html_e('Pick your industry above before running a scan.', 'caidance-ai-readiness'); ?></em></p>
            <?php
            return;
        }

        $latest = (new ScanHistoryRepository())->getLatest();

        $endpoint = rest_url('caidance-air/v1/scan');
        $nonce    = wp_create_nonce('wp_rest');
        ?>
        <p>
            <button
                type="button"
                id="caidance-air-run-scan"
                class="button button-primary"
                data-endpoint="<?php echo esc_attr($endpoint); ?>"
                data-nonce="<?php echo esc_attr($nonce); ?>"
                data-label-idle="<?php esc_attr_e('Run scan now', 'caidance-ai-readiness'); ?>"
                data-label-running="<?php esc_attr_e('Scanning…', 'caidance-ai-readiness'); ?>"
            >
                <?php esc_html_e('Run scan now', 'caidance-ai-readiness'); ?>
            </button>
            <span id="caidance-air-scan-status" style="margin-left:10px;color:#646970;"></span>
        </p>

        <div id="caidance-air-results">
            <?php
            if (is_array($latest) && isset($latest['results']) && is_array($latest['results'])) {
                echo $this->renderLatestResults($latest); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            } else {
                echo '<p><em>' . esc_html__('No scan has run yet. Click Run scan now to start.', 'caidance-ai-readiness') . '</em></p>';
            }
            ?>
        </div>

        <?php
        // Run-scan JS is registered and enqueued via enqueueAssets()
        // above (admin_enqueue_scripts hook, gated to this settings
        // screen). Source: assets/js/admin-scan.js. The button's
        // data-* attributes carry all runtime config (endpoint, nonce,
        // labels) — no localize call needed.
    }

    /**
     * Builds the HTML for a stored scan result: score badge + per-check
     * rows. Returns raw HTML for echoing (caller is responsible).
     *
     * @param array<string, mixed> $latest
     */
    private function renderLatestResults(array $latest): string
    {
        $score   = (int) ($latest['total_score'] ?? 0);
        $max     = (int) ($latest['max_possible'] ?? 0);
        $band    = (string) ($latest['band'] ?? 'starter');
        $ranAt   = (string) ($latest['ran_at'] ?? '');
        $results = is_array($latest['results'] ?? null) ? $latest['results'] : [];

        $html  = '<h3 style="margin-top:24px;">Latest scan</h3>';
        $html .= '<p style="color:#646970;">Ran at <code>' . esc_html($ranAt) . '</code></p>';
        $html .= '<p>' . ResultRenderer::renderScoreBadge($score, $max, $band) . '</p>';

        foreach ($results as $result) {
            if (is_array($result)) {
                $html .= ResultRenderer::renderResultRow($result);
            }
        }

        return $html;
    }

    /**
     * Shows the post-activation welcome notice in wp-admin until an
     * industry is picked. Suppressed on our own settings page (they are
     * already there) and once the notice is dismissed.
     */
    public function maybeShowWelcomeNotice(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen !== null && $screen->id === 'settings_page_' . self::MENU_SLUG) {
            return;
        }

        $industry  = (string) get_option('caidance_air_industry', '');
        $dismissed = get_option('caidance_air_welcome_dismissed', '0') === '1';
        if ($industry !== '' || $dismissed) {
            return;
        }

        $settingsUrl = admin_url('options-general.php?page=' . self::MENU_SLUG);
        ?>
        <div class="notice notice-info is-dismissible">
            <p>
                <strong><?php esc_html_e('Caidance is ready to scan.', 'caidance-ai-readiness'); ?></strong>
                <?php
                printf(
                    /* translators: %s is an HTML link to the Settings → Caidance page. */
                    esc_html__('Pick your industry on %s to get started.', 'caidance-ai-readiness'),
                    '<a href="' . esc_url($settingsUrl) . '">' . esc_html__('Settings → Caidance', 'caidance-ai-readiness') . '</a>'
                );
                ?>
            </p>
        </div>
        <?php
    }
}
