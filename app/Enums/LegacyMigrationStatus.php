<?php

namespace App\Enums;

enum LegacyMigrationStatus: string
{
    /** Parsed, mapped and validated; every domain write was rolled back. */
    case DryRun = 'dry_run';

    /** Apply mode in progress. */
    case Running = 'running';

    /** Apply mode finished; groups may still have recorded errors. */
    case Completed = 'completed';

    /** The run itself could not proceed (unreadable source, bad mapping). */
    case Failed = 'failed';
}
