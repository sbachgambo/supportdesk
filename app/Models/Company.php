<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Db;

/**
 * Company (Model) — thin repository over the admin-managed `companies` suggestion list.
 * Tickets store the company as free text; this table only powers the type-or-pick
 * datalist and the admin management tab. No FK from tickets (free text is allowed).
 */
final class Company
{
    public static function all(): array
    {
        return Db::queryAll('SELECT company_id, name, active, created_at FROM companies ORDER BY name');
    }

    /** Active companies for the form datalist. */
    public static function allActive(): array
    {
        return Db::queryAll("SELECT company_id, name FROM companies WHERE active = 1 ORDER BY name");
    }

    public static function find(string $companyId): ?array
    {
        return Db::queryOne('SELECT * FROM companies WHERE company_id = :c', [':c' => $companyId]);
    }

    public static function nameExists(string $name, string $exceptId = ''): bool
    {
        return (int) Db::scalar(
            'SELECT COUNT(*) FROM companies WHERE name = :n AND company_id <> :x',
            [':n' => $name, ':x' => $exceptId]
        ) > 0;
    }

    public static function create(array $data): string
    {
        $id = self::nextCompanyId();
        Db::insert('companies', [
            'company_id' => $id,
            'name'       => $data['name'],
            'active'     => !empty($data['active']) || !isset($data['active']) ? 1 : 0,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return $id;
    }

    /** @param array<string,mixed> $fields */
    public static function update(string $companyId, array $fields): void
    {
        Db::update('companies', $fields, 'company_id = :c', [':c' => $companyId]);
    }

    public static function delete(string $companyId): void
    {
        Db::delete('companies', 'company_id = :c', [':c' => $companyId]);
    }

    public static function nextCompanyId(): string
    {
        $max = Db::scalar("SELECT MAX(CAST(SUBSTRING_INDEX(company_id, :dash, -1) AS UNSIGNED)) FROM companies", [':dash' => '-']);
        return sprintf('CO-%04d', ((int) $max) + 1);
    }
}
