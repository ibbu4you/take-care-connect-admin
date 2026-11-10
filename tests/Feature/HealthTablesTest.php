<?php

namespace Tests\Feature;

use App\Console\Commands\HealthCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use ReflectionClassConstant;
use Tests\TestCase;

/**
 * `tcc:health` decides "is this database ours?" from a list of the tables this
 * system creates. A migration that adds a table without adding it to that list
 * makes every deploy report a foreign table — which happened with lead_calls,
 * deal_activities and proposals. This keeps the list and the migrations in step.
 */
class HealthTablesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_table_the_migrations_create_is_known_to_the_health_check(): void
    {
        $owned = (new ReflectionClassConstant(HealthCommand::class, 'OWNED_TABLES'))->getValue();

        $created = collect(Schema::getTableListing())
            // SQLite's own bookkeeping, not ours.
            ->reject(fn (string $table) => str_starts_with($table, 'sqlite_'))
            ->map(fn (string $table) => str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table)
            ->values()
            ->all();

        $this->assertSame([], array_values(array_diff($created, $owned)), 'Add these tables to HealthCommand::OWNED_TABLES.');
    }
}
