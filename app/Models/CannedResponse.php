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

    /** All (incl. inactive) for management. */
    public static function all(): array
    {
        return Db::queryAll('SELECT response_id, title, body, category, active FROM canned_responses ORDER BY title');
    }

    public static function create(array $data): string
    {
        $id = self::nextResponseId();
        Db::insert('canned_responses', [
            'response_id' => $id,
            'title'       => $data['title'],
            'body'        => $data['body'],
            'category'    => $data['category'] ?? '',
            'active'      => 1,
            'created_by'  => $data['created_by'],
            'created_at'  => gmdate('Y-m-d H:i:s'),
        ]);
        return $id;
    }

    /** @param array<string,mixed> $fields */
    public static function update(string $responseId, array $fields): void
    {
        Db::update('canned_responses', $fields, 'response_id = :r', [':r' => $responseId]);
    }

    public static function delete(string $responseId): void
    {
        Db::delete('canned_responses', 'response_id = :r', [':r' => $responseId]);
    }

    public static function nextResponseId(): string
    {
        $max = Db::scalar("SELECT MAX(CAST(SUBSTRING_INDEX(response_id, :dash, -1) AS UNSIGNED)) FROM canned_responses", [':dash' => '-']);
        return sprintf('CAN-%03d', ((int) $max) + 1);
    }
}
