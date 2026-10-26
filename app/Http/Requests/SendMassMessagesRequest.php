<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendMassMessagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
    public function rules(): array
    {
        return [
            'phone_id'      => 'required|exists:user_phones,id',
            'catalog_ids'   => 'required|array',
            'catalog_ids.*' => 'exists:catalogs,id',
            'message'       => 'required|string',
            'interval'      => 'required|integer|min:0|max:1440',
            'duration'      => 'required|integer|min:1|max:48',
        ];
    }
}
