<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Sending\Attachments;
use App\Sending\MassSend;
use App\Sending\SendOrder;
use Illuminate\Foundation\Http\FormRequest;

/** The web send form (multipart when a file is attached). */
final class SendRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'phone_id' => ['required', 'integer', 'exists:user_phones,id'],
            'catalog_ids' => ['required', 'array', 'min:1'],
            'catalog_ids.*' => ['integer', 'distinct'],
            'message' => ['nullable', 'string', 'max:'.MassSend::MAX_TEXT_LENGTH, 'required_without:file'],
            'file' => Attachments::rules(),
            'repeat_every_minutes' => ['nullable', 'integer', 'min:1', 'max:'.MassSend::MAX_REPEAT_MINUTES],
            'max_rounds' => ['nullable', 'integer', 'min:1', 'max:'.MassSend::MAX_ROUNDS],
        ];
    }

    public function order(): SendOrder
    {
        return new SendOrder(
            catalogIds: array_map('intval', (array) $this->validated('catalog_ids')),
            text: (string) $this->validated('message'),
            file: $this->file('file'),
            repeatEveryMinutes: $this->filled('repeat_every_minutes') ? $this->integer('repeat_every_minutes') : null,
            maxRounds: $this->filled('max_rounds') ? $this->integer('max_rounds') : null,
        );
    }
}
