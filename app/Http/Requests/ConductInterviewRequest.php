<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Marking an interview conducted.
 *
 * ── What this deliberately does not collect ────────────────────────────────
 * The questions and answers. The anchor runs the recording through Turboscript
 * and uploads the exported PDF instead, which is faster than retyping an
 * interview and produces a better record than anybody typing from memory an
 * hour later.
 *
 * What is left here is the context the transcript cannot carry: where the
 * recording lives, what language it was in, whether the subject agreed to
 * publication, and a short brief for whoever writes it up.
 */
class ConductInterviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('conduct', $this->route('interview'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'conducted_at' => ['nullable', 'date', 'before_or_equal:now'],

            // Stored in `qa_summary` — the column kept its name; the field is a
            // brief for the writer now rather than a summary of a Q&A.
            'qa_summary' => ['nullable', 'string', 'max:5000'],
            'recording_url' => ['nullable', 'url', 'max:512'],
            'language' => ['nullable', 'string', 'max:40'],
            'consent_to_publish' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'conducted_at.before_or_equal' => 'An interview cannot have been conducted in the future.',
        ];
    }
}
