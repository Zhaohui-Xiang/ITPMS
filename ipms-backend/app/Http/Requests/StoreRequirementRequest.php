<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRequirementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('requirement.create');
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string'],
            'priority' => ['required', 'integer', 'in:1,2,3,4'],
            'requirement_type' => ['required', 'integer', 'in:1,2,3,4,5'],
            'expected_completion_date' => ['nullable', 'date'],
            'project_ids' => ['required', 'array'],
            'project_ids.*' => ['integer', 'exists:projects,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => '需求标题不能为空',
            'title.max' => '需求标题不能超过200个字符',
            'description.required' => '需求描述不能为空',
            'priority.required' => '优先级不能为空',
            'priority.in' => '优先级无效',
            'requirement_type.required' => '需求类型不能为空',
            'requirement_type.in' => '需求类型无效',
            'project_ids.required' => '必须关联至少一个项目',
            'project_ids.*.exists' => '所选项目不存在',
        ];
    }
}
