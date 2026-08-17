<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('task.create');
    }

    public function rules(): array
    {
        return [
            'requirement_id' => ['required', 'integer', 'exists:requirements,id'],
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'assignee_id' => ['nullable', 'integer', 'exists:users,id'],
            'priority' => ['required', 'integer', 'in:1,2,3,4'],
            'due_date' => ['required', 'date'],
            'remind_days_before' => ['nullable', 'integer', 'min:0'],
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
