<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Db;

/**
 * Category (Model) — two-level categories with referential integrity.
 * Two levels ONLY: a child's parent must itself be top-level (parent_id NULL).
 */
final class Category
{
    /** All active categories, top-level and children, ordered for display. */
    public static function allActive(): array
    {
        return Db::queryAll(
            'SELECT category_id, name, color, parent_id
             FROM categories WHERE active = 1
             ORDER BY (parent_id IS NOT NULL), name'
        );
    }

    /** Every category (incl. inactive) for admin management. */
    public static function all(): array
    {
        return Db::queryAll(
            'SELECT category_id, name, description, color, active, parent_id, created_at
             FROM categories ORDER BY (parent_id IS NOT NULL), name'
        );
    }

    public static function find(string $categoryId): ?array
    {
        return Db::queryOne('SELECT * FROM categories WHERE category_id = :c', [':c' => $categoryId]);
    }

    public static function existsActive(string $categoryId): bool
    {
        return Db::queryOne('SELECT 1 FROM categories WHERE category_id = :c AND active = 1', [':c' => $categoryId]) !== null;
    }

    public static function isTopLevel(string $categoryId): bool
    {
        $row = Db::queryOne('SELECT parent_id FROM categories WHERE category_id = :c', [':c' => $categoryId]);
        return $row !== null && $row['parent_id'] === null;
    }

    public static function nextCategoryId(): string
    {
        $max = Db::scalar(
            "SELECT MAX(CAST(SUBSTRING_INDEX(category_id, :dash, -1) AS UNSIGNED)) FROM categories",
            [':dash' => '-']
        );
        return sprintf('CAT-%03d', ((int) $max) + 1);
    }

    public static function create(array $data): string
    {
        $id = self::nextCategoryId();
        Db::insert('categories', [
            'category_id' => $id,
            'name'        => $data['name'],
            'description' => $data['description'] ?? '',
            'color'       => $data['color'] ?? '#4057F5',
            'active'      => !empty($data['active']) ? 1 : 1,
            'parent_id'   => $data['parent_id'] ?? null,
            'created_at'  => gmdate('Y-m-d H:i:s'),
        ]);
        return $id;
    }

    /** @param array<string,mixed> $fields */
    public static function update(string $categoryId, array $fields): void
    {
        Db::update('categories', $fields, 'category_id = :c', [':c' => $categoryId]);
    }

    public static function delete(string $categoryId): void
    {
        Db::delete('categories', 'category_id = :c', [':c' => $categoryId]);
    }

    // ── referential-integrity checks (delete guards, §3) ─────────────────────
    public static function childCount(string $categoryId): int
    {
        return (int) Db::scalar('SELECT COUNT(*) FROM categories WHERE parent_id = :c', [':c' => $categoryId]);
    }

    public static function ticketCount(string $categoryId): int
    {
        return (int) Db::scalar('SELECT COUNT(*) FROM tickets WHERE category_id = :c', [':c' => $categoryId]);
    }

    /** Routing rules referencing this category id in their conditions/actions JSON. */
    public static function ruleReferenceCount(string $categoryId): int
    {
        $like = '%' . $categoryId . '%';
        return (int) Db::scalar(
            'SELECT COUNT(*) FROM routing_rules WHERE conditions LIKE :l1 OR actions LIKE :l2',
            [':l1' => $like, ':l2' => $like]
        );
    }
}
