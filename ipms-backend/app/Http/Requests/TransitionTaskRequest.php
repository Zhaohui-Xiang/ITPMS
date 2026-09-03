<?php

namespace App\Http\Requests;

use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class TransitionTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        $task = Task::query()->findOrFail((int) $this->route('id'));

        return Gate::forUser($this->user())->allows('transition', $task);
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'integer:strict', 'in:1,2,3,4'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
