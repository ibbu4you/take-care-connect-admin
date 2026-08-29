<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Policies\UserPolicy;
use App\Support\Permissions;
use App\Support\Roles;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('user'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var User $subject */
        $subject = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($subject->id)],

            // Optional on update: an empty box means "leave the password alone",
            // which is the common case when only a job title is being corrected.
            'password' => ['nullable', 'confirmed', Password::defaults()],

            'phone' => ['nullable', 'string', 'max:32'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'team' => ['nullable', 'string', 'max:40'],
            'is_active' => ['boolean'],

            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::in(Roles::names())],

            'direct_permissions' => ['array'],
            'direct_permissions.*' => ['string', Rule::in(Permissions::all())],
            'denied_permissions' => ['array'],
            'denied_permissions.*' => ['string', Rule::in(Permissions::all())],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var User $subject */
                $subject = $this->route('user');
                $actor = $this->user();

                foreach ($this->input('roles', []) as $role) {
                    if (! $actor->can('assignRole', [User::class, $role])) {
                        $validator->errors()->add('roles', "You cannot assign the {$role} role.");
                    }
                }

                /*
                 | Nobody may switch themselves off, or take away their own
                 | administrative access.
                 |
                 | Both are one-way doors: the session ends on the next request
                 | and the only account that could undo it is the one that just
                 | did it. The policy covers deactivating *other* people; this
                 | covers the self-inflicted case, which the policy cannot see
                 | because it does not receive the submitted payload.
                 */
                if ($actor->is($subject)) {
                    if (! $this->boolean('is_active')) {
                        $validator->errors()->add('is_active', 'You cannot deactivate your own account.');
                    }

                    if (in_array('users.update', $this->input('denied_permissions', []), true)) {
                        $validator->errors()->add(
                            'denied_permissions',
                            'You cannot revoke your own permission to manage accounts.',
                        );
                    }

                    $keepsAdminRole = in_array(Roles::ADMIN, $this->input('roles', []), true);

                    if ($subject->hasRole(Roles::ADMIN) && ! $keepsAdminRole) {
                        $validator->errors()->add('roles', 'You cannot remove your own administrator role.');
                    }
                }

                /*
                 | The last Admin cannot be demoted.
                 |
                 | Admin is the top role and the only one holding `users.update`
                 | and `roles.update`. An organisation with none has nobody who
                 | can create an account, fix a permission or promote anybody —
                 | and no way back except editing the database by hand.
                 |
                 | Counted among *active* admins: a deactivated one cannot sign
                 | in to fix anything, so they are not a fallback.
                 */
                if ($subject->hasRole(Roles::ADMIN) && ! in_array(Roles::ADMIN, $this->input('roles', []), true)) {
                    $remaining = User::role(Roles::ADMIN)
                        ->active()
                        ->where('id', '!=', $subject->id)
                        ->count();

                    if ($remaining === 0) {
                        $validator->errors()->add(
                            'roles',
                            'This is the only administrator. Promote somebody else before changing this account.',
                        );
                    }
                }

                /*
                 | The last Admin cannot have administrative permissions taken
                 | away either.
                 |
                 | Revocations apply to admins now — nothing bypasses them — so
                 | revoking `users.update` from the only Admin would leave
                 | nobody able to grant it back. Demotion and revocation are the
                 | same door, and both have to be held shut.
                 */
                $denied = (array) $this->input('denied_permissions', []);

                if ($denied !== [] && UserPolicy::isLastAdmin($subject)) {
                    $blocked = array_intersect($denied, [
                        'users.update', 'users.permissions.manage', 'roles.update',
                    ]);

                    if ($blocked !== []) {
                        $validator->errors()->add(
                            'denied_permissions',
                            'This is the only administrator. Taking away account or role permissions would leave nobody able to give them back.',
                        );
                    }
                }
            },
        ];
    }
}
