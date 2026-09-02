<?php

namespace App\Http\Requests;

use App\Models\Project;
use App\Models\ProjectVersion;
use App\Models\RequirementProject;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class AssignRequirementVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = Project::query()->find((int) $this->route('projectId'));
        if ($project === null) {
            return true;
        }

        $probe = new ProjectVersion(['project_id' => $project->id]);
        $probe->setRelation('project', $project);

        return $this->user()?->can('update', $probe) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $versionRule = $this->isMethod('PUT') ? 'required' : 'prohibited';

        return [
            'project_version_id' => [$versionRule, 'integer:strict', 'exists:project_versions,id'],
            'lock_version' => ['required', 'integer:strict', 'min:1'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $requirementId = (int) $this->route('requirementId');
            $projectId = (int) $this->route('projectId');

            $membershipExists = RequirementProject::query()
                ->where('requirement_id', $requirementId)
                ->where('project_id', $projectId)
                ->exists();

            if (! $membershipExists) {
                $validator->errors()->add(
                    'requirement_id',
                    'The requirement is not assigned to this project.',
                );
            }

            if (! $this->isMethod('PUT') || ! is_int($this->input('project_version_id'))) {
                return;
            }

            $versionMatches = ProjectVersion::query()
                ->whereKey((int) $this->input('project_version_id'))
                ->where('project_id', $projectId)
                ->exists();

            if (! $versionMatches) {
                $validator->errors()->add(
                    'project_version_id',
                    'The project version does not belong to this project.',
                );
            }
        });
    }
}
