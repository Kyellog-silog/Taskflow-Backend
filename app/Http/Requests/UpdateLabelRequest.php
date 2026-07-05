<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLabelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageLabels', $this->route('label')->project);
    }

    public function rules(): array
    {
        $label = $this->route('label');

        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('labels', 'name')->where('project_id', $label->project_id)->ignore($label->id),
            ],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ];
    }
}
