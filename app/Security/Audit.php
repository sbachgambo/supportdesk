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
