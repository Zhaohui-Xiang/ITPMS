<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        // 超管可以编辑任何用户，供应商项目经理可以编辑本团队的用户
        if ($user->isSuperAdmin()) {
            return true;
        }
        return $user->user_type === 2 && $user->hasPermission('user.edit');
    }

    public function rules(): array
    {
        $userId = $this->route('id');

        return [
            'first_name' => ['nullable', 'string', 'max:150'],
            'last_name' => ['nullable', 'string', 'max:150'],
            'display_name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:254', Rule::unique('users', 'email')->ignore($userId)],
            'phone' => ['nullable', 'string', 'max:20'],
        ];
    }
}
