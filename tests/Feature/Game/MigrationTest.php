<?php

namespace Tests\Feature\Game;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_FILE = '2026_06_11_191937_add_processing_day_to_games_status_enum.php';

    private function statusEnumDefinition(): string
    {
        return DB::selectOne("SHOW COLUMNS FROM games WHERE Field = 'status'")->Type;
    }

    /**
     * L'ENUM games.status doit contenir 'processing_day' (ajouté tâche E),
     * tout en conservant 'role_reveal' (préservé, voir DECISIONS.md).
     */
    public function test_processing_day_added_to_enum(): void
    {
        $definition = $this->statusEnumDefinition();

        $this->assertStringContainsString('processing_day', $definition);
        $this->assertStringContainsString('role_reveal', $definition);
    }

    /**
     * Le rollback de la migration doit retirer 'processing_day' de l'ENUM
     * sans toucher aux autres valeurs (notamment 'role_reveal').
     */
    public function test_rollback_of_processing_day_removes_it(): void
    {
        $migration = require database_path('migrations/'.self::MIGRATION_FILE);

        try {
            $migration->down();

            $definition = $this->statusEnumDefinition();
            $this->assertStringNotContainsString('processing_day', $definition);
            $this->assertStringContainsString('role_reveal', $definition);
        } finally {
            $migration->up();
        }

        $definition = $this->statusEnumDefinition();
        $this->assertStringContainsString('processing_day', $definition);
    }
}
