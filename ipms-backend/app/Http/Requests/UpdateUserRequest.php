<?php

namespace App\Http\Requests;

use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

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

    protected function prepareForValidation(): void
    {
        if (! $this->has('email')) {
            return;
        }

        $email = $this->input('email');

        $this->merge([
            'email' => is_string($email) && $email !== '' ? strtolower($email) : '',
        ]);
    }

    public function rules(): array
    {
        $userId = (int) $this->route('id');

        return [
            'first_name' => ['nullable', 'string', 'max:150'],
            'last_name' => ['nullable', 'string', 'max:150'],
            'display_name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:254', $this->uniqueEmailRule($userId)],
            'phone' => ['nullable', 'string', 'max:20'],
        ];
    }

    private function uniqueEmailRule(int $userId): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($userId): void {
            if (! is_string($value) || $value === '') {
                return;
            }

            $exists = User::query()
                ->whereRaw('LOWER(email) = LOWER(?)', [$value])
                ->where('id', '<>', $userId)
                ->exists();

            if ($exists) {
                $fail('The email has already been taken.');
            }
        };
    }
}
