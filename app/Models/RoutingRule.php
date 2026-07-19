<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Db;

/**
 * RoutingRule (Model) — thin repository over routing_rules. conditions/actions are
 * stored as JSON, validated on write by Services\RoutingRules.
 */
final class RoutingRule
{
    public static function all(): array
    {
        return Db::queryAll('SELECT * FROM routing_rules ORDER BY sort_order ASC, id ASC');
    }

    /** Enabled rules only, in evaluation order (the engine reads this). */
    public static function allEnabled(): array
    {
        return Db::queryAll('SELECT * FROM routing_rules WHERE enabled = 1 ORDER BY sort_order ASC, id ASC');
    }

    public static function find(string $ruleId): ?array
    {
        return Db::queryOne('SELECT * FROM routing_rules WHERE rule_id = :r', [':r' => $ruleId]);
    }

    public static function create(array $data): string
    {
        $ruleId = $data['rule_id'];
        Db::insert('routing_rules', [
            'rule_id'    => $ruleId,
            'name'       => $data['name'],
            'enabled'    => !empty($data['enabled']) ? 1 : 0,
            'conditions' => $data['conditions'],
            'actions'    => $data['actions'],
            'sort_order' => (int) $data['sort_order'],
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return $ruleId;
    }

    /** @param array<string,mixed> $fields */
    public static function update(string $ruleId, array $fields): void
    {
        $fields['updated_at'] = gmdate('Y-m-d H:i:s');
        Db::update('routing_rules', $fields, 'rule_id = :r', [':r' => $ruleId]);
    }

    public static function delete(string $ruleId): void
    {
        Db::delete('routing_rules', 'rule_id = :r', [':r' => $ruleId]);
    }

    public static function nextRuleId(): string
    {
        $max = Db::scalar("SELECT MAX(CAST(SUBSTRING_INDEX(rule_id, :dash, -1) AS UNSIGNED)) FROM routing_rules", [':dash' => '-']);
        return sprintf('RULE-%03d', ((int) $max) + 1);
    }

    public static function maxSortOrder(): int
    {
        return (int) Db::scalar('SELECT COALESCE(MAX(sort_order), 0) FROM routing_rules');
    }
}
