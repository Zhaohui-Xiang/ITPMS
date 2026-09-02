<?php

namespace App\Http\Requests;

use App\Models\ProjectVersion;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

final class ReleaseProjectVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $version = ProjectVersion::query()->find((int) $this->route('id'));
        if ($version === null) {
            return true;
        }

        $force = $this->input('force');
        if (! is_bool($force) && $force !== null) {
            return ($this->user()?->can('release', $version) ?? false)
                || ($this->user()?->can('forceRelease', $version) ?? false);
        }

        return $this->user()?->can(
            $force === true ? 'forceRelease' : 'release',
            $version,
        ) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lock_version' => [
                'required',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_int($value) || $value < 1) {
                        $fail('The lock version must be a positive JSON integer.');
                    }
                },
            ],
            'force' => [
                'sometimes',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_bool($value)) {
                        $fail('The force field must be a JSON boolean.');
                    }
                },
            ],
            'release_notes' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'force_reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
