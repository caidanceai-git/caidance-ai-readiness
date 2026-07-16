<?php
/**
 * Stack Sense: reads the site's active plugins against a curated table
 * and maps them into the Caidance alignment taxonomy.
 *
 * Read-only, local, zero remote calls — the same privacy stance as the
 * scanner. Observations are QUALITATIVE ONLY and follow the normalized
 * signal contract (docs/connector-spec.md in the caidance.ai repo):
 * sensors observe, they never score. The real Alignment Score comes
 * from the 21-question assessment on caidance.ai — no local imitation
 * of it exists here, by design.
 *
 * @package Caidance\AiReadiness
 */

declare(strict_types=1);

namespace Caidance\AiReadiness\Scanner;

final class StackDetector
{
    public const SOURCE = 'wp_stack';

    public const CATEGORY_LABELS = [
        'ecommerce'         => 'eCommerce',
        'forms'             => 'Forms & intake',
        'crm'               => 'CRM',
        'email-esp'         => 'Email marketing',
        'lms-membership'    => 'Courses & membership',
        'automation'        => 'Automation',
        'seo'               => 'SEO',
        'security-backup'   => 'Security & backup',
        'analytics'         => 'Analytics',
        'accounting-bridge' => 'Accounting bridge',
    ];

    /**
     * Curated slug table: plugin directory slug => [display name, category].
     * Names are fallbacks — the installed plugin's own header name wins
     * when readable.
     */
    private const KNOWN_PLUGINS = [
        // eCommerce
        'woocommerce'            => ['WooCommerce', 'ecommerce'],
        'easy-digital-downloads' => ['Easy Digital Downloads', 'ecommerce'],
        'surecart'               => ['SureCart', 'ecommerce'],
        'ecwid-shopping-cart'    => ['Ecwid', 'ecommerce'],
        // Forms & intake
        'gravityforms'           => ['Gravity Forms', 'forms'],
        'wpforms-lite'           => ['WPForms', 'forms'],
        'wpforms'                => ['WPForms', 'forms'],
        'fluentform'             => ['Fluent Forms', 'forms'],
        'ninja-forms'            => ['Ninja Forms', 'forms'],
        'contact-form-7'         => ['Contact Form 7', 'forms'],
        'formidable'             => ['Formidable Forms', 'forms'],
        'forminator'             => ['Forminator', 'forms'],
        // CRM
        'fluent-crm'             => ['FluentCRM', 'crm'],
        'zero-bs-crm'            => ['Jetpack CRM', 'crm'],
        'leadin'                 => ['HubSpot', 'crm'],
        'wp-fusion'              => ['WP Fusion', 'crm'],
        'wp-fusion-lite'         => ['WP Fusion Lite', 'crm'],
        'groundhogg'             => ['Groundhogg', 'crm'],
        // Email / ESP bridges
        'mailchimp-for-wp'       => ['Mailchimp for WP', 'email-esp'],
        'mailpoet'               => ['MailPoet', 'email-esp'],
        'mailin'                 => ['Brevo', 'email-esp'],
        'newsletter'             => ['The Newsletter Plugin', 'email-esp'],
        'convertkit'             => ['Kit (ConvertKit)', 'email-esp'],
        // LMS / membership
        'sfwd-lms'               => ['LearnDash', 'lms-membership'],
        'lifterlms'              => ['LifterLMS', 'lms-membership'],
        'tutor'                  => ['Tutor LMS', 'lms-membership'],
        'learnpress'             => ['LearnPress', 'lms-membership'],
        'memberpress'            => ['MemberPress', 'lms-membership'],
        'paid-memberships-pro'   => ['Paid Memberships Pro', 'lms-membership'],
        'restrict-content-pro'   => ['Restrict Content Pro', 'lms-membership'],
        // Automation
        'uncanny-automator'      => ['Uncanny Automator', 'automation'],
        'suretriggers'           => ['SureTriggers', 'automation'],
        'zapier'                 => ['Zapier', 'automation'],
        'wp-webhooks'            => ['WP Webhooks', 'automation'],
        'automatorwp'            => ['AutomatorWP', 'automation'],
        'flowmattic'             => ['FlowMattic', 'automation'],
        // SEO
        'wordpress-seo'          => ['Yoast SEO', 'seo'],
        'seo-by-rank-math'       => ['Rank Math SEO', 'seo'],
        'all-in-one-seo-pack'    => ['All in One SEO', 'seo'],
        'autodescription'        => ['The SEO Framework', 'seo'],
        'wp-seopress'            => ['SEOPress', 'seo'],
        'slim-seo'               => ['Slim SEO', 'seo'],
        'squirrly-seo'           => ['Squirrly SEO', 'seo'],
        // Security & backup
        'wordfence'              => ['Wordfence', 'security-backup'],
        'sucuri-scanner'         => ['Sucuri Security', 'security-backup'],
        'better-wp-security'     => ['Solid Security', 'security-backup'],
        'all-in-one-wp-security-and-firewall' => ['All-In-One Security', 'security-backup'],
        'updraftplus'            => ['UpdraftPlus', 'security-backup'],
        'backwpup'               => ['BackWPup', 'security-backup'],
        'duplicator'             => ['Duplicator', 'security-backup'],
        // Analytics
        'google-site-kit'        => ['Site Kit by Google', 'analytics'],
        'google-analytics-for-wordpress' => ['MonsterInsights', 'analytics'],
        'ga-google-analytics'    => ['GA Google Analytics', 'analytics'],
        'matomo'                 => ['Matomo Analytics', 'analytics'],
        'burst-statistics'       => ['Burst Statistics', 'analytics'],
        'independent-analytics'  => ['Independent Analytics', 'analytics'],
        // Accounting bridges
        'myworks-woo-sync-for-quickbooks-online' => ['MyWorks QuickBooks Sync', 'accounting-bridge'],
        'wp-ever-accounting'     => ['WP Ever Accounting', 'accounting-bridge'],
        'sliced-invoices'        => ['Sliced Invoices', 'accounting-bridge'],
    ];

    /**
     * Active plugins matched against the curated table.
     *
     * @return array<int, array{slug: string, name: string, category: string}>
     */
    public function inventory(): array
    {
        $active = get_option('active_plugins', []);
        if (!is_array($active)) {
            return [];
        }

        $headers = $this->pluginHeaders();
        $items   = [];
        $seen    = [];

        foreach ($active as $pluginFile) {
            $pluginFile = (string) $pluginFile;
            $slug       = dirname($pluginFile);
            if ($slug === '.') {
                $slug = basename($pluginFile, '.php');
            }
            if (!isset(self::KNOWN_PLUGINS[$slug]) || isset($seen[$slug])) {
                continue;
            }
            $seen[$slug] = true;

            $headerName = isset($headers[$pluginFile]['Name']) ? trim((string) $headers[$pluginFile]['Name']) : '';
            $items[]    = [
                'slug'     => $slug,
                'name'     => $headerName !== '' ? $headerName : self::KNOWN_PLUGINS[$slug][0],
                'category' => self::KNOWN_PLUGINS[$slug][1],
            ];
        }

        return $items;
    }

    /**
     * Inventory grouped by category: category key => list of names.
     *
     * @param array<int, array{slug: string, name: string, category: string}> $inventory
     * @return array<string, array<int, string>>
     */
    public function categories(array $inventory): array
    {
        $grouped = [];
        foreach ($inventory as $item) {
            $grouped[$item['category']][] = $item['name'];
        }
        return $grouped;
    }

    /**
     * Qualitative observations in the normalized signal shape
     * (docs/connector-spec.md). Never a score. Capped at five.
     *
     * @param array<int, array{slug: string, name: string, category: string}> $inventory
     * @param array<string, mixed>|null $latestScan The most recent stored scan.
     * @return array<int, array{signal_id: string, source_system: string, category: string, severity: string, evidence: string, recommended_fix: string}>
     */
    public function signals(array $inventory, ?array $latestScan): array
    {
        $byCategory = $this->categories($inventory);
        $signals    = [];

        if (count($byCategory['forms'] ?? []) >= 2) {
            $names     = implode(' and ', array_slice($byCategory['forms'], 0, 2));
            $signals[] = $this->signal(
                'two-form-plugins',
                'forms',
                'notice',
                $names . ' are both active. Intake data is likely split across two systems — an AI operator sees two sources of truth for leads.',
                'Consolidate intake to one form system, or make sure both feed the same destination.'
            );
        }

        if (!isset($byCategory['crm'])) {
            $signals[] = $this->signal(
                'no-crm-detected',
                'crm',
                'notice',
                'No CRM plugin is active on this WordPress site.',
                'If your CRM lives outside WordPress, fine — but if leads live in an inbox or a spreadsheet, that is the single most common alignment gap.'
            );
        }

        if (isset($byCategory['automation'])) {
            $signals[] = $this->signal(
                'automation-present',
                'automation',
                'notice',
                $byCategory['automation'][0] . ' is wiring your tools together.',
                'Automation compounds whatever alignment you have — misaligned data flows faster too. Alignment matters more here, not less.'
            );
        }

        if (isset($byCategory['seo']) && $this->schemaChecksFailing($latestScan)) {
            $signals[] = $this->signal(
                'seo-plugin-schema-failing',
                'seo',
                'warning',
                $byCategory['seo'][0] . ' is installed, but this site\'s schema checks are still not passing — the tool is present, the output is missing.',
                'Check its schema / structured-data settings, or use the fix hints on the Scan tab.'
            );
        }

        if (isset($byCategory['ecommerce']) && !isset($byCategory['accounting-bridge'])) {
            $signals[] = $this->signal(
                'ecommerce-no-accounting-bridge',
                'accounting-bridge',
                'notice',
                $byCategory['ecommerce'][0] . ' is selling, but no accounting bridge is detected.',
                'If order data is re-keyed by hand into the books, every manual step is an alignment seam.'
            );
        }

        if ($signals === []) {
            $signals[] = $this->signal(
                'lean-stack',
                'general',
                'info',
                'A lean stack — fewer systems, fewer seams.',
                'The alignment question for a lean stack is whether the few systems you do have talk to each other.'
            );
        }

        return array_slice($signals, 0, 5);
    }

    /**
     * Whitelisted prefill params for the Alignment Assessment deep-link.
     * Only values that exist in the assessment form's option vocabulary
     * are ever sent — anything else is omitted, never guessed.
     *
     * @param array<int, array{slug: string, name: string, category: string}> $inventory
     * @return array<string, string>
     */
    public function prefillParams(array $inventory): array
    {
        $params = ['stack_website' => 'wordpress'];

        $slugs = array_column($inventory, 'slug');

        $byCategory = $this->categories($inventory);
        if (isset($byCategory['ecommerce'])) {
            $params['stack_ecommerce'] = 'website-ecommerce';
        }
        if (in_array('leadin', $slugs, true)) {
            $params['stack_crm'] = 'hubspot';
        }
        if (in_array('myworks-woo-sync-for-quickbooks-online', $slugs, true)) {
            $params['stack_accounting'] = 'quickbooks';
        }

        return $params;
    }

    /**
     * @return array{signal_id: string, source_system: string, category: string, severity: string, evidence: string, recommended_fix: string}
     */
    private function signal(string $id, string $category, string $severity, string $evidence, string $fix): array
    {
        return [
            'signal_id'       => $id,
            'source_system'   => self::SOURCE,
            'category'        => $category,
            'severity'        => $severity,
            'evidence'        => $evidence,
            'recommended_fix' => $fix,
        ];
    }

    /**
     * True when the latest stored scan shows either homepage schema
     * check (Organization / WebSite) not passing.
     *
     * @param array<string, mixed>|null $latestScan
     */
    private function schemaChecksFailing(?array $latestScan): bool
    {
        if (!is_array($latestScan)) {
            return false;
        }
        foreach (($latestScan['results'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $checkId = (string) ($row['checkId'] ?? '');
            if (in_array($checkId, ['organization_schema', 'website_schema'], true)
                && ($row['status'] ?? '') !== 'pass'
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Installed-plugin headers keyed by plugin file, or [] when the
     * admin plugin API is unavailable.
     *
     * @return array<string, array<string, string>>
     */
    private function pluginHeaders(): array
    {
        if (!function_exists('get_plugins')) {
            $file = ABSPATH . 'wp-admin/includes/plugin.php';
            if (is_readable($file)) {
                require_once $file;
            }
        }
        if (!function_exists('get_plugins')) {
            return [];
        }
        $plugins = get_plugins();
        return is_array($plugins) ? $plugins : [];
    }
}
