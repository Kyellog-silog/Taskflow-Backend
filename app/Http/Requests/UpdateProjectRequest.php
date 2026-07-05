<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('project'));
    }

    /**
     * The project key is intentionally not updatable — issue keys derived
     * from it are immutable.
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'lead_user_id' => [
                'sometimes',
                'required',
                'integer',
                'exists:users,id',
                function ($attribute, $value, $fail) {
                    $project = $this->route('project');
                    $newLead = User::find($value);
                    if (! $newLead || ! $project->isMember($newLead)) {
                        $fail('The project lead must be a member of the project.');
                    }
                },
            ],
        ];
    }
}
