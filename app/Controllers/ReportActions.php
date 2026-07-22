<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Session;
use App\Models\User;
use App\Services\ReportService;

/**
 * ReportActions — staff (agent+) reporting. Figures come from ReportService, scoped
 * to the caller's organization: a system admin sees all organizations; an org admin
 * or agent sees only their own (tenant isolation).
 */
final class ReportActions
{
    /** @return array{0:bool,1:?string} [allOrgs, orgId] */
    private function scope(): array
    {
        if ((string) Session::role() === 'admin') {
            return [true, null];
        }
        $me = User::findById((int) Session::userId());
        $org = (string) ($me['organization_id'] ?? '');
        return [false, $org === '' ? null : $org];
    }

    public function getReports(array $payload, Request $request): array
    {
        [$allOrgs, $orgId] = $this->scope();
        return ReportService::summary((int) ($payload['period'] ?? 30), $allOrgs, $orgId);
    }

    public function getAgentPerformance(array $payload, Request $request): array
    {
        [$allOrgs, $orgId] = $this->scope();
        $days = ReportService::normalizePeriod((int) ($payload['period'] ?? 30));
        return ['period' => $days, 'agents' => ReportService::agentPerformance($days, $allOrgs, $orgId)];
    }

    /** Grouped breakdown (custom report): tickets per product/category/agent/status/priority. */
    public function getGroupedReport(array $payload, Request $request): array
    {
        [$allOrgs, $orgId] = $this->scope();
        $dimension = (string) ($payload['dimension'] ?? 'product');
        if (!in_array($dimension, ReportService::GROUP_DIMENSIONS, true)) {
            $dimension = 'product';
        }
        $days = ReportService::normalizePeriod((int) ($payload['period'] ?? 30));
        return [
            'dimension' => $dimension,
            'period'    => $days,
            'groups'    => ReportService::grouped($dimension, $days, $allOrgs, $orgId),
        ];
    }
}
