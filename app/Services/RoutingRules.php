<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Category;
use App\Models\RoutingRule;
use App\Models\User;

/**
 * RoutingRules (§3, §18) — the routing-rules engine.
 *
 * Rules are evaluated in sort order; for each ENABLED rule whose conditions ALL
 * match, its actions are applied to a working set of overrides (later rules can
 * override earlier ones). DISABLED RULES NEVER FIRE — the engine reads only enabled
 * rules. (§18: "a toggle must actually gate behaviour"; the test proves a disabled
 * rule does not change the outcome.)
 */
final class RoutingRules
{
    public const FIELDS = ['subject', 'description', 'priority', 'category', 'customer_email', 'channel', 'tags'];
    public const OPERATORS = ['is', 'contains', 'starts_with', 'not_contains'];
    public const ACTION_TYPES = ['set_priority', 'set_category', 'assign_agent', 'add_tag'];

    /**
     * Compute field overrides for a ticket from the enabled rules.
     * @return array{priority?:string, category_id?:string, assigned_to?:string, tags?:string}
     */
    public static function apply(array $ticket): array
    {
        $overrides = [];
        foreach (RoutingRule::allEnabled() as $rule) {
            $conditions = json_decode((string) $rule['conditions'], true);
            $actions = json_decode((string) $rule['actions'], true);
            if (!is_array($conditions) || !is_array($actions)) {
                continue;
            }
            if (self::matches($conditions, $ticket, $overrides)) {
                self::applyActions($actions, $overrides, $ticket);
            }
        }
        return $overrides;
    }

    /** All conditions must hold. Evaluates against the ticket merged with prior overrides. */
    private static function matches(array $conditions, array $ticket, array $overrides): bool
    {
        foreach ($conditions as $cond) {
            $field = (string) ($cond['field'] ?? '');
            $operator = (string) ($cond['operator'] ?? '');
            $value = (string) ($cond['value'] ?? '');
            $actual = self::fieldValue($field, $ticket, $overrides);

            $ok = match ($operator) {
                'is'           => strcasecmp($actual, $value) === 0,
                'contains'     => $value !== '' && stripos($actual, $value) !== false,
                'starts_with'  => $value !== '' && stripos($actual, $value) === 0,
                'not_contains' => $value === '' || stripos($actual, $value) === false,
                default        => false,
            };
            if (!$ok) {
                return false;
            }
        }
        return $conditions !== [];
    }

    private static function fieldValue(string $field, array $ticket, array $overrides): string
    {
        return match ($field) {
            'subject'        => (string) ($ticket['subject'] ?? ''),
            'description'    => (string) ($ticket['description'] ?? ''),
            'priority'       => (string) ($overrides['priority'] ?? $ticket['priority'] ?? ''),
            'category'       => (string) ($overrides['category_id'] ?? $ticket['category_id'] ?? ''),
            'customer_email' => (string) ($ticket['customer_email'] ?? ''),
            'channel'        => (string) ($ticket['channel'] ?? ''),
            'tags'           => (string) ($overrides['tags'] ?? $ticket['tags'] ?? ''),
            default          => '',
        };
    }

    private static function applyActions(array $actions, array &$overrides, array $ticket): void
    {
        foreach ($actions as $action) {
            $type = (string) ($action['type'] ?? '');
            $value = (string) ($action['value'] ?? '');
            switch ($type) {
                case 'set_priority':
                    if (in_array($value, TicketService::PRIORITIES, true)) {
                        $overrides['priority'] = $value;
                    }
                    break;
                case 'set_category':
                    if (Category::existsActive($value)) {
                        $overrides['category_id'] = $value;
                    }
                    break;
                case 'assign_agent':
                    if (User::findActiveAgent($value) !== null) {
                        $overrides['assigned_to'] = $value;
                    }
                    break;
                case 'add_tag':
                    $current = (string) ($overrides['tags'] ?? $ticket['tags'] ?? '');
                    $tags = array_filter(array_map('trim', explode(',', $current)));
                    if ($value !== '' && !in_array($value, $tags, true)) {
                        $tags[] = $value;
                    }
                    $overrides['tags'] = implode(',', $tags);
                    break;
            }
        }
    }

    /**
     * Validate rule conditions + actions on write. Returns an error string or null.
     */
    public static function validate(array $conditions, array $actions): ?string
    {
        if ($conditions === []) {
            return 'A rule needs at least one condition.';
        }
        foreach ($conditions as $c) {
            if (!in_array((string) ($c['field'] ?? ''), self::FIELDS, true)) {
                return 'Invalid condition field.';
            }
            if (!in_array((string) ($c['operator'] ?? ''), self::OPERATORS, true)) {
                return 'Invalid condition operator.';
            }
        }
        if ($actions === []) {
            return 'A rule needs at least one action.';
        }
        foreach ($actions as $a) {
            $type = (string) ($a['type'] ?? '');
            $value = (string) ($a['value'] ?? '');
            if (!in_array($type, self::ACTION_TYPES, true)) {
                return 'Invalid action type.';
            }
            if ($type === 'set_priority' && !in_array($value, TicketService::PRIORITIES, true)) {
                return 'Invalid priority in action.';
            }
            if ($type === 'set_category' && !Category::existsActive($value)) {
                return 'Action references a category that does not exist.';
            }
            if ($type === 'assign_agent' && User::findActiveAgent($value) === null) {
                return 'Action assigns to an agent who is not active.';
            }
        }
        return null;
    }
}
