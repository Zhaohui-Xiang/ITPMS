<?php

namespace App\Http\Requests;

use App\Models\Requirement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateRequirementRequest extends FormRequest
{
    public function authorize(): bool
    {
        $requirement = Requirement::query()->find($this->route('id'));

        return $requirement !== null
            && Gate::forUser($this->user())->allows('update', $requirement);
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:200'],
            'description' => ['sometimes', 'string'],
            'priority' => ['sometimes', 'integer', 'in:1,2,3,4'],
            'requirement_type' => ['sometimes', 'integer', 'in:1,2,3,4,5'],
            'expected_completion_date' => ['nullable', 'date'],
            'project_ids' => ['sometimes', 'array', 'min:1'],
            'project_ids.*' => ['integer', 'distinct', 'exists:projects,id'],
            'dev_lead_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.max' => '需求标题不能超过200个字符',
            'priority.in' => '优先级无效',
            'requirement_type.in' => '需求类型无效',
            'project_ids.*.exists' => '所选项目不存在',
            'dev_lead_id.exists' => '开发负责人不存在',
        ];
    }
}
