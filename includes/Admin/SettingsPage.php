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

final class SettingsPage
{
    public const MENU_SLUG    = 'caidance-ai-readiness';
    private const NONCE_ACTION = 'caidance_air_save_settings';

    /**
     * The 11 Caidance industries — mirrors the parent set in the
     * mu-plugin Industries constant. Sub-verticals are not exposed in v1.
     *
     * @var array<string, string>
     */
    private const INDUSTRIES = [
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
            <p>
                <?php esc_html_e('The scan engine ships in the next plugin update. Your industry selection above will be applied to your first scan.', 'caidance-ai-readiness'); ?>
            </p>

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
