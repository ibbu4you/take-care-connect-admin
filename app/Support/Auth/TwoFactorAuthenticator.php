<?php

namespace App\Support\Auth;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP enrolment and verification.
 *
 * Everything about second factors lives here so the controller stays a thin
 * shell: it is the kind of code that is easy to get subtly wrong and expensive
 * to get wrong twice.
 */
class TwoFactorAuthenticator
{
    /**
     * How many 30-second steps either side of now are accepted.
     *
     * One step, not zero: phone clocks drift, and somebody typing the last two
     * digits as the code rolls over is not an attacker. Wider than this starts
     * meaningfully extending the window a stolen code stays usable.
     */
    private const WINDOW = 1;

    private const RECOVERY_CODE_COUNT = 8;

    public function __construct(private readonly Google2FA $google2fa) {}

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey();
    }

    /** The otpauth:// URI an authenticator app scans. */
    public function provisioningUri(User $user, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl(
            config('app.name'),
            $user->email,
            $secret,
        );
    }

    /**
     * The QR code as an inline SVG.
     *
     * SVG rather than a PNG data URI: it stays sharp at any size, needs no GD
     * call, and can be dropped straight into the page without a second request
     * for an image that contains a shared secret.
     */
    public function qrCodeSvg(User $user, string $secret): string
    {
        $writer = new Writer(
            new ImageRenderer(new RendererStyle(200, 0), new SvgImageBackEnd),
        );

        return $writer->writeString($this->provisioningUri($user, $secret));
    }

    public function verify(string $secret, string $code): bool
    {
        // The user typed this: strip the space authenticator apps display.
        $code = preg_replace('/\s+/', '', $code) ?? '';

        return (bool) $this->google2fa->verifyKey($secret, $code, self::WINDOW);
    }

    /**
     * Single-use codes for when the phone is lost.
     *
     * Without these, a broken phone means an administrator has to clear the
     * secret from the database — which is a worse habit to build than handing
     * out eight one-time strings.
     *
     * @return list<string>
     */
    public function generateRecoveryCodes(): array
    {
        return collect(range(1, self::RECOVERY_CODE_COUNT))
            ->map(fn (): string => Str::lower(Str::random(5).'-'.Str::random(5)))
            ->all();
    }

    /**
     * Spend a recovery code, returning what remains.
     *
     * Returns null when the code is not valid. The caller must persist the
     * returned list — a recovery code that survives being used is not a
     * single-use code.
     *
     * @param  list<string>  $codes
     * @return list<string>|null
     */
    public function consumeRecoveryCode(array $codes, string $candidate): ?array
    {
        $candidate = Str::lower(trim($candidate));

        $remaining = [];
        $matched = false;

        foreach ($codes as $code) {
            // hash_equals rather than ===: comparison time must not depend on
            // how many leading characters were guessed correctly.
            if (! $matched && hash_equals($code, $candidate)) {
                $matched = true;

                continue;
            }

            $remaining[] = $code;
        }

        return $matched ? $remaining : null;
    }
}
