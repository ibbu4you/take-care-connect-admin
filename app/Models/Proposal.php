<?php

namespace App\Models;

use App\Support\Settings;
use App\Support\Workflow\ProposalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What the buyer is offered: line items, a discount, a total.
 *
 * @property ProposalStatus $status
 * @property list<array{description: string, quantity: float, rate: float, amount: float}> $items
 */
class Proposal extends Model
{
    protected $fillable = [
        'code', 'deal_id', 'version', 'status', 'title', 'items',
        'subtotal', 'discount_percent', 'discount_amount', 'total',
        'valid_until', 'terms', 'internal_note', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProposalStatus::class,
            'items' => 'array',
            'subtotal' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'valid_until' => 'date:Y-m-d',
            'approved_at' => 'datetime',
            'sent_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    /**
     * Recompute every amount from the line items and the discount.
     *
     * Always server-side: the browser shows a running total for comfort, but
     * the figure on the proposal is the one computed here, so a tampered or
     * rounded request cannot change what the buyer is quoted.
     *
     * @param  list<array{description: string, quantity: mixed, rate: mixed}>  $items
     */
    public function fillAmounts(array $items, float $discountPercent): void
    {
        $lines = array_values(array_map(static function (array $item): array {
            $quantity = round((float) $item['quantity'], 2);
            $rate = round((float) $item['rate'], 2);

            return [
                'description' => trim((string) $item['description']),
                'quantity' => $quantity,
                'rate' => $rate,
                'amount' => round($quantity * $rate, 2),
            ];
        }, $items));

        $subtotal = round(array_sum(array_column($lines, 'amount')), 2);
        $discount = round($subtotal * $discountPercent / 100, 2);

        $this->items = $lines;
        $this->subtotal = $subtotal;
        $this->discount_percent = round($discountPercent, 2);
        $this->discount_amount = $discount;
        $this->total = round($subtotal - $discount, 2);
    }

    /** Whether a Sales Manager has to see this before it goes out. */
    public function needsApproval(): bool
    {
        return (float) $this->discount_percent > Settings::int('sales.discount_approval_percent');
    }

    /** @return BelongsTo<Deal, $this> */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
