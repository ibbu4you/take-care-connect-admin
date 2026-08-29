<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A person's own account.
 *
 * Deliberately narrow: name, contact details, avatar, password and 2FA. Job
 * title, team, roles and permissions are all set by an administrator — letting
 * somebody edit their own job title is harmless, but the same form editing
 * their own roles would be a privilege-escalation route, so the whole category
 * stays out.
 */
class ProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Profile/Edit', [
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'job_title' => $user->job_title,
                'team' => $user->team,
                'bio' => $user->bio,
                'avatar_url' => $user->avatarUrl(),
                'initials' => $user->initials(),
                'roles' => $user->getRoleNames()->all(),
                'two_factor_enabled' => $user->hasTwoFactorEnabled(),
                'recovery_codes_left' => count($user->two_factor_recovery_codes ?? []),
                'last_login_at' => $user->last_login_at?->toIso8601String(),
            ],

            // Shown once, immediately after enrolment, then never again.
            'recovery_codes' => $request->session()->get('recovery_codes'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:32'],
            'bio' => ['nullable', 'string', 'max:1000'],
            'avatar' => ['nullable', 'image', 'max:2048'],
        ]);

        if ($request->hasFile('avatar')) {
            // Replace rather than accumulate: without this, every change of
            // photo leaves the previous one on disk forever.
            if (filled($user->avatar_path)) {
                Storage::disk('public')->delete($user->avatar_path);
            }

            $user->avatar_path = $request->file('avatar')->store('avatars', 'public');
        }

        $user->fill([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'bio' => $validated['bio'] ?? null,
        ])->save();

        return back()->with('success', 'Profile updated.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            // `current_password` proves the person at the keyboard is the
            // account holder, not somebody who found it unlocked.
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $request->user()->update([
            'password' => Hash::make($validated['password']),
        ]);

        /*
         | Keep this session alive, kill every other one.
         |
         | Changing a password is what somebody does when they think an old
         | session is compromised, so leaving those sessions signed in defeats
         | the point. logoutOtherDevices re-hashes the remember token, which
         | invalidates every other session for this user.
         */
        Auth::logoutOtherDevices($validated['password']);

        return back()->with('success', 'Password changed. Other devices have been signed out.');
    }
}
