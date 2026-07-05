<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTransitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageWorkflow', $this->route('project'));
    }

    public function rules(): array
    {
        $projectId = $this->route('project')->id;
        $sameProject = Rule::exists('statuses', 'id')->where('project_id', $projectId);

        return [
            'from_status_id' => ['nullable', 'integer', $sameProject, 'different:to_status_id'],
            'to_status_id' => ['required', 'integer', $sameProject],
            'name' => ['nullable', 'string', 'max:100'],
            'allowed_roles' => ['nullable', 'array'],
            'allowed_roles.*' => [Rule::in(['owner', 'admin', 'member'])],
        ];
    }
}
