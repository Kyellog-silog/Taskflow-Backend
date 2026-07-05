<?php

namespace App\Http\Requests;

use App\Models\Status;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageWorkflow', $this->route('project'));
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('statuses', 'name')->where('project_id', $this->route('project')->id),
            ],
            'category' => ['required', Rule::in(Status::CATEGORIES)],
            'position' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
