<?php

namespace App\Http\Requests;

use App\Models\ProjectVersion;
use DateTimeImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class UpdateProjectVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $version = ProjectVersion::query()->find((int) $this->route('id'));
        if ($version === null) {
            return true;
        }

        $ability = $this->isMethod('DELETE') ? 'delete' : 'update';

        return $this->user()?->can($ability, $version) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer:strict', 'min:1'],
            'code' => ['sometimes', 'required', 'string', 'max:50', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/'],
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string'],
            'owner_id' => ['sometimes', 'nullable', 'integer:strict', 'exists:users,id'],
            'planned_start_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'planned_release_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'release_notes' => ['sometimes', 'nullable', 'string', 'max:10000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->isMethod('DELETE') || $validator->errors()->isNotEmpty()) {
                return;
            }

            $input = $this->all();
            $version = ProjectVersion::query()
                ->with('project:id,manager_id')
                ->find((int) $this->route('id'));
            if ($version === null) {
                return;
            }

            if (array_key_exists('owner_id', $input)
                && $input['owner_id'] !== null
                && $version->project?->manager_id !== $input['owner_id']) {
                $validator->errors()->add(
                    'owner_id',
                    'The version owner must be the assigned internal IT project manager.',
                );
            }

            $changesStart = array_key_exists('planned_start_date', $input);
            $changesRelease = array_key_exists('planned_release_date', $input);
            if (! $changesStart && ! $changesRelease) {
                return;
            }

            $start = $changesStart
                ? $input['planned_start_date']
                : $version->planned_start_date?->toDateString();
            $release = $changesRelease
                ? $input['planned_release_date']
                : $version->planned_release_date?->toDateString();

            if ($start === null || $release === null) {
                return;
            }

            if (new DateTimeImmutable((string) $release)
                < new DateTimeImmutable((string) $start)) {
                $validator->errors()->add(
                    'planned_release_date',
                    'The planned release date must be on or after the planned start date.',
                );
            }
        });
    }
}
