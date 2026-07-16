<?php
/**
 * Schema fix for the Organization check: outputs an Organization
 * JSON-LD node on the homepage, built live from real site settings.
 * All mechanics live in AbstractSchemaFixer.
 *
 * @package Caidance\AiReadiness
 */

declare(strict_types=1);

namespace Caidance\AiReadiness\Fixer;

final class OrganizationSchemaFixer extends AbstractSchemaFixer
{
    public const CHECK_ID = 'organization_schema';

    public function id(): string
    {
        return self::CHECK_ID;
    }

    public function label(): string
    {
        return 'Organization schema';
    }

    protected function enabledOption(): string
    {
        return SchemaOutputter::ORG_OPTION;
    }

    protected function markerOption(): string
    {
        return 'caidance_air_org_schema_marker';
    }

    protected function schemaType(): string
    {
        return 'Organization';
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildNode(): array
    {
        return SchemaBuilder::organizationNode();
    }

    protected function completenessNote(): string
    {
        if (SchemaBuilder::logoUrl() !== '') {
            return '';
        }
        return __('Note: no site logo or icon is set, so the schema ships without one (name + URL still helps — the check will read as partial). Add a logo under Appearance → Customize → Site Identity and the markup picks it up automatically.', 'caidance-ai-readiness');
    }
}
