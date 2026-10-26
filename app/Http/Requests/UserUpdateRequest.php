<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\Role;
use Illuminate\Validation\Rule;

class UserUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->route('user'); // route parameter: user/{user}

        return [
            'name' => 'required|string|max:255',

            'email' => [
                'nullable',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($userId),
            ],

            'password' => 'nullable|string|min:8',

            'role_id' => 'required|exists:roles,id',
            'department_id' => 'nullable|exists:departments,id',

            'telegram_id' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('users', 'telegram_id')->ignore($userId),
            ],
            'max_users'=> 'nullable|integer',
            'has_extra_minutes' => 'nullable|boolean',
            'catalog_ids' => 'nullable|array',
            'catalog_ids.*' => 'integer|exists:catalogs,id',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $roleId = $this->input('role_id');
            $role   = Role::find($roleId);

            if (!$role) {
                return;
            }

            // Adminlar uchun email va password talab qilinsin
            if (in_array($role->name, ['superadmin', 'admin'])) {
                if (!$this->filled('email')) {
                    $validator->errors()->add('email', 'Email is required for admin users.');
                }

                if (!$this->filled('password')) {
                    $validator->errors()->add('password', 'Password is required for admin users.');
                }
            }

            // Non-superadminlar uchun department talab qilinsin
            if ($role->name !== 'superadmin' && !$this->filled('department_id')) {
                $validator->errors()->add(
                    'department_id',
                    'Department is required for non-superadmin users.'
                );
            }
        });
    }
}
