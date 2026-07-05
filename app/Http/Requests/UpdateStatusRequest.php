<?php

namespace App\Http\Requests;

use App\Models\Status;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageWorkflow', $this->route('status')->project);
    }

    public function rules(): array
    {
        $status = $this->route('status');

        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('statuses', 'name')->where('project_id', $status->project_id)->ignore($status->id),
            ],
            'category' => ['sometimes', 'required', Rule::in(Status::CATEGORIES)],
            'position' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
