<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Session;
use App\Core\ValidationException;
use App\Models\CannedResponse;
use App\Security\Audit;

/**
 * CannedResponseActions (§3) — agent management of canned responses. The {customerName}
 * / {agentName} / {ticketId} substitution happens at use time in TicketService.
 */
final class CannedResponseActions
{
    public function manageCannedResponses(array $payload, Request $request): array
    {
        return ['responses' => CannedResponse::all()];
    }

    public function createCannedResponse(array $payload, Request $request): array
    {
        [$title, $body, $category] = $this->validated($payload);
        $id = CannedResponse::create([
            'title' => $title, 'body' => $body, 'category' => $category,
            'created_by' => (string) Session::email(),
        ]);
        Audit::log((string) Session::email(), 'canned_create', $id, $title);
        return ['response_id' => $id];
    }

    public function updateCannedResponse(array $payload, Request $request): array
    {
        $id = (string) ($payload['response_id'] ?? '');
        if (CannedResponse::find($id) === null) {
            throw new ValidationException('Canned response not found.');
        }
        [$title, $body, $category] = $this->validated($payload);
        $fields = ['title' => $title, 'body' => $body, 'category' => $category];
        if (isset($payload['active'])) {
            $fields['active'] = !empty($payload['active']) ? 1 : 0;
        }
        CannedResponse::update($id, $fields);
        Audit::log((string) Session::email(), 'canned_update', $id);
        return ['ok' => true];
    }

    public function deleteCannedResponse(array $payload, Request $request): array
    {
        $id = (string) ($payload['response_id'] ?? '');
        if (CannedResponse::find($id) === null) {
            throw new ValidationException('Canned response not found.');
        }
        CannedResponse::delete($id);
        Audit::log((string) Session::email(), 'canned_delete', $id);
        return ['ok' => true];
    }

    /** @return array{0:string,1:string,2:string} */
    private function validated(array $payload): array
    {
        $title = trim((string) ($payload['title'] ?? ''));
        $body = trim((string) ($payload['body'] ?? ''));
        $category = mb_substr(trim((string) ($payload['category'] ?? '')), 0, 60);
        if ($title === '' || mb_strlen($title) > 120) {
            throw new ValidationException('Title is required (max 120 chars).');
        }
        if ($body === '') {
            throw new ValidationException('Body is required.');
        }
        return [$title, $body, $category];
    }
}
