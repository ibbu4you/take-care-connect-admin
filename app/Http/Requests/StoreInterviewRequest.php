<?php

namespace App\Http\Requests;

use App\Models\Interview;
use App\Models\Project;
use App\Support\Workflow\InterviewKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreInterviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Interview::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(InterviewKind::values())],
            'title' => ['required', 'string', 'max:255'],

            'subject_name' => ['required', 'string', 'max:255'],
            'subject_designation' => ['nullable', 'string', 'max:160'],
            'subject_company' => ['nullable', 'string', 'max:160'],
            'subject_phone' => ['nullable', 'string', 'max:32'],
            'subject_email' => ['nullable', 'email', 'max:255'],

            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')],

            // Every interview needs somebody to run it — an unassigned one sits
            // in a list nobody considers theirs.
            'anchor_id' => ['required', 'integer', Rule::exists('users', 'id')],

            'scheduled_at' => ['required', 'date'],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:600'],

            'mode' => ['required', Rule::in(['in_person', 'google_meet'])],
            'location' => ['nullable', 'string', 'max:255'],
            'meeting_url' => ['nullable', 'url', 'max:512'],

            'due_on' => ['nullable', 'date'],
            'priority' => ['required', Rule::in(['low', 'normal', 'high'])],
            'language' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $mode = $this->input('mode');

                /*
                 | The detail that makes the interview actually happen.
                 |
                 | A Meet interview with no link, or an in-person one with no
                 | address, is a diary entry nobody can act on — and it is
                 | discovered at the appointment, not before it.
                 */
                if ($mode === 'google_meet' && blank($this->input('meeting_url'))) {
                    $validator->errors()->add('meeting_url', 'Add the Google Meet link so the anchor can join.');
                }

                if ($mode === 'in_person' && blank($this->input('location'))) {
                    $validator->errors()->add('location', 'Add where this is taking place.');
                }

                if ($this->filled('due_on') && $this->filled('scheduled_at')) {
                    if (strtotime((string) $this->input('due_on')) < strtotime((string) $this->input('scheduled_at'))) {
                        $validator->errors()->add('due_on', 'The deadline cannot fall before the interview itself.');
                    }
                }

                /*
                 | The project must belong to the client.
                 |
                 | Both fields are optional and independently valid, so nothing
                 | else catches this: booking against client A under a project
                 | sold to client C files the work in the wrong place and counts
                 | it towards the wrong package's delivery. The project page
                 | would then read "3 of 6 delivered" on strength of an
                 | interview that has nothing to do with it.
                 */
                if ($this->filled('project_id')) {
                    $project = Project::find($this->integer('project_id'));

                    if ($project !== null && (int) $project->client_id !== $this->integer('client_id')) {
                        $validator->errors()->add(
                            'project_id',
                            'That project belongs to a different client. Choose the client it was sold to, or leave the project blank.',
                        );
                    }
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'anchor_id.required' => 'Choose the anchor who will run this interview.',
            'scheduled_at.required' => 'Set a date and time.',
        ];
    }
}
