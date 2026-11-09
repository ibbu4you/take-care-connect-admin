<?php

namespace App\Support\Workflow;

/**
 * What came of one call to a lead.
 *
 * ── Why a fixed list ───────────────────────────────────────────────────────
 * The questions a team lead asks of a calling day — how many calls, how many
 * got through, how many said yes — only have answers if the outcome is a
 * value. Typed into a note, "no answer" is also "NA", "not picking", "dint
 * pick" and a blank line.
 *
 * Kept to the outcomes that change what happens next. "Connected" is not one
 * of them: it is implied by the four that start with a conversation.
 */
enum CallOutcome: string
{
    case Interested = 'interested';
    case CallBack = 'call_back';
    case NotInterested = 'not_interested';
    case NoAnswer = 'no_answer';
    case Busy = 'busy';
    case Unreachable = 'unreachable';
    case WrongNumber = 'wrong_number';

    public function label(): string
    {
        return match ($this) {
            self::Interested => 'Interested',
            self::CallBack => 'Asked to call back',
            self::NotInterested => 'Not interested',
            self::NoAnswer => 'No answer',
            self::Busy => 'Busy or cut the call',
            self::Unreachable => 'Switched off or unreachable',
            self::WrongNumber => 'Wrong number',
        };
    }

    /** Whether somebody actually spoke to the business on this call. */
    public function connected(): bool
    {
        return in_array($this, [self::Interested, self::CallBack, self::NotInterested], true);
    }

    /**
     * When to try again if the caller does not say.
     *
     * A missed call is worth another try tomorrow; somebody who asked for a call
     * back usually means in a couple of days. "Not interested" and a wrong
     * number get no date — chasing them is the wrong default.
     */
    public function defaultRetryDays(): ?int
    {
        return match ($this) {
            self::NoAnswer, self::Busy, self::Unreachable => 1,
            self::CallBack => 2,
            self::Interested => 1,
            self::NotInterested, self::WrongNumber => null,
        };
    }

    /** @return list<array{value: string, label: string, connected: bool, retry_days: int|null}> */
    public static function options(): array
    {
        return array_map(static fn (self $outcome): array => [
            'value' => $outcome->value,
            'label' => $outcome->label(),
            'connected' => $outcome->connected(),
            'retry_days' => $outcome->defaultRetryDays(),
        ], self::cases());
    }

    /** @return list<string> */
    public static function connectedValues(): array
    {
        return array_values(array_map(
            static fn (self $outcome): string => $outcome->value,
            array_filter(self::cases(), static fn (self $outcome): bool => $outcome->connected()),
        ));
    }
}
