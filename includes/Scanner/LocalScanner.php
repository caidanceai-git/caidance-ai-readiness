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
use Caidance\AiReadiness\Scanner\Checks\AiCrawlerCheck;
use Caidance\AiReadiness\Scanner\Checks\CheckInterface;
use Caidance\AiReadiness\Scanner\Checks\LlmsTxtCheck;
use Caidance\AiReadiness\Scanner\Checks\RobotsSitemapCheck;

final class LocalScanner
{
    public const TARGET_MAX_SCORE = 60;

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
     * @return array{
     *   results: array<int, array{checkId: string, checkLabel: string, status: string, score: int, explanation: string, fixHint: string}>,
     *   total_score: int,
     *   max_possible: int,
     *   target_max: int,
     *   band: string,
     *   checks_run: int,
     *   ran_at: string
     * }
     */
    public function run(): array
    {
        $results = [];
        $total   = 0;

        foreach ($this->checks as $check) {
            $result    = $check->run();
            $results[] = $result->toArray();
            $total    += $result->score();
        }

        return [
            'results'      => $results,
            'total_score'  => $total,
            'max_possible' => count($this->checks) * 6,
            'target_max'   => self::TARGET_MAX_SCORE,
            'band'         => self::bandForScore($total),
            'checks_run'   => count($this->checks),
            'ran_at'       => current_time('mysql'),
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
