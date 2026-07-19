<?php
/**
 * Checks the homepage for Organization JSON-LD schema.
 *
 * Organization schema is how AI agents identify the entity behind a
 * website. The minimum useful set is name + url + logo.
 *
 *   pass    = Organization schema with name, url, and logo
 *   partial = Organization schema present but missing logo (or only
 *             name + url, no logo)
 *   partial = Organization schema present with name only
 *   fail    = No Organization schema on the homepage
 *
 * @package Caidance\AiReadiness
 */

declare(strict_types=1);

namespace Caidance\AiReadiness\Scanner\Checks;

use Caidance\AiReadiness\Html\JsonLdExtractor;
use Caidance\AiReadiness\Scanner\CheckResult;

final class OrganizationSchemaCheck extends AbstractCheck
{
    public function id(): string
    {
        return 'organization_schema';
    }

    public function label(): string
    {
        return 'Organization schema on homepage';
    }

    public function run(): CheckResult
    {
        $home = $this->fetcher->get($this->fetcher->homeUrl());

        if (!$home['ok']) {
            if ($this->fetchLooksBlocked($home)) {
                return $this->unverified(
                    'Could not verify Organization schema: the homepage scan request appears to be blocked by your firewall or CDN, so this check is excluded from your score.'
                );
            }
            return $this->fail(
                'Cannot fetch your homepage to inspect schema.',
                'Verify your homepage is publicly accessible (no auth wall, no firewall blocking).'
            );
        }

        $nodes = JsonLdExtractor::extract($home['body']);
        $orgs  = JsonLdExtractor::findByType($nodes, 'Organization');

        if ($orgs === []) {
            return $this->fail(
                'Your homepage does not include Organization schema. AI agents need this to identify your business.',
                'Add an Organization JSON-LD block to your homepage with name, url, and logo. An SEO plugin like Yoast or Rank Math can generate this automatically.'
            );
        }

        $first    = $orgs[0];
        $hasName  = $this->hasNonEmpty($first, 'name');
        $hasUrl   = $this->hasNonEmpty($first, 'url');
        $hasLogo  = $this->hasNonEmpty($first, 'logo');

        if ($hasName && $hasUrl && $hasLogo) {
            return $this->pass(
                'Your homepage includes complete Organization schema with name, URL, and logo. AI agents can clearly identify your business.'
            );
        }

        if ($hasName && $hasUrl) {
            return $this->partial(
                'Organization schema is present with name and URL but no logo. AI agents see who you are but not your visual identity.',
                'Add a logo field to your Organization schema. Most SEO plugins offer this in their organization or "Knowledge Graph" settings.'
            );
        }

        return $this->partial(
            'Organization schema is present but missing key fields.',
            'Ensure your Organization schema includes at minimum: name, url, and logo.'
        );
    }

    /**
     * @param array<string, mixed> $node
     */
    private function hasNonEmpty(array $node, string $key): bool
    {
        if (!isset($node[$key])) {
            return false;
        }
        $value = $node[$key];
        if (is_string($value)) {
            return trim($value) !== '';
        }
        if (is_array($value)) {
            return $value !== [];
        }
        return $value !== null;
    }
}
