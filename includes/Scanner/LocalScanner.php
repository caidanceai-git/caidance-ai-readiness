<?php
/**
 * Scanner orchestrator.
 *
 * Runs registered checks against the active site, sums their scores, and
 * maps the total to a band. Also exposes a buildDefault() composer so
 * Bootstrap and the WP-CLI entrypoint share the same check composition,
 * and a cliRun() entrypoint registered as `wp caidance-air scan`.
 *
 * Bands follow the canonical CDI 0–60 scale:
 *   0–14  Starter   (very limited AI readiness)
 *   15–29 Builder   (some signals present)
 *   30–44 Operator  (most signals present)
 *   45–60 Leader    (comprehensive AI readiness)
 *
 * @package Caidance\AiReadiness
 */

declare(strict_types=1);

namespace Caidance\AiReadiness\Scanner;

use Caidance\AiReadiness\Http\SiteFetcher;
use Caidance\AiReadiness\Storage\ScanHistoryRepository;
use Caidance\AiReadiness\Scanner\Checks\AiCrawlerCheck;
use Caidance\AiReadiness\Scanner\Checks\ArticleSchemaCheck;
use Caidance\AiReadiness\Scanner\Checks\AuthorSchemaCheck;
use Caidance\AiReadiness\Scanner\Checks\CanonicalCheck;
use Caidance\AiReadiness\Scanner\Checks\CheckInterface;
use Caidance\AiReadiness\Scanner\Checks\FaqSchemaCheck;
use Caidance\AiReadiness\Scanner\Checks\LlmsTxtCheck;
use Caidance\AiReadiness\Scanner\Checks\OpenGraphCheck;
use Caidance\AiReadiness\Scanner\Checks\OrganizationSchemaCheck;
use Caidance\AiReadiness\Scanner\Checks\RobotsSitemapCheck;
use Caidance\AiReadiness\Scanner\Checks\WebSiteSchemaCheck;

final class LocalScanner
{
    public const TARGET_MAX_SCORE = 60;

    /**
     * When this many checks (or more) come back unverified — the scanner
     * blocked by the site's own firewall/CDN — a score computed from the
     * few remaining checks would be meaningless, so the scan reports the
     * score as unavailable instead.
     */
    public const SCORE_UNAVAILABLE_AT = 3;

    public const BAND_STARTER  = 'starter';
    public const BAND_BUILDER  = 'builder';
    public const BAND_OPERATOR = 'operator';
    public const BAND_LEADER   = 'leader';

    /**
     * @var array<int, CheckInterface>
     */
    private array $checks = [];

    public function __construct(private readonly SiteFetcher $fetcher)
    {
    }

    /**
     * Registers a single check with this scanner instance.
     */
    public function registerCheck(CheckInterface $check): void
    {
        $this->checks[] = $check;
    }

    /**
     * Runs all registered checks and returns the full scan output.
     *
     * Before the checks run, BlockageDetector decides whether the
     * scanner itself is being blocked by the site's firewall/CDN (using
     * the homepage + robots.txt fetches the checks share anyway). Checks
     * then report "unverified" instead of a false "fail" for fetches
     * that look blocked. Unverified checks are excluded from BOTH
     * total_score and max_possible, and when SCORE_UNAVAILABLE_AT or
     * more checks are unverified the whole score is reported as
     * unavailable (score_available = false, band = '').
     *
     * @return array{
     *   results: array<int, array{checkId: string, checkLabel: string, status: string, score: int, explanation: string, fixHint: string}>,
     *   total_score: int,
     *   max_possible: int,
     *   target_max: int,
     *   band: string,
     *   checks_run: int,
     *   unverified_count: int,
     *   score_available: bool,
     *   scanner_blocked: bool,
     *   blockage_reason: string,
     *   ran_at: string
     * }
     */
    public function run(): array
    {
        $history        = (new ScanHistoryRepository())->getHistory();
        $blockageReason = BlockageDetector::evaluate($this->fetcher, $history);
        if ($blockageReason !== '') {
            $this->fetcher->flagScannerBlocked($blockageReason);
        }

        $results    = [];
        $total      = 0;
        $unverified = 0;

        foreach ($this->checks as $check) {
            $result    = $check->run();
            $results[] = $result->toArray();
            if ($result->status === CheckResult::STATUS_UNVERIFIED) {
                $unverified++;
            } else {
                $total += $result->score();
            }
        }

        // Blockage discovered mid-scan (e.g. the homepage was readable
        // but post fetches were challenged): surface the first recorded
        // challenge signature as the evidence.
        if ($blockageReason === '' && $unverified > 0) {
            $blockageReason = $this->fetcher->firstChallengeSignal();
        }

        $maxPossible    = (count($this->checks) - $unverified) * 6;
        $scoreAvailable = $unverified < self::SCORE_UNAVAILABLE_AT;

        $band = '';
        if ($scoreAvailable) {
            $bandBasis = $total;
            if ($unverified > 0 && $maxPossible > 0) {
                // Pro-rate to the 0–60 band scale so excluded (blocked)
                // checks cannot drag the band down.
                $bandBasis = (int) round($total * self::TARGET_MAX_SCORE / $maxPossible);
            }
            $band = self::bandForScore($bandBasis);
        }

        return [
            'results'          => $results,
            'total_score'      => $total,
            'max_possible'     => $maxPossible,
            'target_max'       => self::TARGET_MAX_SCORE,
            'band'             => $band,
            'checks_run'       => count($this->checks),
            'unverified_count' => $unverified,
            'score_available'  => $scoreAvailable,
            'scanner_blocked'  => $blockageReason !== '',
            'blockage_reason'  => $blockageReason,
            'ran_at'           => current_time('mysql'),
        ];
    }

    /**
     * Maps a raw 0–60 score to a band on the canonical CDI scale.
     */
    public static function bandForScore(int $score): string
    {
        if ($score >= 45) {
            return self::BAND_LEADER;
        }
        if ($score >= 30) {
            return self::BAND_OPERATOR;
        }
        if ($score >= 15) {
            return self::BAND_BUILDER;
        }
        return self::BAND_STARTER;
    }

    /**
     * Builds a fully-wired scanner with the currently shipped checks,
     * respecting the AI-crawler-check opt-out toggle from Settings.
     *
     * Centralized so Bootstrap, the WP-CLI command, and any future UI
     * trigger all use the same composition.
     */
    public static function buildDefault(): self
    {
        $fetcher = new SiteFetcher();
        $scanner = new self($fetcher);

        $scanner->registerCheck(new LlmsTxtCheck($fetcher));
        $scanner->registerCheck(new RobotsSitemapCheck($fetcher));

        // Schema family — share the cached homepage + recent-post fetches.
        $scanner->registerCheck(new OrganizationSchemaCheck($fetcher));
        $scanner->registerCheck(new WebSiteSchemaCheck($fetcher));
        $scanner->registerCheck(new FaqSchemaCheck($fetcher));
        $scanner->registerCheck(new ArticleSchemaCheck($fetcher));
        $scanner->registerCheck(new AuthorSchemaCheck($fetcher));

        // Meta tag family — reuses the same homepage + 1 recent post fetch.
        $scanner->registerCheck(new OpenGraphCheck($fetcher));
        $scanner->registerCheck(new CanonicalCheck($fetcher));

        $aiCrawlerEnabled = get_option('caidance_air_ai_crawler_check_enabled', '1') === '1';
        if ($aiCrawlerEnabled) {
            $scanner->registerCheck(new AiCrawlerCheck($fetcher));
        }

        return $scanner;
    }

    /**
     * WP-CLI entrypoint. Registered in Bootstrap as `wp caidance-air scan`.
     * Runs a scan and prints the result as pretty JSON.
     *
     * @param array<int, string> $args
     * @param array<string, string> $assoc_args
     */
    public static function cliRun(array $args, array $assoc_args): void
    {
        $scanner = self::buildDefault();
        $output  = $scanner->run();

        if (defined('WP_CLI') && WP_CLI && class_exists('\WP_CLI')) {
            \WP_CLI::line(
                (string) wp_json_encode(
                    $output,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                )
            );
            if (!empty($output['scanner_blocked'])) {
                \WP_CLI::warning(sprintf(
                    'Scanner blocked — %d check(s) unverified and excluded from the score. Evidence: %s',
                    (int) ($output['unverified_count'] ?? 0),
                    (string) ($output['blockage_reason'] ?? '')
                ));
            }
            \WP_CLI::success(sprintf(
                'Scan complete. Score: %d / %d (%d of %d checks run). Band: %s.',
                (int) $output['total_score'],
                (int) $output['max_possible'],
                (int) $output['checks_run'],
                (int) (self::TARGET_MAX_SCORE / 6),
                (string) $output['band']
            ));
        }
    }
}
