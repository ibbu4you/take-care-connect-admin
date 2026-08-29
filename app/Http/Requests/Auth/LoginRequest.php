<?php

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['boolean'],
        ];
    }

    /**
     * Attempt the login, or throw the right validation error.
     *
     * ── One message for every failure ───────────────────────────────────────
     * Wrong password, unknown address and deactivated account all answer
     * "These credentials do not match our records". Distinguishing them would
     * turn this form into a way to test whether a given email has an account
     * here — and, worse, tell somebody holding a former employee's password
     * that the account exists and is merely switched off.
     *
     * The deactivated case is checked *after* the password, so the answer
     * cannot be used to enumerate accounts without also knowing the password.
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $credentials = [
            'email' => mb_strtolower($this->string('email')->toString()),
            'password' => $this->string('password')->toString(),
        ];

        if (! Auth::attempt($credentials, $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $user = Auth::user();

        // A deactivated account, or one with no role at all, has nothing to do
        // here. Logged straight back out so no session is ever established.
        if (! $user->is_active || ! $user->roles()->exists()) {
            Auth::logout();

            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Both limiters registered in AppServiceProvider have to be clear.
     *
     * Checked here rather than as route middleware so a failure raises a
     * validation error on the form instead of a bare 429 page.
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => __('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Keyed on email AND IP together.
     *
     * Email alone would let anyone lock a colleague out by failing five times
     * against their address; IP alone would let an attacker with a staff list
     * grind every account from one machine at five a minute each.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(
            Str::lower($this->string('email')->toString()).'|'.$this->ip(),
        );
    }
}
