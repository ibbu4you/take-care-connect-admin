<?php

namespace App\Support\Workflow;

/**
 * Something a salesperson did on a deal.
 *
 * Every type that is a conversation has outcomes, because "how many meetings
 * actually happened" is the number a sales manager asks for, and "we had a
 * meeting" in a note does not count it.
 */
enum ActivityType: string
{
    case Call = 'call';
    case Meeting = 'meeting';
    case Visit = 'visit';
    case Whatsapp = 'whatsapp';
    case Email = 'email';

    public function label(): string
    {
        return match ($this) {
            self::Call => 'Call',
            self::Meeting => 'Meeting',
            self::Visit => 'Visit',
            self::Whatsapp => 'WhatsApp',
            self::Email => 'Email',
        };
    }

    /**
     * What can come of this kind of activity, value => label. Empty when the
     * activity has no outcome worth recording (a WhatsApp sent is just sent).
     *
     * @return array<string, string>
     */
    public function outcomes(): array
    {
        return match ($this) {
            self::Call => array_combine(
                array_map(fn (CallOutcome $o) => $o->value, CallOutcome::cases()),
                array_map(fn (CallOutcome $o) => $o->label(), CallOutcome::cases()),
            ),
            self::Meeting, self::Visit => [
                'held' => 'Held',
                'postponed' => 'Postponed',
                'no_show' => 'They did not turn up',
            ],
            self::Whatsapp, self::Email => [],
        };
    }

    /** The follow-up channel a next step of this type becomes. */
    public function followUpChannel(): string
    {
        return match ($this) {
            self::Call => 'call',
            self::Meeting => 'meeting',
            self::Visit => 'visit',
            self::Whatsapp => 'whatsapp',
            self::Email => 'email',
        };
    }

    /** Whether this activity, with this outcome, means somebody actually spoke to the buyer. */
    public static function spokeTo(self $type, ?string $outcome): bool
    {
        return match ($type) {
            self::Call => $outcome !== null && in_array($outcome, CallOutcome::connectedValues(), true),
            self::Meeting, self::Visit => $outcome === 'held',
            self::Whatsapp, self::Email => false,
        };
    }

    /** @return list<array{value: string, label: string, outcomes: list<array{value: string, label: string}>}> */
    public static function options(): array
    {
        return array_map(static fn (self $type): array => [
            'value' => $type->value,
            'label' => $type->label(),
            'outcomes' => array_map(
                static fn (string $value, string $label): array => ['value' => $value, 'label' => $label],
                array_keys($type->outcomes()),
                array_values($type->outcomes()),
            ),
        ], self::cases());
    }
}
