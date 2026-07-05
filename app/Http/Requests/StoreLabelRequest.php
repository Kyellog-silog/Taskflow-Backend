<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLabelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageLabels', $this->route('project'));
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:50',
                Rule::unique('labels', 'name')->where('project_id', $this->route('project')->id),
            ],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ];
    }
}
