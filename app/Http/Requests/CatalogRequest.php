<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CatalogRequest extends FormRequest
{
    /**
     * Authorization
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validation rules
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],


            'peers' => ['nullable', 'array'],

            'peers.*' => ['string', 'max:255'],

        ];
    }

    /**
     * Custom messages
     */
    public function messages(): array
    {
        return [
            'title.required' => __('messages.validation.title_required'),
            'title.max' => __('messages.validation.title_max'),

            'user_phone_id.exists' => __('messages.validation.phone_not_found'),

            'peers.array' => __('messages.validation.peers_array'),
            'peers.*.string' => __('messages.validation.peers_string'),

        ];
    }

    /**
     * Prepare data before validation
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('peers') && is_string($this->peers)) {

            $peers = preg_split('/[\s,;]+/', $this->peers);

            $this->merge([
                'peers' => array_filter($peers)
            ]);
        }
    }
}