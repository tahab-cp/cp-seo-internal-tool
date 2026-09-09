<?php

namespace App\Support\LegacyMigration;

/**
 * The four things a migration can decide for one source item.
 */
final class MigrationOutcome
{
    public const CREATE = 'create';

    public const UPDATE = 'update';

    public const SKIP = 'skip';

    public const CONFLICT = 'conflict';

    public const ALL = [self::CREATE, self::UPDATE, self::SKIP, self::CONFLICT];
}
