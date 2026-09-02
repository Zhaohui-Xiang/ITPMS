<?php

namespace App\Http\Requests;

use App\Models\Project;
use App\Models\ProjectVersion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class StoreProjectVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = Project::query()->find((int) $this->route('projectId'));
        if ($project === null) {
            return true;
        }

        return $this->user()?->can(
            'create',
            [ProjectVersion::class, $project],
        ) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/'],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'owner_id' => ['nullable', 'integer:strict', 'exists:users,id'],
            'planned_start_date' => ['nullable', 'date_format:Y-m-d'],
            'planned_release_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:planned_start_date'],
            'release_notes' => ['nullable', 'string', 'max:10000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $ownerId = $this->input('owner_id');
            if ($ownerId === null || $validator->errors()->has('owner_id')) {
                return;
            }

            $project = Project::query()->find((int) $this->route('projectId'));
            if ($project !== null && $project->manager_id !== $ownerId) {
                $validator->errors()->add(
                    'owner_id',
                    'The version owner must be the assigned internal IT project manager.',
                );
            }
        });
    }
}
