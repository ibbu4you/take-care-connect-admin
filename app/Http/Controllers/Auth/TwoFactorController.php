<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\Auth\TwoFactorAuthenticator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Enrolling in, challenging for, and disabling the second factor.
 *
 * The session flag `two_factor_passed` is what RequireTwoFactor looks for. It
 * is set only after a code is verified, and cleared on every fresh login by
 * virtue of the session being regenerated.
 */
class TwoFactorController extends Controller
{
    public function __construct(private readonly TwoFactorAuthenticator $authenticator) {}

    /** Step one of enrolment: show a secret and its QR code. */
    public function setup(Request $request): Response
    {
        $user = $request->user();

        /*
         | The pending secret lives in the session, not on the user row.
         |
         | Writing it to the database before a code has been verified would
         | leave an account in a half-enrolled state if the person closed the
         | tab — `two_factor_secret` set but `confirmed_at` null — and the next
         | login would then demand codes from an app that was never set up.
         */
        $secret = $request->session()->get('two_factor_pending_secret')
            ?? tap($this->authenticator->generateSecret(), function (string $secret) use ($request) {
                $request->session()->put('two_factor_pending_secret', $secret);
            });

        return Inertia::render('Auth/TwoFactorSetup', [
            'secret' => $secret,
            'qr_code' => $this->authenticator->qrCodeSvg($user, $secret),
            'already_enabled' => $user->hasTwoFactorEnabled(),
        ]);
    }

    /** Step two: verify a code, then store the secret and the recovery codes. */
    public function confirm(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string']]);

        $secret = $request->session()->get('two_factor_pending_secret');

        if (! $secret) {
            return redirect()->route('two-factor.setup')
                ->with('error', 'That enrolment expired. Start again.');
        }

        if (! $this->authenticator->verify($secret, $request->string('code')->toString())) {
            throw ValidationException::withMessages([
                'code' => 'That code is not right. Check your authenticator app and try again.',
            ]);
        }

        $codes = $this->authenticator->generateRecoveryCodes();

        $request->user()->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => $codes,
            'two_factor_confirmed_at' => now(),
        ])->save();

        $request->session()->forget('two_factor_pending_secret');
        $request->session()->put('two_factor_passed', true);

        // Flashed, so the codes are shown exactly once. They are encrypted at
        // rest and never rendered again — that is the point of them.
        return redirect()
            ->route('profile.edit')
            ->with('recovery_codes', $codes)
            ->with('success', 'Two-factor authentication is on. Save your recovery codes now.');
    }

    /** The challenge shown after a password when 2FA is enrolled. */
    public function challenge(Request $request): Response
    {
        return Inertia::render('Auth/TwoFactorChallenge');
    }

    public function verify(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string']]);

        $user = $request->user();
        $code = $request->string('code')->toString();

        if ($this->authenticator->verify((string) $user->two_factor_secret, $code)) {
            $request->session()->put('two_factor_passed', true);

            return redirect()->intended('/dashboard');
        }

        // Fall back to a recovery code, which is spent on use.
        $remaining = $this->authenticator->consumeRecoveryCode(
            $user->two_factor_recovery_codes ?? [],
            $code,
        );

        if ($remaining !== null) {
            $user->forceFill(['two_factor_recovery_codes' => $remaining])->save();

            $request->session()->put('two_factor_passed', true);

            return redirect()
                ->intended('/dashboard')
                ->with('info', sprintf(
                    'Recovery code used. You have %d left — generate new ones from your profile.',
                    count($remaining),
                ));
        }

        throw ValidationException::withMessages([
            'code' => 'That code is not right.',
        ]);
    }

    /**
     * Turn it off.
     *
     * Requires the current password: an unlocked laptop must not be enough to
     * strip the second factor off the account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);

        $user = $request->user();

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $request->session()->forget('two_factor_passed');

        return back()->with('success', 'Two-factor authentication is off.');
    }

    /** Sign out from the challenge screen, for somebody who cannot get past it. */
    public function abandon(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
