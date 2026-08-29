<?php

namespace App\Exceptions;

use App\Support\Workflow\Stage;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A workflow move that the process does not allow.
 *
 * ── Why this is not just a RuntimeException ─────────────────────────────────
 * Asking to move an interview from scheduled straight to published is bad
 * input, not a broken server. Letting it bubble up as a 500 gives the person a
 * stack trace instead of a sentence, and puts a false alarm in the error log
 * next to the failures that actually need attention.
 *
 * Rendering itself as a redirect with a field error means the refusal arrives
 * the same way every other refusal in the application does.
 */
class InvalidStageTransition extends RuntimeException
{
    public static function between(Stage $from, Stage $to): self
    {
        return new self(sprintf(
            'A record at "%s" cannot move to "%s".',
            $from->label(),
            $to->label(),
        ));
    }

    public function render(Request $request)
    {
        return back()->withErrors(['stage' => $this->getMessage()]);
    }
}
