<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('task.edit');
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'assignee_id' => ['nullable', 'integer', 'exists:users,id'],
            'priority' => ['sometimes', 'integer', 'in:1,2,3,4'],
            'due_date' => ['sometimes', 'date'],
            'remind_days_before' => ['nullable', 'integer', 'min:0'],
            'estimated_hours' => ['nullable', 'numeric', 'min:0'],
            'actual_hours' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.max' => '任务标题不能超过200个字符',
            'priority.in' => '优先级无效',
            'due_date.date' => '截止日期格式无效',
            'assignee_id.exists' => '负责人不存在',
        ];
    }
}
