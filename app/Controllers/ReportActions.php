<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Services\ReportService;

/**
 * ReportActions — staff (agent+) reporting. All figures come from ReportService,
 * computed over tickets created in the selected 7/30/90-day period.
 */
final class ReportActions
{
    public function getReports(array $payload, Request $request): array
    {
        return ReportService::summary((int) ($payload['period'] ?? 30));
    }

    public function getAgentPerformance(array $payload, Request $request): array
    {
        $days = ReportService::normalizePeriod((int) ($payload['period'] ?? 30));
        return ['period' => $days, 'agents' => ReportService::agentPerformance($days)];
    }
}
