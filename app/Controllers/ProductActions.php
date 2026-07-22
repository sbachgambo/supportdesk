<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Session;
use App\Core\ValidationException;
use App\Models\Product;
use App\Security\Audit;

/**
 * ProductActions (§3) — admin CRUD over the shared products/projects list. A delete is
 * BLOCKED while any ticket still references the product (referential integrity, like
 * categories); disable it instead to hide it from the forms while keeping history.
 */
final class ProductActions
{
    public function listProducts(array $payload, Request $request): array
    {
        return ['products' => Product::all()];
    }

    public function createProduct(array $payload, Request $request): array
    {
        $name = $this->validName($payload);
        if (Product::nameExists($name)) {
            throw new ValidationException('A product/project with that name already exists.');
        }
        $id = Product::create(['name' => $name, 'active' => 1]);
        Audit::log((string) Session::email(), 'product_create', $id, $name);
        return ['product_id' => $id];
    }

    public function updateProduct(array $payload, Request $request): array
    {
        $id = (string) ($payload['product_id'] ?? '');
        if (Product::find($id) === null) {
            throw new ValidationException('Product/project not found.');
        }
        $fields = [];
        if (isset($payload['name'])) {
            $name = $this->validName($payload);
            if (Product::nameExists($name, $id)) {
                throw new ValidationException('Another product/project already has that name.');
            }
            $fields['name'] = $name;
        }
        if (isset($payload['active'])) {
            $fields['active'] = !empty($payload['active']) ? 1 : 0;
        }
        if ($fields !== []) {
            Product::update($id, $fields);
        }
        Audit::log((string) Session::email(), 'product_update', $id);
        return ['ok' => true];
    }

    public function deleteProduct(array $payload, Request $request): array
    {
        $id = (string) ($payload['product_id'] ?? '');
        if (Product::find($id) === null) {
            throw new ValidationException('Product/project not found.');
        }
        if (Product::ticketCount($id) > 0) {
            throw new ValidationException('Cannot delete: tickets are assigned to this product/project. Disable it instead.');
        }
        Product::delete($id);
        Audit::log((string) Session::email(), 'product_delete', $id);
        return ['ok' => true];
    }

    private function validName(array $payload): string
    {
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            throw new ValidationException('Product/project name is required (max 120 characters).');
        }
        return $name;
    }
}
