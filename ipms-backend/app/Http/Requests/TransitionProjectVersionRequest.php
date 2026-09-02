<?php

namespace App\Http\Requests;

use App\Enums\ProjectVersionStatus;
use App\Models\ProjectVersion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class TransitionProjectVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $version = ProjectVersion::query()->find((int) $this->route('id'));
        if ($version === null) {
            return true;
        }

        return $this->user()?->can('transition', $version) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => [
                'required',
                'integer:strict',
                Rule::in(array_map(
                    static fn (ProjectVersionStatus $status): int => $status->value,
                    ProjectVersionStatus::cases(),
                )),
            ],
            'lock_version' => ['required', 'integer:strict', 'min:1'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
