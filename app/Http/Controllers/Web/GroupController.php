<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\MessageStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\GroupResource;
use App\Http\Resources\MessageResource;
use App\Models\MessageGroup;
use App\Models\SendLoop;
use App\Models\UserPhone;
use App\Queries\GroupList;
use App\Sending\GroupProgress;
use App\Sending\MassSend;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** History of sends, and one send in detail with live progress. */
final class GroupController extends Controller
{
    public function index(Request $request): View
    {
        return view('sends.index', [
            'phones' => UserPhone::query()->ownedBy($request->user())->connected()->orderBy('phone')->get(['id', 'phone']),
        ]);
    }

    public function data(Request $request, GroupList $list): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', Rule::in(GroupList::STATUSES)],
            'phone_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);
        $groups = $list->paginate($request->user(), $filters, 20);

        return response()->json([
            'groups' => GroupResource::collection($groups->getCollection()),
            'meta' => ['current_page' => $groups->currentPage(), 'last_page' => $groups->lastPage(), 'total' => $groups->total()],
        ]);
    }

    public function show(MessageGroup $group): View
    {
        Gate::authorize('view', $group);

        return view('sends.show', ['groupId' => $group->id]);
    }

    /** Polled while the send runs: the group, and one page of its messages. */
    public function progress(Request $request, MessageGroup $group): JsonResponse
    {
        Gate::authorize('view', $group);
        $status = MessageStatus::tryFrom((string) $request->query('status'));
        $search = trim((string) $request->query('search'));

        $group = MessageGroup::query()
            ->with(['phone:id,user_id,phone,first_name,last_name,username', 'phone.user:id,name', 'creator:id,name', 'catalogs:id,title,user_id', 'attachment', 'loop'])
            ->withDeliveryCounts()
            ->findOrFail($group->id);

        $messages = $group->messages()
            ->when($status, fn ($q, MessageStatus $s) => $q->where('status', $s))
            ->when($search !== '', fn ($q) => $q->where('peer', 'like', "%{$search}%"))
            ->orderByRaw("FIELD(status, 'sending', 'failed', 'pending', 'sent', 'canceled')")
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString();

        return response()->json([
            'group' => new GroupResource($group),
            'messages' => MessageResource::collection($messages->getCollection()),
            'meta' => ['current_page' => $messages->currentPage(), 'last_page' => $messages->lastPage(), 'total' => $messages->total()],
            'editable_catalogs' => $group->catalogs
                ->filter(fn ($c) => $request->user()->can('update', $c))
                ->map(fn ($c) => ['id' => $c->id, 'title' => $c->title])
                ->values(),
        ]);
    }

    public function cancel(Request $request, MessageGroup $group, GroupProgress $progress): JsonResponse
    {
        Gate::authorize('cancel', $group);
        abort_unless($group->status->isActive(), 409, __('sends.not_active'));
        $count = $progress->cancel($group, $request->user()->id);

        return response()->json(['ok' => true, 'message' => __('sends.canceled', ['count' => $count])]);
    }

    /** A new send to the peers that failed in this one. */
    public function retry(Request $request, MessageGroup $group, MassSend $send): JsonResponse
    {
        Gate::authorize('cancel', $group);
        $retry = $send->retryFailed($request->user(), $group);

        return response()->json(['ok' => true, 'message' => __('sends.retry_queued'), 'redirect' => route('sends.show', $retry)]);
    }

    /** Stops a repeating send: no more rounds, the running round is canceled. */
    public function stopLoop(Request $request, SendLoop $loop, GroupProgress $progress): JsonResponse
    {
        Gate::authorize('stop', $loop);
        abort_unless($loop->isActive(), 409, __('sends.loop_not_active'));
        $progress->stopLoop($loop, $request->user()->id);

        return response()->json(['ok' => true, 'message' => __('sends.loop_stopped')]);
    }
}
