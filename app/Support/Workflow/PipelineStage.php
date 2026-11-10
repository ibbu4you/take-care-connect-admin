<?php

namespace App\Support\Workflow;

use App\Services\DealStageRules;

/**
 * The sales pipeline — named for what has actually happened with the buyer.
 *
 *   new → contacted → meeting → proposal → negotiation → won
 *                                                     ↘ lost
 *
 * ── Why these names ────────────────────────────────────────────────────────
 * The first version used temperature words — cold, warm, qualified. Two
 * salespeople would put the same deal in different columns, because "warm" is
 * a feeling. Every stage here is a fact somebody can check: we have spoken to
 * them; we have met them; a proposal went out; they are negotiating it. So the
 * board means the same thing to whoever reads it, and the forecast built on it
 * is worth something.
 *
 * Moving into a stage needs the thing that stage claims has happened — see
 * {@see DealStageRules}.
 *
 * ── Why a deal can move backwards ──────────────────────────────────────────
 * Any open stage can be reached from any other. A negotiation that stalls
 * really is back to "contacted", and a board that refuses the drag is a board
 * people work around by closing the deal and opening a new one — which
 * destroys the history the board exists to keep. Won and lost are reached only
 * through a deliberate close, because they write the figures targets are
 * measured on.
 */
enum PipelineStage: string implements Stage
{
    case New = 'new';
    case Contacted = 'contacted';
    case Meeting = 'meeting';
    case Proposal = 'proposal';
    case Negotiation = 'negotiation';
    case Won = 'won';
    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Contacted => 'Contacted',
            self::Meeting => 'Meeting held',
            self::Proposal => 'Proposal sent',
            self::Negotiation => 'Negotiation',
            self::Won => 'Won',
            self::Lost => 'Lost',
        };
    }

    /** One line saying what being in this stage means — shown on the board. */
    public function description(): string
    {
        return match ($this) {
            self::New => 'Not spoken to yet.',
            self::Contacted => 'Spoken to; no meeting yet.',
            self::Meeting => 'Met them and understood what they need.',
            self::Proposal => 'A proposal has gone out.',
            self::Negotiation => 'Discussing price or terms of the proposal.',
            self::Won => 'They said yes.',
            self::Lost => 'They said no, or went quiet for good.',
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
     * Roughly how likely a deal at this stage is to be won — used to weight the
     * forecast. Deliberately coarse: it exists so a board full of new deals
     * does not forecast the same as a board full of negotiations.
     */
    public function defaultProbability(): int
    {
        return match ($this) {
            self::New => 5,
            self::Contacted => 15,
            self::Meeting => 35,
            self::Proposal => 60,
            self::Negotiation => 80,
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
        return [self::New, self::Contacted, self::Meeting, self::Proposal, self::Negotiation];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $stage): string => $stage->value, self::cases());
    }

    /**
     * @return list<array{value: string, label: string, description: string, probability: int}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $stage): array => [
                'value' => $stage->value,
                'label' => $stage->label(),
                'description' => $stage->description(),
                'probability' => $stage->defaultProbability(),
            ],
            self::cases(),
        );
    }
}
