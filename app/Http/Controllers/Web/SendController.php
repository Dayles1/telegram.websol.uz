<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\SendRequest;
use App\Http\Resources\CatalogResource;
use App\Http\Resources\PhoneResource;
use App\Models\Catalog;
use App\Models\UserPhone;
use App\Sending\CatalogCooldown;
use App\Sending\DailyLimit;
use App\Sending\MassSend;
use App\Sending\Peers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/** The send form: pick an account, pick catalogs, write the text, attach a file, maybe repeat. */
final class SendController extends Controller
{
    public function create(Request $request): View
    {
        $user = $request->user();

        $phones = UserPhone::query()->with('user:id,name')->ownedBy($user)->connected()
            ->orderByRaw('user_id = ? desc', [$user->id])->orderBy('phone')->get();
        $catalogs = Catalog::query()->with('user:id,name')->usableBy($user)
            ->orderByRaw('user_id = ? desc', [$user->id])->orderBy('title')->get();

        return view('send.create', [
            'phones' => PhoneResource::collection($phones)->resolve($request),
            // the form needs counts, not the peers themselves (catalogs can hold thousands)
            'catalogs' => array_map(fn (array $c) => Arr::except($c, 'peers'), CatalogResource::collection($catalogs)->resolve($request)),
            'selectedPhone' => (int) $request->query('phone'),
        ]);
    }

    /** Live summary under the form: unique peers, cooldowns, today's usage. */
    public function preview(Request $request, CatalogCooldown $cooldown, DailyLimit $limit): JsonResponse
    {
        $data = $request->validate([
            'phone_id' => ['nullable', 'integer'],
            'catalog_ids' => ['array'],
            'catalog_ids.*' => ['integer'],
        ]);
        $user = $request->user();

        $catalogs = Catalog::query()->usableBy($user)->whereIn('id', $data['catalog_ids'] ?? [])->get();
        $phone = isset($data['phone_id']) ? UserPhone::query()->find($data['phone_id']) : null;
        if ($phone !== null && Gate::denies('use', $phone)) {
            $phone = null;
        }

        return response()->json([
            'unique_peers' => count(Peers::unique($catalogs->flatMap(fn (Catalog $c) => $c->peerList()))),
            'total_peers' => $catalogs->sum(fn (Catalog $c) => count($c->peerList())),
            'cooldowns' => $phone ? $cooldown->remaining($catalogs->modelKeys(), $phone->id) : [],
            'sent_today' => $phone ? $limit->sentToday($phone->id) : null,
            'daily_limit' => $limit->limit(),
        ]);
    }

    public function store(SendRequest $request, MassSend $send): JsonResponse
    {
        $phone = UserPhone::query()->findOrFail($request->integer('phone_id'));
        Gate::authorize('use', $phone);

        $group = $send->create($request->user(), $phone, $request->order());

        return response()->json([
            'ok' => true,
            'message' => __('send.queued'),
            'redirect' => route('sends.show', $group),
        ], 201);
    }
}
