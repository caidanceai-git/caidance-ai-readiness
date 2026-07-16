<?php
/**
 * Schema fix for the WebSite check: outputs a WebSite JSON-LD node
 * (with a SearchAction pointing at WordPress's native ?s= search) on
 * the homepage. All mechanics live in AbstractSchemaFixer.
 *
 * @package Caidance\AiReadiness
 */

declare(strict_types=1);

namespace Caidance\AiReadiness\Fixer;

final class WebSiteSchemaFixer extends AbstractSchemaFixer
{
    public const CHECK_ID = 'website_schema';

    public function id(): string
    {
        return self::CHECK_ID;
    }

    public function label(): string
    {
        return 'WebSite schema';
    }

    protected function enabledOption(): string
    {
        return SchemaOutputter::SITE_OPTION;
    }

    protected function markerOption(): string
    {
        return 'caidance_air_website_schema_marker';
    }

    protected function schemaType(): string
    {
        return 'WebSite';
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildNode(): array
    {
        return SchemaBuilder::webSiteNode();
    }

    protected function completenessNote(): string
    {
        return '';
    }
}
