<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\Role;

class UserStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',

            'email' => 'nullable|string|email|max:255|unique:users,email',
            'password' => 'nullable|string|min:8',

            'role_id' => 'required|exists:roles,id',
            'department_id' => 'nullable|exists:departments,id',
            'telegram_id' => 'nullable|string|max:255|unique:users,telegram_id',
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

            if (in_array($role->name, ['superadmin', 'admin'])) {
                if (!$this->filled('email')) {
                    $validator->errors()->add('email', 'Email is required for admin users.');
                }

                if (!$this->filled('password')) {
                    $validator->errors()->add('password', 'Password is required for admin users.');
                }
            }

            if ($role->name !== 'superadmin' && !$this->filled('department_id')) {
                $validator->errors()->add(
                    'department_id',
                    'Department is required for non-superadmin users.'
                );
            }
        });
    }
}
