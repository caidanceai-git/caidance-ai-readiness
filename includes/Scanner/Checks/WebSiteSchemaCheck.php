<?php
/**
 * Checks the homepage for WebSite JSON-LD schema with a SearchAction.
 *
 * WebSite schema tells AI agents the canonical site structure. The
 * potentialAction → SearchAction inside it lets AI agents query the
 * site as a search target (and surfaces a "sitelinks search box" in
 * Google).
 *
 *   pass    = WebSite schema with a SearchAction in potentialAction
 *   partial = WebSite schema but no SearchAction
 *   fail    = No WebSite schema at all
 *
 * @package Caidance\AiReadiness
 */

declare(strict_types=1);

namespace Caidance\AiReadiness\Scanner\Checks;

use Caidance\AiReadiness\Html\JsonLdExtractor;
use Caidance\AiReadiness\Scanner\CheckResult;

final class WebSiteSchemaCheck extends AbstractCheck
{
    public function id(): string
    {
        return 'website_schema';
    }

    public function label(): string
    {
        return 'WebSite schema with SearchAction';
    }

    public function run(): CheckResult
    {
        $home = $this->fetcher->get($this->fetcher->homeUrl());

        if (!$home['ok']) {
            if ($this->fetchLooksBlocked($home)) {
                return $this->unverified(
                    'Could not verify WebSite schema: the homepage scan request appears to be blocked by your firewall or CDN, so this check is excluded from your score.'
                );
            }
            return $this->fail(
                'Cannot fetch your homepage to inspect schema.',
                'Verify your homepage is publicly accessible.'
            );
        }

        $nodes = JsonLdExtractor::extract($home['body']);
        $sites = JsonLdExtractor::findByType($nodes, 'WebSite');

        if ($sites === []) {
            return $this->fail(
                'Your homepage does not include WebSite schema. AI agents use this to map your site.',
                'Add a WebSite JSON-LD block to your homepage. SEO plugins generate this automatically.'
            );
        }

        foreach ($sites as $site) {
            if ($this->hasSearchAction($site)) {
                return $this->pass(
                    'Your homepage publishes WebSite schema with a SearchAction. AI agents can query your site directly.'
                );
            }
        }

        return $this->partial(
            'WebSite schema is present but no SearchAction is declared. AI agents cannot use a search action to find your content.',
            'Add a potentialAction → SearchAction to your WebSite schema. SEO plugins often expose this as a "sitelinks search box" setting.'
        );
    }

    /**
     * potentialAction can be either a single action object OR an array
     * of action objects. Type matching is case-insensitive.
     *
     * @param array<string, mixed> $site
     */
    private function hasSearchAction(array $site): bool
    {
        $action = $site['potentialAction'] ?? null;
        if (!is_array($action)) {
            return false;
        }

        // Single-action shape: action has @type directly.
        if (isset($action['@type'])) {
            return $this->typeIncludes($action['@type'], 'SearchAction');
        }

        // Array-of-actions shape.
        foreach ($action as $candidate) {
            if (is_array($candidate) && isset($candidate['@type'])
                && $this->typeIncludes($candidate['@type'], 'SearchAction')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $type Could be a string or an array of strings.
     */
    private function typeIncludes(mixed $type, string $expected): bool
    {
        $target = strtolower($expected);

        if (is_string($type)) {
            return strtolower($type) === $target;
        }

        if (is_array($type)) {
            foreach ($type as $value) {
                if (strtolower((string) $value) === $target) {
                    return true;
                }
            }
        }

        return false;
    }
}
