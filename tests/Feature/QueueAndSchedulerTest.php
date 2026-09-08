<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class QueueAndSchedulerTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_database_queue_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('jobs'));
        $this->assertTrue(Schema::hasTable('job_batches'));
        $this->assertTrue(Schema::hasTable('failed_jobs'));
    }

    public function test_jobs_can_be_pushed_onto_the_database_queue(): void
    {
        config(['queue.default' => 'database']);

        dispatch(function (): void {
            // Intentionally empty: we only verify the job was persisted.
        });

        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_the_scheduler_commands_are_operational(): void
    {
        $this->assertSame(0, Artisan::call('schedule:list'));
        $this->assertStringContainsString('No scheduled tasks have been defined', Artisan::output());

        $this->assertSame(0, Artisan::call('schedule:run'));
    }
}
