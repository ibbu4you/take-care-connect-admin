<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Support\Permissions;
use App\Support\Roles;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', User::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'confirmed', Password::defaults()],
            'phone' => ['nullable', 'string', 'max:32'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'team' => ['nullable', 'string', 'max:40'],
            'is_active' => ['boolean'],

            /*
             | At least one role.
             |
             | An account with no role can sign in as far as the password check
             | and is then rejected for having nothing to do — a confusing way to
             | discover that a step was missed at creation time.
             */
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::in(Roles::names())],

            // Validated against the catalogue so a mangled payload cannot create
            // permission rows that no route will ever check.
            'direct_permissions' => ['array'],
            'direct_permissions.*' => ['string', Rule::in(Permissions::all())],
            'denied_permissions' => ['array'],
            'denied_permissions.*' => ['string', Rule::in(Permissions::all())],
        ];
    }

    /**
     * Every role on the payload has to be one this actor may hand out.
     *
     * Checked here as well as in the policy because the policy's `assignRole`
     * takes one role at a time and this payload carries several.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach ($this->input('roles', []) as $role) {
                    if (! $this->user()->can('assignRole', [User::class, $role])) {
                        $validator->errors()->add('roles', "You cannot assign the {$role} role.");
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
            'roles.required' => 'Choose at least one role — an account with no role cannot sign in.',
        ];
    }
}
