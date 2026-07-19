<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Db;

/**
 * CannedResponse (Model) — thin repository over canned_responses.
 * Placeholder substitution ({customerName}/{agentName}/{ticketId}) lives in
 * Services\TicketService::renderCanned(), not here.
 */
final class CannedResponse
{
    public static function allActive(): array
    {
        return Db::queryAll(
            'SELECT response_id, title, body, category FROM canned_responses
             WHERE active = 1 ORDER BY title ASC'
        );
    }

    public static function find(string $responseId): ?array
    {
        return Db::queryOne(
            'SELECT response_id, title, body, category FROM canned_responses WHERE response_id = :r',
            [':r' => $responseId]
        );
    }
}
