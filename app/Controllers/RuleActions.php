<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Session;
use App\Core\ValidationException;
use App\Models\RoutingRule;
use App\Security\Audit;
use App\Services\RoutingRules;

/**
 * RuleActions — admin CRUD over the routing-rules engine. conditions/actions are
 * validated on write (Services\RoutingRules::validate). A rule can be enabled/disabled;
 * a disabled rule never fires (the engine reads only enabled rules).
 */
final class RuleActions
{
    public function listRules(array $payload, Request $request): array
    {
        return ['rules' => RoutingRule::all()];
    }

    public function createRule(array $payload, Request $request): array
    {
        [$conditions, $actions] = $this->validated($payload);
        $ruleId = RoutingRule::create([
            'rule_id'    => RoutingRule::nextRuleId(),
            'name'       => $this->name($payload),
            'enabled'    => !empty($payload['enabled']),
            'conditions' => json_encode($conditions),
            'actions'    => json_encode($actions),
            'sort_order' => RoutingRule::maxSortOrder() + 1,
        ]);
        Audit::log((string) Session::email(), 'rule_create', $ruleId, $this->name($payload));
        return ['rule_id' => $ruleId];
    }

    public function updateRule(array $payload, Request $request): array
    {
        $ruleId = (string) ($payload['rule_id'] ?? '');
        if (RoutingRule::find($ruleId) === null) {
            throw new ValidationException('Rule not found.');
        }
        [$conditions, $actions] = $this->validated($payload);
        RoutingRule::update($ruleId, [
            'name'       => $this->name($payload),
            'conditions' => json_encode($conditions),
            'actions'    => json_encode($actions),
            'enabled'    => !empty($payload['enabled']) ? 1 : 0,
        ]);
        Audit::log((string) Session::email(), 'rule_update', $ruleId);
        return ['ok' => true];
    }

    public function toggleRule(array $payload, Request $request): array
    {
        $ruleId = (string) ($payload['rule_id'] ?? '');
        $rule = RoutingRule::find($ruleId);
        if ($rule === null) {
            throw new ValidationException('Rule not found.');
        }
        $enabled = !empty($payload['enabled']) ? 1 : 0;
        RoutingRule::update($ruleId, ['enabled' => $enabled]);
        Audit::log((string) Session::email(), 'rule_toggle', $ruleId, "enabled={$enabled}");
        return ['ok' => true, 'enabled' => (bool) $enabled];
    }

    public function deleteRule(array $payload, Request $request): array
    {
        $ruleId = (string) ($payload['rule_id'] ?? '');
        if (RoutingRule::find($ruleId) === null) {
            throw new ValidationException('Rule not found.');
        }
        RoutingRule::delete($ruleId);
        Audit::log((string) Session::email(), 'rule_delete', $ruleId);
        return ['ok' => true];
    }

    /** @return array{0:array<int,mixed>,1:array<int,mixed>} */
    private function validated(array $payload): array
    {
        $conditions = is_array($payload['conditions'] ?? null) ? $payload['conditions'] : [];
        $actions = is_array($payload['actions'] ?? null) ? $payload['actions'] : [];
        $error = RoutingRules::validate($conditions, $actions);
        if ($error !== null) {
            throw new ValidationException($error);
        }
        return [$conditions, $actions];
    }

    private function name(array $payload): string
    {
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) {
            throw new ValidationException('Rule name is required (max 100 chars).');
        }
        return $name;
    }
}
