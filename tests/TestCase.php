<?php

namespace Tests;

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    /**
     * Runs after the application is created but before any database
     * trait (RefreshDatabase, DatabaseMigrations, ...) touches the database.
     *
     * @return array<class-string, class-string>
     */
    protected function setUpTraits(): array
    {
        $this->ensureTestingDatabase();

        return parent::setUpTraits();
    }

    /**
     * Refuse to run against anything that is not explicitly a testing database.
     *
     * Destructive helpers such as RefreshDatabase wipe the configured database.
     * The name must end in "_testing" (or be an in-memory SQLite database) so a
     * misconfigured environment can never truncate development or production data.
     */
    protected function ensureTestingDatabase(): void
    {
        $connection = config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        if ($this->isTestingDatabaseName($database)) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Refusing to run tests against database "%s" on connection "%s". '.
            'The test database name must end in "_testing" (see phpunit.xml).',
            $database,
            $connection,
        ));
    }

    protected function isTestingDatabaseName(string $database): bool
    {
        return str_ends_with($database, '_testing') || $database === ':memory:';
    }
}
