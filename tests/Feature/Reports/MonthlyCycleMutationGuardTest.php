<?php

namespace Tests\Feature\Reports;

use App\Services\MonthlyCycles\MonthlyCycleMutationGuard;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/**
 * Deliberately without RefreshDatabase: the guard must refuse to take a
 * row lock when no transaction is open, because such a lock would be
 * released immediately and protect nothing.
 */
class MonthlyCycleMutationGuardTest extends TestCase
{
    public function test_the_guard_refuses_to_lock_outside_a_transaction(): void
    {
        $this->assertSame(0, DB::transactionLevel());

        try {
            app(MonthlyCycleMutationGuard::class)->lockForWrite(1, 'test');
            $this->fail('Expected a LogicException outside a transaction.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('inside a database transaction', $exception->getMessage());
        }

        try {
            app(MonthlyCycleMutationGuard::class)->lockForMove(1, 2, 'a', 'b');
            $this->fail('Expected a LogicException outside a transaction.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        // Nothing to lock (project-level) never touches the database.
        $this->assertNull(app(MonthlyCycleMutationGuard::class)->lockForWrite(null, 'test'));
    }
}
