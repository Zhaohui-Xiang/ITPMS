<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDefectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('defect.create');
    }

    public function rules(): array
    {
        return [
            'requirement_id' => ['required', 'integer', 'exists:requirements,id'],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string'],
            'severity' => ['required', 'integer', 'in:1,2,3,4'],
            'defect_type' => ['required', 'integer', 'in:1,2,3,4,5'],
            'discovery_phase' => ['required', 'integer', 'in:1,2'],
            'screenshot' => ['nullable', 'image', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'requirement_id.required' => '必须关联一条需求',
            'requirement_id.exists' => '关联的需求不存在',
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
