<?php

namespace App\Http\Requests;

use App\Models\Defect;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreDefectRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! $this->has('project_id')) {
            return $this->user()->hasPermission('defect.create');
        }

        $projectId = $this->input('project_id');
        if (! is_int($projectId)) {
            return false;
        }

        $project = Project::query()->find($projectId);

        return $project !== null
            && Gate::forUser($this->user())->allows(
                'createForProject',
                [Defect::class, $project],
            );
    }

    protected function prepareForValidation(): void
    {
        if ($this->isJson()) {
            return;
        }

        $normalized = [];
        foreach ([
            'requirement_id',
            'project_id',
            'severity',
            'defect_type',
            'discovery_phase',
        ] as $field) {
            $value = $this->input($field);
            if (is_string($value) && preg_match('/^[1-9]\d*$/D', $value) === 1) {
                $normalized[$field] = (int) $value;
            }
        }

        $this->merge($normalized);
    }

    public function rules(): array
    {
        return [
            'requirement_id' => ['required', 'integer:strict', 'exists:requirements,id'],
            'project_id' => ['required', 'integer:strict', 'exists:projects,id'],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string', 'max:5000'],
            'severity' => ['required', 'integer:strict', 'in:1,2,3,4'],
            'defect_type' => ['required', 'integer:strict', 'in:1,2,3,4,5'],
            'discovery_phase' => ['required', 'integer:strict', 'in:1,2'],
            'screenshot' => ['nullable', 'image', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'requirement_id.required' => '必须关联一条需求',
            'requirement_id.exists' => '关联的需求不存在',
            'project_id.required' => '必须关联一个项目',
            'project_id.exists' => '关联的项目不存在',
            'title.required' => '缺陷标题不能为空',
            'title.max' => '缺陷标题不能超过200个字符',
            'description.required' => '缺陷描述不能为空',
            'severity.required' => '严重程度不能为空',
            'severity.in' => '严重程度无效',
            'defect_type.required' => '缺陷类型不能为空',
            'defect_type.in' => '缺陷类型无效',
            'discovery_phase.required' => '发现阶段不能为空',
            'discovery_phase.in' => '发现阶段无效',
        ];
    }
}
