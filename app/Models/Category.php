<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Db;

/**
 * Category (Model) — thin repository over the two-level categories table.
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

    public static function existsActive(string $categoryId): bool
    {
        $row = Db::queryOne(
            'SELECT 1 FROM categories WHERE category_id = :c AND active = 1',
            [':c' => $categoryId]
        );
        return $row !== null;
    }
}
