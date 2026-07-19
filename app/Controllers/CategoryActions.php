<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Session;
use App\Core\ValidationException;
use App\Models\Category;
use App\Security\Audit;

/**
 * CategoryActions — admin CRUD over two-level categories with referential integrity.
 * A delete is BLOCKED while any child category, ticket, or routing rule references it.
 */
final class CategoryActions
{
    public function listCategories(array $payload, Request $request): array
    {
        return ['categories' => Category::all()];
    }

    public function createCategory(array $payload, Request $request): array
    {
        $name = trim((string) ($payload['name'] ?? ''));
        $color = (string) ($payload['color'] ?? '#4057F5');
        $parentId = trim((string) ($payload['parent_id'] ?? ''));

        if ($name === '' || mb_strlen($name) > 60) {
            throw new ValidationException('Category name is required and must be at most 60 characters.');
        }
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            throw new ValidationException('Colour must be a hex value like #4057F5.');
        }
        if ($parentId !== '') {
            // Two levels only: the parent must exist and itself be top-level.
            if (Category::find($parentId) === null) {
                throw new ValidationException('Parent category does not exist.');
            }
            if (!Category::isTopLevel($parentId)) {
                throw new ValidationException('Categories may be nested only two levels deep.');
            }
        }

        $id = Category::create([
            'name'        => $name,
            'description' => mb_substr((string) ($payload['description'] ?? ''), 0, 255),
            'color'       => $color,
            'parent_id'   => $parentId === '' ? null : $parentId,
        ]);
        Audit::log((string) Session::email(), 'category_create', $id, $name);
        return ['category_id' => $id];
    }

    public function updateCategory(array $payload, Request $request): array
    {
        $id = (string) ($payload['category_id'] ?? '');
        if (Category::find($id) === null) {
            throw new ValidationException('Category not found.');
        }
        $fields = [];
        if (isset($payload['name'])) {
            $name = trim((string) $payload['name']);
            if ($name === '' || mb_strlen($name) > 60) {
                throw new ValidationException('Category name must be 1–60 characters.');
            }
            $fields['name'] = $name;
        }
        if (isset($payload['color'])) {
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', (string) $payload['color'])) {
                throw new ValidationException('Colour must be a hex value like #4057F5.');
            }
            $fields['color'] = (string) $payload['color'];
        }
        if (isset($payload['description'])) {
            $fields['description'] = mb_substr((string) $payload['description'], 0, 255);
        }
        if (isset($payload['active'])) {
            $fields['active'] = !empty($payload['active']) ? 1 : 0;
        }
        if ($fields !== []) {
            Category::update($id, $fields);
        }
        Audit::log((string) Session::email(), 'category_update', $id);
        return ['ok' => true];
    }

    public function deleteCategory(array $payload, Request $request): array
    {
        $id = (string) ($payload['category_id'] ?? '');
        if (Category::find($id) === null) {
            throw new ValidationException('Category not found.');
        }
        // Referential integrity: block while anything references this category (§3).
        if (Category::childCount($id) > 0) {
            throw new ValidationException('Cannot delete: this category has sub-categories.');
        }
        if (Category::ticketCount($id) > 0) {
            throw new ValidationException('Cannot delete: tickets are assigned to this category.');
        }
        if (Category::ruleReferenceCount($id) > 0) {
            throw new ValidationException('Cannot delete: a routing rule references this category.');
        }
        Category::delete($id);
        Audit::log((string) Session::email(), 'category_delete', $id);
        return ['ok' => true];
    }
}
