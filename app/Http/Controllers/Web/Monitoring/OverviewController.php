<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Monitoring;

use App\Http\Controllers\Controller;
use App\Monitoring\SystemOverview;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

/** Live state of the whole system: processes, current sends, accounts in trouble, open problems. */
final class OverviewController extends Controller
{
    public function index(): View
    {
        return view('monitoring.overview');
    }

    public function data(SystemOverview $overview): JsonResponse
    {
        return response()->json($overview->snapshot());
    }
}
