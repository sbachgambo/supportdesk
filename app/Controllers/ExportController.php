<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
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
        $csv = ReportService::ticketsCsv($period);
        Audit::log((string) Session::email(), 'report_export', '', "period={$period}");

        return Response::make($csv, 200, 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="tickets-' . $period . 'd.csv"')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
