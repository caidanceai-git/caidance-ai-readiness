<?php
/**
 * Checks for the presence of /llms.txt on the site.
 *
 * llms.txt is the emerging standard for telling LLM agents where to find
 * a site's machine-readable summary plus its most important pages. It is
 * the most direct AI-specific signal a site can publish.
 *
 *   pass    = llms.txt is served and has substantial content
 *   partial = llms.txt is served but empty or very short
 *   fail    = llms.txt does not respond
 *
 * @package Caidance\AiReadiness
 */

declare(strict_types=1);

namespace Caidance\AiReadiness\Scanner\Checks;

use Caidance\AiReadiness\Scanner\CheckResult;

final class LlmsTxtCheck extends AbstractCheck
{
    private const MINIMUM_USEFUL_LENGTH = 200;

    public function id(): string
    {
        return 'llms_txt';
    }

    public function label(): string
    {
        return 'llms.txt is published';
    }

    public function run(): CheckResult
    {
        $response = $this->fetcher->get($this->fetcher->urlFor('/llms.txt'));

        if (!$response['ok']) {
            if ($this->fetchLooksBlocked($response)) {
                return $this->unverified(
                    'Could not verify llms.txt: the scan request appears to be blocked by your firewall or CDN, so this check is excluded from your score.'
                );
            }
            return $this->fail(
                'Your site does not serve an /llms.txt file. AI agents look for this file first to understand what your business is and where to find your most important pages.',
                'Create a plain-text llms.txt at the root of your site that tells AI agents what your business is and links to your most important pages.'
            );
        }

        $body = trim($response['body']);

        if ($body === '') {
            return $this->partial(
                'An /llms.txt file is served but it is empty. AI agents see the file but get no useful guidance from it.',
                'Add a one-paragraph summary of your business and links to your most important pages.'
            );
        }

        if (strlen($body) < self::MINIMUM_USEFUL_LENGTH) {
            return $this->partial(
                'An /llms.txt file is served but it is very short and may not give AI agents enough context.',
                'Expand llms.txt to include a clear business summary, your top services or products, and links to your most important pages.'
            );
        }

        return $this->pass(
            'Your site publishes an /llms.txt file with content. AI agents can read your guidance directly.'
        );
    }
}
