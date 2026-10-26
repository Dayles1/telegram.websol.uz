<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ScheduleMessageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'message'       => 'required|string',
            'phone_id'      => 'required|integer|exists:user_phones,id',
            'catalog_ids'   => 'required|array|min:1',
            'catalog_ids.*' => 'integer|exists:catalogs,id',
            'interval'      => 'required|integer,',
            'duration'      => 'required|integer|min:1|max:48'
        ];
    }
}
