<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Db;

/**
 * Product (Model) — a shared, admin-managed list of products/projects. A client picks
 * a product/project on the ticket form; it is stored on the ticket for filtering and
 * reporting. Unlike Organization, a product carries NO tenancy/routing meaning — it is
 * purely a labelling field. Deleting a product detaches it from tickets (ON DELETE SET
 * NULL), but the admin CRUD blocks deletion while any ticket still uses it (see
 * ProductActions), mirroring the category delete guard.
 */
final class Product
{
    public static function all(): array
    {
        return Db::queryAll('SELECT product_id, name, active, created_at FROM products ORDER BY name');
    }

    /** Active products for the ticket-form dropdown. */
    public static function allActive(): array
    {
        return Db::queryAll('SELECT product_id, name FROM products WHERE active = 1 ORDER BY name');
    }

    public static function find(string $productId): ?array
    {
        return Db::queryOne('SELECT * FROM products WHERE product_id = :p', [':p' => $productId]);
    }

    /** True if the id names an ACTIVE product (used to validate a submitted product). */
    public static function existsActive(string $productId): bool
    {
        return (int) Db::scalar(
            'SELECT COUNT(*) FROM products WHERE product_id = :p AND active = 1',
            [':p' => $productId]
        ) > 0;
    }

    public static function nameExists(string $name, string $exceptId = ''): bool
    {
        return (int) Db::scalar(
            'SELECT COUNT(*) FROM products WHERE name = :n AND product_id <> :x',
            [':n' => $name, ':x' => $exceptId]
        ) > 0;
    }

    /** Tickets still referencing this product (delete guard). */
    public static function ticketCount(string $productId): int
    {
        return (int) Db::scalar('SELECT COUNT(*) FROM tickets WHERE product_id = :p', [':p' => $productId]);
    }

    public static function create(array $data): string
    {
        $id = self::nextProductId();
        Db::insert('products', [
            'product_id' => $id,
            'name'       => $data['name'],
            'active'     => !empty($data['active']) || !isset($data['active']) ? 1 : 0,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return $id;
    }

    /** @param array<string,mixed> $fields */
    public static function update(string $productId, array $fields): void
    {
        Db::update('products', $fields, 'product_id = :p', [':p' => $productId]);
    }

    public static function delete(string $productId): void
    {
        Db::delete('products', 'product_id = :p', [':p' => $productId]);
    }

    public static function nextProductId(): string
    {
        $max = Db::scalar("SELECT MAX(CAST(SUBSTRING_INDEX(product_id, :dash, -1) AS UNSIGNED)) FROM products", [':dash' => '-']);
        return sprintf('PRD-%04d', ((int) $max) + 1);
    }
}
