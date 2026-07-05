<?php

namespace App\Http\Requests;

use App\Models\Team;
use Illuminate\Foundation\Http\FormRequest;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Models\Project::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'key' => ['required', 'string', 'max:8', 'regex:/^[A-Z][A-Z0-9]{1,7}$/', 'unique:projects,key'],
            'description' => ['nullable', 'string', 'max:2000'],
            'team_id' => [
                'nullable',
                'integer',
                'exists:teams,id',
                function ($attribute, $value, $fail) {
                    $team = Team::find($value);
                    if ($team && ! $team->canCreateBoards($this->user())) {
                        $fail('You do not have permission to create projects in this team.');
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'key.regex' => 'The key must be 2-8 characters, uppercase letters and digits, starting with a letter (e.g. TF, PROJ1).',
        ];
    }
}
