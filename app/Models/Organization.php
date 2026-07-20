<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Db;

/**
 * Organization (Model) — tenants. A ticket is tagged with an organization_id and routed
 * to that organization's agents; each agent is linked to one organization. Admins are
 * cross-organization. Deleting an organization nulls it out on its agents and tickets
 * (handled in OrganizationActions; tickets also have ON DELETE SET NULL).
 */
final class Organization
{
    public static function all(): array
    {
        return Db::queryAll('SELECT organization_id, name, active, created_at FROM organizations ORDER BY name');
    }

    /** Active organizations for the ticket-form dropdown. */
    public static function allActive(): array
    {
        return Db::queryAll("SELECT organization_id, name FROM organizations WHERE active = 1 ORDER BY name");
    }

    public static function find(string $organizationId): ?array
    {
        return Db::queryOne('SELECT * FROM organizations WHERE organization_id = :o', [':o' => $organizationId]);
    }

    /** True if the id names an ACTIVE organization (used to validate a submitted org). */
    public static function existsActive(string $organizationId): bool
    {
        return (int) Db::scalar(
            'SELECT COUNT(*) FROM organizations WHERE organization_id = :o AND active = 1',
            [':o' => $organizationId]
        ) > 0;
    }

    public static function nameExists(string $name, string $exceptId = ''): bool
    {
        return (int) Db::scalar(
            'SELECT COUNT(*) FROM organizations WHERE name = :n AND organization_id <> :x',
            [':n' => $name, ':x' => $exceptId]
        ) > 0;
    }

    public static function create(array $data): string
    {
        $id = self::nextOrganizationId();
        Db::insert('organizations', [
            'organization_id' => $id,
            'name'            => $data['name'],
            'active'          => !empty($data['active']) || !isset($data['active']) ? 1 : 0,
            'created_at'      => gmdate('Y-m-d H:i:s'),
        ]);
        return $id;
    }

    /** @param array<string,mixed> $fields */
    public static function update(string $organizationId, array $fields): void
    {
        Db::update('organizations', $fields, 'organization_id = :o', [':o' => $organizationId]);
    }

    public static function delete(string $organizationId): void
    {
        Db::delete('organizations', 'organization_id = :o', [':o' => $organizationId]);
    }

    public static function nextOrganizationId(): string
    {
        $max = Db::scalar("SELECT MAX(CAST(SUBSTRING_INDEX(organization_id, :dash, -1) AS UNSIGNED)) FROM organizations", [':dash' => '-']);
        return sprintf('ORG-%04d', ((int) $max) + 1);
    }
}
