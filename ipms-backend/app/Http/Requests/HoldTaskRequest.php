<?php

namespace App\Http\Requests;

use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class HoldTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        $task = Task::query()->findOrFail((int) $this->route('id'));

        return Gate::forUser($this->user())->allows('hold', $task);
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
