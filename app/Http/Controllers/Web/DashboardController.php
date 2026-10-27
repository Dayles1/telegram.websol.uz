<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Resources\GroupResource;
use App\Http\Resources\PhoneResource;
use App\Models\MessageGroup;
use App\Models\SystemEvent;
use App\Models\UserPhone;
use App\Queries\DeliveryStats;
use App\Queries\GroupList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The home page: my accounts, what is being sent right now, today's numbers. */
final class DashboardController extends Controller
{
    public function index(): View
    {
        return view('dashboard');
    }

    /** The top bar's live counters, polled on every page. */
    public function pulse(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'active' => MessageGroup::query()->visibleTo($user)->active()->count(),
            'problems' => $user->seesOthers() ? SystemEvent::query()->open()->whereIn('level', ['error', 'critical'])->count() : 0,
        ]);
    }

    public function data(Request $request, GroupList $groups): JsonResponse
    {
        $user = $request->user();
        $scope = $user->seesOthers() ? null : $user->id;

        return response()->json([
            'today' => DeliveryStats::between(now()->startOfDay(), now())->forUser($scope)->totals(),
            'week' => DeliveryStats::between(now()->subDays(6)->startOfDay(), now())->forUser($scope)->series(),
            'active' => GroupResource::collection($groups->query($user, ['status' => 'active'])->limit(10)->get()),
            'recent' => GroupResource::collection($groups->query($user)->limit(6)->get()),
            'phones' => PhoneResource::collection(
                UserPhone::query()->where('user_id', $user->id)->connected()->orderBy('phone')->get()
            ),
        ]);
    }
}
