<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Accounts\AccountLogin;
use App\Accounts\AccountSessions;
use App\Http\Controllers\Api\V1\Concerns\ResolvesPhone;
use App\Http\Controllers\Controller;
use App\Models\UserPhone;
use App\Support\ErrorText;
use App\Telegram\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Accounts over the API: list, login (auth -> code -> password), status, logout.
 * Response shapes are the old ones; new fields are only added.
 */
final class PhoneController extends Controller
{
    use ResolvesPhone;

    public function __construct(
        private readonly AccountLogin $login,
        private readonly AccountSessions $sessions,
    ) {}

    /** GET /api/phones */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $mine = UserPhone::query()->with('user.role')->where('user_id', $user->id)->connected()->latest()->get()
            ->map(fn (UserPhone $p) => $this->format($p, true, $user->isSuperadmin()));
        $others = $user->isSuperadmin()
            ? UserPhone::query()->with('user.role')->where('user_id', '!=', $user->id)->connected()->latest()->get()
                ->map(fn (UserPhone $p) => $this->format($p, false, true))
            : collect();

        return response()->json([
            'success' => true,
            'data' => [
                'my_phones' => ['count' => $mine->count(), 'items' => $mine],
                'others_phones' => ['count' => $others->count(), 'visible' => $user->isSuperadmin(), 'items' => $others],
                'stats' => [
                    'total_my' => $mine->count(),
                    'total_others' => $others->count(),
                    'total_all' => $mine->count() + $others->count(),
                ],
            ],
        ]);
    }

    /** POST /api/tg/auth {phone} */
    public function auth(Request $request): JsonResponse
    {
        $request->validate(['phone' => ['required', 'string', 'regex:'.Phone::PATTERN]]);
        $account = $this->login->start($request->user(), (string) $request->input('phone'));

        return response()->json([
            'status' => 'sms_sent', // kept for old clients; the code is requested in the background
            'message' => __('phones.code_requested'),
            'session_id' => $account->id,
            'phone_id' => $account->id,
            'account_status' => $account->status->value,
        ]);
    }

    /** POST /api/tg/code {phone, code} */
    public function code(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'min:9', 'max:20'],
            'code' => ['required', 'string', 'min:3', 'max:12'],
        ]);
        $account = $this->ownPhone($request->user(), $data['phone']) ?? abort(404, __('phones.not_found'));
        $account = $this->login->submitCode($account, $data['code']);

        return response()->json(['status' => 'processing', 'session_id' => $account->id, 'message' => __('phones.code_checking')]);
    }

    /** POST /api/tg/password {phone, password} (2FA, missing in the old API) */
    public function password(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'min:9', 'max:20'],
            'password' => ['required', 'string', 'max:256'],
        ]);
        $account = $this->ownPhone($request->user(), $data['phone']) ?? abort(404, __('phones.not_found'));
        $account = $this->login->submitPassword($account, $data['password']);

        return response()->json(['status' => 'processing', 'session_id' => $account->id, 'message' => __('phones.password_checking')]);
    }

    /** GET /api/tg/status?phone= : poll the login */
    public function status(Request $request): JsonResponse
    {
        $phone = (string) $request->validate(['phone' => ['required', 'string', 'max:20']])['phone'];
        $account = $this->ownPhone($request->user(), $phone) ?? abort(404, __('phones.not_found'));

        return response()->json([
            'status' => $account->status->value,
            'label' => $account->status->label(),
            'phone_id' => $account->id,
            'awaits' => match (true) {
                $account->status->acceptsCode() => 'code',
                $account->status->acceptsPassword() => 'password',
                default => null,
            },
            'password_hint' => $account->password_hint,
            'error' => $account->last_error,
            'error_text' => ErrorText::for($account->last_error),
        ]);
    }

    /** POST /api/tg/logout/{phone} (phone = user_phones.id) */
    public function logout(Request $request, UserPhone $phone): JsonResponse
    {
        Gate::authorize('use', $phone);
        if (! $phone->canSend()) {
            return response()->json(['status' => 'error', 'message' => __('phones.not_logged_in')], 404);
        }
        $this->sessions->logout($phone);

        return response()->json(['status' => 'processing', 'message' => __('phones.logout_queued')]);
    }

    /** @return array<string, mixed> */
    private function format(UserPhone $phone, bool $isOwner, bool $isSuperadmin): array
    {
        $owner = $phone->user;

        return [
            'id' => $phone->id,
            'phone' => $phone->phone,
            'telegram_user_id' => $phone->telegram_user_id,
            'is_owner' => $isOwner,
            'can_control' => $isOwner || $isSuperadmin,
            'session_exists' => $isOwner ? $phone->canSend() : null,
            'status' => $phone->status->value,
            'name' => $phone->display_name,
            'created_at' => $phone->created_at?->toDateTimeString(),
            'created_at_formatted' => $phone->created_at?->format('d.m.Y H:i'),
            'updated_at_formatted' => $phone->updated_at?->format('d.m.Y H:i'),
            'owner' => $owner ? [
                'id' => $owner->id,
                'name' => $owner->name,
                'email' => $owner->email,
                'role' => $owner->role?->name ?? 'user',
            ] : null,
        ];
    }
}
