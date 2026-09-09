<?php

namespace Tests\Support;

use App\Services\MonthlyCycles\MonthlyCycleMutationGuard;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Records every cycle row lock the application takes (ids, order and the
 * transaction level at that moment) and can run a callback just before
 * the lock is acquired, to simulate another connection committing first.
 */
class LockSpy extends MonthlyCycleMutationGuard
{
    /**
     * @var list<array{ids: list<int>, transaction_level: int}>
     */
    public array $locks = [];

    /**
     * @var (callable(list<int>): void)|null
     */
    public $beforeLock = null;

    public function lockRows(array $ids): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        if ($this->beforeLock !== null) {
            ($this->beforeLock)($ids);
        }

        $this->locks[] = ['ids' => $ids, 'transaction_level' => DB::transactionLevel()];

        return parent::lockRows($ids);
    }

    public static function install(): self
    {
        $spy = new self;

        app()->instance(MonthlyCycleMutationGuard::class, $spy);

        return $spy;
    }

    /**
     * @return list<list<int>>
     */
    public function lockedIdSets(): array
    {
        return array_column($this->locks, 'ids');
    }
}
