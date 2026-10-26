<?php

namespace App\Http\Controllers;

use App\Models\Bookkeeping\Ledger;
use App\Services\Bookkeeping\MonthlyBookkeepingReportService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class MonthlyBookkeepingReportController extends Controller
{
    public function index(): View
    {
        $latestReports = [];

        foreach (Storage::disk('local')->allFiles('reports/monthly') as $path) {
            if (preg_match('#^reports/monthly/([^/]+)/latest\.html$#', $path, $matches) === 1) {
                $latestReports[$matches[1]] = true;
            }
        }

        $ledgers = Ledger::query()
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'public_id', 'name', 'currency'])
            ->map(fn (Ledger $ledger): array => [
                'name' => $ledger->name,
                'currency' => $ledger->currency,
                'has_latest_report' => isset($latestReports[$ledger->public_id]),
                'report_url' => route('reports.monthly.show', $ledger),
            ]);

        return view('reports.index', compact('ledgers'));
    }

    public function __invoke(Request $request, Ledger $ledger, MonthlyBookkeepingReportService $reports): Response
    {
        $month = $request->query('month');
        abort_unless($month === null || (is_string($month) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1), 404);

        $path = $reports->path($ledger, $month);
        abort_unless(Storage::disk('local')->exists($path), 404);

        return response(Storage::disk('local')->get($path), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
