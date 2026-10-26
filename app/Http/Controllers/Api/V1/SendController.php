<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesPhone;
use App\Http\Controllers\Controller;
use App\Sending\Attachments;
use App\Sending\MassSend;
use App\Sending\SendOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SendController extends Controller
{
    use ResolvesPhone;

    /**
     * POST /api/tg/send (JSON, or multipart/form-data with a file)
     *
     * From: phone_id | phone | telegram_user_id.
     * To, exactly one of: catalog_ids[] (every peer of these catalogs) or peer
     * ("@username", "t.me/link", "+998901234567", a chat id).
     * What: message (HTML, up to 4096) and/or file (up to 50 MB).
     * Optional repeat_every_minutes (+ max_rounds): start again that long after each round ends.
     */
    public function store(Request $request, MassSend $send): JsonResponse
    {
        $data = $request->validate($this->phoneRules() + [
            'catalog_ids' => ['nullable', 'array', 'min:1', 'required_without:peer', 'prohibits:peer'],
            'catalog_ids.*' => ['integer'],
            'peer' => ['nullable', 'string', 'max:255', 'required_without:catalog_ids'],
            'message' => ['nullable', 'string', 'max:'.MassSend::MAX_TEXT_LENGTH, 'required_without:file'],
            'file' => Attachments::rules(),
            'repeat_every_minutes' => ['nullable', 'integer', 'min:1', 'max:'.MassSend::MAX_REPEAT_MINUTES],
            'max_rounds' => ['nullable', 'integer', 'min:1', 'max:'.MassSend::MAX_ROUNDS],
        ]);

        $phone = $this->resolvePhone($request->user(), $data);
        if ($phone === null || ! $phone->canSend()) {
            return response()->json(['status' => 'error', 'message' => 'Phone not found or not yours.'], 422);
        }

        $group = $send->create($request->user(), $phone, new SendOrder(
            catalogIds: array_map('intval', $data['catalog_ids'] ?? []),
            peers: isset($data['peer']) ? [$data['peer']] : [],
            text: (string) ($data['message'] ?? ''),
            file: $request->file('file'),
            repeatEveryMinutes: isset($data['repeat_every_minutes']) ? (int) $data['repeat_every_minutes'] : null,
            maxRounds: isset($data['max_rounds']) ? (int) $data['max_rounds'] : null,
        ));

        return response()->json(array_filter([
            'status' => 'success',
            'group_id' => $group->id,
            'loop_id' => $group->send_loop_id,
            'peers_count' => $group->messages()->count(),
        ], fn ($value) => $value !== null));
    }

    /**
     * POST /api/tg/sendInf. The old repeating ("infinity") send never worked.
     * Repeating is now a parameter of /api/tg/send (repeat_every_minutes).
     */
    public function infinity(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => 'Use POST /api/tg/send with repeat_every_minutes.',
        ], 410);
    }
}
