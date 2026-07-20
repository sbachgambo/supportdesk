<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Session;
use App\Core\ValidationException;
use App\Models\Organization;
use App\Models\User;
use App\Security\Audit;

/**
 * OrganizationActions (§3) — admin CRUD over organizations (tenants). Deleting an
 * organization clears it from its agents (they become org-less) and, via the ticket
 * FK (ON DELETE SET NULL), from its tickets — nothing is orphaned.
 */
final class OrganizationActions
{
    public function listOrganizations(array $payload, Request $request): array
    {
        return ['organizations' => Organization::all()];
    }

    public function createOrganization(array $payload, Request $request): array
    {
        $name = $this->validName($payload);
        if (Organization::nameExists($name)) {
            throw new ValidationException('An organization with that name already exists.');
        }
        $id = Organization::create(['name' => $name, 'active' => 1]);
        Audit::log((string) Session::email(), 'organization_create', $id, $name);
        return ['organization_id' => $id];
    }

    public function updateOrganization(array $payload, Request $request): array
    {
        $id = (string) ($payload['organization_id'] ?? '');
        if (Organization::find($id) === null) {
            throw new ValidationException('Organization not found.');
        }
        $fields = [];
        if (isset($payload['name'])) {
            $name = $this->validName($payload);
            if (Organization::nameExists($name, $id)) {
                throw new ValidationException('Another organization already has that name.');
            }
            $fields['name'] = $name;
        }
        if (isset($payload['active'])) {
            $fields['active'] = !empty($payload['active']) ? 1 : 0;
        }
        if ($fields !== []) {
            Organization::update($id, $fields);
        }
        Audit::log((string) Session::email(), 'organization_update', $id);
        return ['ok' => true];
    }

    public function deleteOrganization(array $payload, Request $request): array
    {
        $id = (string) ($payload['organization_id'] ?? '');
        if (Organization::find($id) === null) {
            throw new ValidationException('Organization not found.');
        }
        User::nullifyOrganization($id);   // detach agents; tickets detach via ON DELETE SET NULL
        Organization::delete($id);
        Audit::log((string) Session::email(), 'organization_delete', $id);
        return ['ok' => true];
    }

    private function validName(array $payload): string
    {
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            throw new ValidationException('Organization name is required (max 120 characters).');
        }
        return $name;
    }
}
