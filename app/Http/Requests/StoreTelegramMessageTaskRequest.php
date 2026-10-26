<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTelegramMessageTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sender_phone_id' => ['required', 'integer', 'exists:user_phones,id'],
            'message' => ['required', 'string', 'min:1'],

            'target_tg_id' => ['required', 'string'],
            'target_phone' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'sender_phone_id.required' => 'Sender telefoni tanlanishi kerak.',
            'message.required' => 'Xabar matni kerak.',
            'target_tg_id.required_without' => 'Telegram ID yoki telefon raqamdan biri kerak.',
            'target_phone.required_without' => 'Telegram ID yoki telefon raqamdan biri kerak.',
        ];
    }
}