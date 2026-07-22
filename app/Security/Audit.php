<?php
declare(strict_types=1);

namespace App\Security;

use App\Core\Db;

/**
 * Audit (§10.11) — tamper-evident, hash-chained audit log.
 *
 *   row_hash = sha256(prev_hash || actor || action || target || details || created_at)
 *
 * Each row commits to the previous row's hash, so row-level tampering or deletion
 * breaks the chain and becomes detectable (bin/verify_audit.php, Phase 12). This
 * does not PREVENT tampering by someone with DB access — it makes it evident,
 * which is the achievable goal. The audit log survives ticket-data reset by design.
 */
final class Audit
{
    private const SEP = "\x1f"; // unit separator — unambiguous field delimiter

    public static function log(
        string $actor,
        string $action,
        string $target = '',
        string $details = '',
        string $ip = ''
    ): void {
        $createdAt = gmdate('Y-m-d H:i:s');
        $prevHash = self::lastHash();
        $rowHash = self::computeHash($prevHash, $actor, $action, $target, $details, $createdAt);

        Db::insert('audit_log', [
            'actor'      => $actor,
            'action'     => $action,
            'target'     => $target,
            'details'    => mb_substr($details, 0, 500),
            'ip_address' => $ip,
            'created_at' => $createdAt,
            'prev_hash'  => $prevHash,
            'row_hash'   => $rowHash,
        ]);
    }

    public static function computeHash(
        string $prevHash,
        string $actor,
        string $action,
        string $target,
        string $details,
        string $createdAt
    ): string {
        return hash('sha256', implode(self::SEP, [
            $prevHash, $actor, $action, $target, mb_substr($details, 0, 500), $createdAt,
        ]));
    }

    private static function lastHash(): string
    {
        $row = Db::queryOne('SELECT row_hash FROM audit_log ORDER BY id DESC LIMIT 1');
        return $row === null ? '' : (string) $row['row_hash'];
    }

    /**
     * Paged read for the admin viewer (§3). WHERE is fully static with bound
     * sentinels (empty = no filter, §10.1); actor matches as a substring, action
     * matches exactly (it comes from the distinct-actions dropdown).
     *
     * @return array{rows:array<int,array<string,mixed>>, total:int}
     */
    public static function paged(string $actor, string $action, int $page, int $perPage = 25): array
    {
        $params = [
            ':actor_a' => $actor, ':actor_b' => '%' . $actor . '%',
            ':action_a' => $action, ':action_b' => $action,
        ];
        $total = (int) Db::scalar(
            "SELECT COUNT(*) FROM audit_log
             WHERE (:actor_a = '' OR actor LIKE :actor_b)
               AND (:action_a = '' OR action = :action_b)",
            $params
        );
        $params[':limit'] = $perPage;
        $params[':offset'] = (max(1, $page) - 1) * $perPage;
        $rows = Db::queryAll(
            "SELECT id, actor, action, target, details, ip_address, created_at
             FROM audit_log
             WHERE (:actor_a = '' OR actor LIKE :actor_b)
               AND (:action_a = '' OR action = :action_b)
             ORDER BY id DESC
             LIMIT :limit OFFSET :offset",
            $params
        );
        return ['rows' => $rows, 'total' => $total];
    }

    /** Distinct action names for the viewer's filter dropdown. */
    public static function actionNames(): array
    {
        $rows = Db::queryAll('SELECT DISTINCT action FROM audit_log ORDER BY action');
        return array_map(static fn(array $r): string => (string) $r['action'], $rows);
    }

    /**
     * Walk the chain in order; return the id of the first row whose stored hash does
     * not match a recomputation (or whose prev_hash breaks linkage), or null if intact.
     */
    public static function verifyChain(): ?int
    {
        $rows = Db::queryAll(
            'SELECT id, actor, action, target, details, created_at, prev_hash, row_hash
             FROM audit_log ORDER BY id ASC'
        );
        $expectedPrev = '';
        foreach ($rows as $r) {
            if ((string) $r['prev_hash'] !== $expectedPrev) {
                return (int) $r['id'];
            }
            $recomputed = self::computeHash(
                (string) $r['prev_hash'],
                (string) $r['actor'],
                (string) $r['action'],
                (string) $r['target'],
                (string) $r['details'],
                (string) $r['created_at']
            );
            if (!hash_equals($recomputed, (string) $r['row_hash'])) {
                return (int) $r['id'];
            }
            $expectedPrev = (string) $r['row_hash'];
        }
        return null;
    }
}
