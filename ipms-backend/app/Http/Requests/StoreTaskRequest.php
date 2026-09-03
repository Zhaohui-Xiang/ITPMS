<?php

namespace App\Http\Requests;

use App\Models\Project;
use App\Models\Requirement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        $projectId = $this->input('project_id');
        if (! is_int($projectId)) {
            return false;
        }

        $project = Project::query()->find($projectId);
        $requirementId = $this->input('requirement_id');
        $requirement = is_int($requirementId)
            ? Requirement::query()->find($requirementId)
            : null;

        return $project !== null && $requirement !== null
            && Gate::forUser($this->user())->allows(
                'createTask',
                [$requirement, $project],
            );
    }

    protected function prepareForValidation(): void
    {
        if ($this->route('id') !== null) {
            $this->merge([
                'requirement_id' => (int) $this->route('id'),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'requirement_id' => ['required', 'integer:strict', 'exists:requirements,id'],
            'project_id' => ['required', 'integer:strict', 'exists:projects,id'],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'assignee_id' => ['nullable', 'integer:strict', 'exists:users,id'],
            'priority' => ['required', 'integer:strict', 'in:1,2,3,4'],
            'due_date' => ['required', 'date'],
            'remind_days_before' => ['nullable', 'integer:strict', 'min:0'],
            'estimated_hours' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'requirement_id.required' => '所属需求不能为空',
            'requirement_id.exists' => '所属需求不存在',
            'project_id.required' => '所属项目不能为空',
            'project_id.exists' => '所属项目不存在',
            'title.required' => '任务标题不能为空',
            'title.max' => '任务标题不能超过200个字符',
            'priority.required' => '优先级不能为空',
            'priority.in' => '优先级无效',
            'due_date.required' => '截止日期不能为空',
            'due_date.date' => '截止日期格式无效',
            'assignee_id.exists' => '负责人不存在',
        ];
    }
}
