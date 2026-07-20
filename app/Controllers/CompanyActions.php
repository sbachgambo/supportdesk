<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Session;
use App\Core\ValidationException;
use App\Models\Company;
use App\Security\Audit;

/**
 * CompanyActions (§3) — admin CRUD over the client Company/Institution suggestion list.
 * Companies are stored on tickets as free text, so deleting one never orphans a ticket;
 * it only removes the entry from the type-or-pick datalist.
 */
final class CompanyActions
{
    public function listCompanies(array $payload, Request $request): array
    {
        return ['companies' => Company::all()];
    }

    public function createCompany(array $payload, Request $request): array
    {
        $name = $this->validName($payload);
        if (Company::nameExists($name)) {
            throw new ValidationException('A company with that name already exists.');
        }
        $id = Company::create(['name' => $name, 'active' => 1]);
        Audit::log((string) Session::email(), 'company_create', $id, $name);
        return ['company_id' => $id];
    }

    public function updateCompany(array $payload, Request $request): array
    {
        $id = (string) ($payload['company_id'] ?? '');
        if (Company::find($id) === null) {
            throw new ValidationException('Company not found.');
        }
        $fields = [];
        if (isset($payload['name'])) {
            $name = $this->validName($payload);
            if (Company::nameExists($name, $id)) {
                throw new ValidationException('Another company already has that name.');
            }
            $fields['name'] = $name;
        }
        if (isset($payload['active'])) {
            $fields['active'] = !empty($payload['active']) ? 1 : 0;
        }
        if ($fields !== []) {
            Company::update($id, $fields);
        }
        Audit::log((string) Session::email(), 'company_update', $id);
        return ['ok' => true];
    }

    public function deleteCompany(array $payload, Request $request): array
    {
        $id = (string) ($payload['company_id'] ?? '');
        if (Company::find($id) === null) {
            throw new ValidationException('Company not found.');
        }
        Company::delete($id);
        Audit::log((string) Session::email(), 'company_delete', $id);
        return ['ok' => true];
    }

    private function validName(array $payload): string
    {
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            throw new ValidationException('Company name is required (max 120 characters).');
        }
        return $name;
    }
}
