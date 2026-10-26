<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Accounts\AccountLogin;
use App\Accounts\AccountSessions;
use App\Http\Controllers\Controller;
use App\Http\Resources\PhoneResource;
use App\Models\UserPhone;
use App\Sending\DailyLimit;
use App\Telegram\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Telegram accounts: the list, the connect wizard (phone -> code -> password),
 * logout, session check, removal. Every action answers with the account's
 * fresh state; the page polls data() while something is in flight.
 */
final class PhoneController extends Controller
{
    public function __construct(
        private readonly AccountLogin $login,
        private readonly AccountSessions $sessions,
    ) {}

    public function index(): View
    {
        return view('phones.index');
    }

    public function data(Request $request, DailyLimit $limit): JsonResponse
    {
        $user = $request->user();
        $phones = UserPhone::query()
            ->with('user:id,name')
            ->ownedBy($user)
            ->when(! $request->boolean('all') || ! $user->isSuperadmin(), fn ($q) => $q->where('user_id', $user->id))
            ->orderByRaw('user_id = ? desc', [$user->id])
            ->orderBy('phone')
            ->get();

        return response()->json([
            'phones' => PhoneResource::collection($phones),
            'sent_today' => $phones->mapWithKeys(fn (UserPhone $p) => [$p->id => $limit->sentToday($p->id)]),
            'daily_limit' => $limit->limit(),
        ]);
    }

    public function show(UserPhone $phone): PhoneResource
    {
        Gate::authorize('view', $phone);

        return new PhoneResource($phone->load('user:id,name'));
    }

    public function store(Request $request): PhoneResource
    {
        $request->validate(['phone' => ['required', 'string', 'regex:'.Phone::PATTERN]]);

        return new PhoneResource($this->login->start($request->user(), (string) $request->input('phone')));
    }

    public function code(Request $request, UserPhone $phone): PhoneResource
    {
        Gate::authorize('use', $phone);
        $request->validate(['code' => ['required', 'string', 'regex:/^[\d\s\-]{3,12}$/']]);

        return new PhoneResource($this->login->submitCode($phone, (string) $request->input('code')));
    }

    public function password(Request $request, UserPhone $phone): PhoneResource
    {
        Gate::authorize('use', $phone);
        $request->validate(['password' => ['required', 'string', 'max:256']]);

        return new PhoneResource($this->login->submitPassword($phone, (string) $request->input('password')));
    }

    public function relogin(Request $request, UserPhone $phone): PhoneResource
    {
        Gate::authorize('use', $phone);

        return new PhoneResource($this->login->start($phone->user, $phone->phone));
    }

    public function cancel(UserPhone $phone): JsonResponse
    {
        Gate::authorize('use', $phone);
        $this->login->cancel($phone);

        return response()->json(['ok' => true]);
    }

    public function check(UserPhone $phone): JsonResponse
    {
        Gate::authorize('use', $phone);
        $this->sessions->check($phone);

        return response()->json(['ok' => true, 'message' => __('phones.check_queued')]);
    }

    public function logout(UserPhone $phone): JsonResponse
    {
        Gate::authorize('use', $phone);
        $this->sessions->logout($phone);

        return response()->json(['ok' => true, 'message' => __('phones.logout_queued')]);
    }

    public function destroy(UserPhone $phone): JsonResponse
    {
        Gate::authorize('use', $phone);
        $this->sessions->remove($phone);

        return response()->json(['ok' => true]);
    }
}
