<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\User;
use App\Security\Audit;
use App\Security\Rbac;
use App\Services\ReportService;

/**
 * ExportController — CSV download of tickets in a period (agent+). A GET download,
 * so it carries no CSRF (no state change) but IS access-checked. Served as an
 * attachment with a text/csv type.
 */
final class ExportController
{
    public function ticketsCsv(Request $request): Response
    {
        if (Session::current() === null) {
            return redirect('login');
        }
        if (!Rbac::isAtLeastAgent()) {
            return Response::make('Forbidden', 403, 'text/plain; charset=utf-8');
        }

        $period = ReportService::normalizePeriod((int) ($request->query('period') ?? 30));
        // Org scope: system admin exports all; org admin / agent only their own org.
        $allOrgs = Rbac::isAdmin();
        $orgId = null;
        if (!$allOrgs) {
            $me = User::findById((int) Session::userId());
            $org = (string) ($me['organization_id'] ?? '');
            $orgId = $org === '' ? null : $org;
        }
        // Optional filters (custom report export). Each is a bound sentinel in the query.
        $filters = [
            'status'      => (string) ($request->query('status') ?? ''),
            'priority'    => (string) ($request->query('priority') ?? ''),
            'product_id'  => (string) ($request->query('product_id') ?? ''),
            'category_id' => (string) ($request->query('category_id') ?? ''),
            'assigned_to' => (string) ($request->query('assigned_to') ?? ''),
            'q'           => (string) ($request->query('q') ?? ''),
        ];
        $csv = ReportService::ticketsCsv($period, $allOrgs, $orgId, $filters);
        Audit::log((string) Session::email(), 'report_export', '', "period={$period}");

        return Response::make($csv, 200, 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="tickets-' . $period . 'd.csv"')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
