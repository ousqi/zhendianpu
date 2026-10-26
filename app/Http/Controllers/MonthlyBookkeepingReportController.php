<?php

namespace App\Http\Controllers;

use App\Models\Bookkeeping\Ledger;
use App\Services\Bookkeeping\MonthlyBookkeepingReportService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class MonthlyBookkeepingReportController extends Controller
{
    public function __invoke(Request $request, Ledger $ledger, MonthlyBookkeepingReportService $reports): Response
    {
        $month = $request->query('month');
        abort_unless($month === null || (is_string($month) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1), 404);
        $path = $reports->path($ledger, $month);
        abort_unless(Storage::disk('local')->exists($path), 404);

        return response(Storage::disk('local')->get($path), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
