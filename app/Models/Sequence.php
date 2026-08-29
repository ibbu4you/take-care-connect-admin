<?php

namespace App\Models;

use App\Services\CodeGenerator;
use Illuminate\Database\Eloquent\Model;

/**
 * A counter behind a human-readable code.
 *
 * Written only through {@see CodeGenerator}, which takes a row
 * lock inside a transaction — two people creating an interview in the same
 * second must not both be handed INT-000123.
 */
class Sequence extends Model
{
    protected $fillable = ['key', 'next_value'];

    protected function casts(): array
    {
        return ['next_value' => 'integer'];
    }
}
