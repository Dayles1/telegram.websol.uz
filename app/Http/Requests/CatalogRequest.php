<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Sending\Peers;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create/update a catalog. Peers come either as an array ("peers", the API) or
 * as text, one per line ("peers_text", the web form); both end up normalized.
 */
final class CatalogRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'peers' => ['nullable', 'array', 'max:10000'],
            'peers.*' => ['nullable', 'string', 'max:255'],
            'peers_text' => ['nullable', 'string', 'max:500000'],
            'is_global' => ['sometimes', 'boolean'],
            'timer_minutes' => ['nullable', 'integer', 'min:0', 'max:10080'],
        ];
    }

    /** @return array{title: string, peers: list<string>, is_global?: bool, timer_minutes?: ?int} */
    public function catalogData(): array
    {
        $peers = $this->filled('peers_text')
            ? Peers::parse((string) $this->input('peers_text'))
            : Peers::unique((array) $this->input('peers', []));

        $data = ['title' => trim((string) $this->input('title')), 'peers' => $peers];

        // Only an admin or the superadmin decides who else sees a catalog.
        if ($this->user()->seesOthers() && $this->has('is_global')) {
            $data['is_global'] = $this->boolean('is_global');
        }
        if ($this->has('timer_minutes')) {
            $data['timer_minutes'] = $this->filled('timer_minutes') ? (int) $this->input('timer_minutes') : null;
        }

        return $data;
    }
}
