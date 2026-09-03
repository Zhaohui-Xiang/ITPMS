<?php

namespace App\Http\Requests;

use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        $task = Task::query()->findOrFail((int) $this->route('id'));
        $gate = Gate::forUser($this->user());

        if (! $gate->allows('update', $task)) {
            return false;
        }

        if (array_key_exists('assignee_id', $this->all())) {
            return $gate->allows('assign', $task);
        }

        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'assignee_id' => ['nullable', 'integer:strict', 'exists:users,id'],
            'priority' => ['sometimes', 'integer:strict', 'in:1,2,3,4'],
            'due_date' => ['sometimes', 'date'],
            'remind_days_before' => ['nullable', 'integer:strict', 'min:0'],
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
