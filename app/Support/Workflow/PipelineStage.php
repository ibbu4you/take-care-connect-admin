<?php

namespace App\Support\Workflow;

/**
 * The sales pipeline.
 *
 *   intake → cold → warm → qualified → proposal → won
 *                                              ↘ lost
 *
 * ── Why a deal can move backwards ──────────────────────────────────────────
 * Unlike the interview, article and creative workflows, this one allows a card
 * to move to *any* open stage. That is not laxness: a deal that looked
 * qualified and then went quiet genuinely is cold again, and a board that
 * refuses to let somebody drag it back is a board they will work around by
 * closing it as lost and opening a new one — which destroys the history the
 * board exists to keep.
 *
 * Won and lost are the only real constraints: they are outcomes, they write
 * revenue, and they are reached through a deliberate close rather than a drag.
 */
enum PipelineStage: string implements Stage
{
    case Intake = 'intake';
    case Cold = 'cold';
    case Warm = 'warm';
    case Qualified = 'qualified';
    case Proposal = 'proposal';
    case Won = 'won';
    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::Intake => 'Intake',
            self::Cold => 'Cold',
            self::Warm => 'Warm',
            self::Qualified => 'Qualified',
            self::Proposal => 'Proposal',
            self::Won => 'Won',
            self::Lost => 'Lost',
        };
    }

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        // A closed deal stays closed. Reopening one would make "won this month"
        // a figure that can change after the month has been reported.
        if ($this->isClosed()) {
            return [];
        }

        // Any other open stage, plus the two outcomes.
        return array_values(array_filter(
            self::cases(),
            fn (self $stage): bool => $stage !== $this,
        ));
    }

    public function isTerminal(): bool
    {
        return $this->isClosed();
    }

    public function isClosed(): bool
    {
        return in_array($this, [self::Won, self::Lost], true);
    }

    /**
     * Roughly how likely this is to land, used for the weighted pipeline figure.
     *
     * Deliberately coarse. These are not forecasts — they exist so that a board
     * full of intake cards does not report the same pipeline value as a board
     * full of proposals, which is the only comparison anybody makes.
     */
    public function defaultProbability(): int
    {
        return match ($this) {
            self::Intake => 5,
            self::Cold => 10,
            self::Warm => 30,
            self::Qualified => 55,
            self::Proposal => 75,
            self::Won => 100,
            self::Lost => 0,
        };
    }

    /**
     * The board columns, in order. Won and lost are outcomes, not columns.
     *
     * @return list<self>
     */
    public static function boardColumns(): array
    {
        return [self::Intake, self::Cold, self::Warm, self::Qualified, self::Proposal];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $stage): string => $stage->value, self::cases());
    }

    /**
     * @return list<array{value: string, label: string, probability: int}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $stage): array => [
                'value' => $stage->value,
                'label' => $stage->label(),
                'probability' => $stage->defaultProbability(),
            ],
            self::cases(),
        );
    }
}
