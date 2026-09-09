<?php

namespace App\Models;

use App\Enums\ImportStatus;
use App\Enums\ImportType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * History of one CSV import attempt. The stored file lives on a private
 * disk and is never exposed by path; the batch is the record that data
 * arrived via CSV for a project (and month).
 */
class ImportBatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'monthly_cycle_id',
        'import_type',
        'original_filename',
        'stored_file_path',
        'mapping_json',
        'status',
        'total_rows',
        'valid_rows',
        'imported_rows',
        'failed_rows',
        'created_by',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'import_type' => ImportType::class,
            'status' => ImportStatus::class,
            'mapping_json' => 'array',
            'total_rows' => 'integer',
            'valid_rows' => 'integer',
            'imported_rows' => 'integer',
            'failed_rows' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    public function monthlyCycle(): BelongsTo
    {
        return $this->belongsTo(MonthlyCycle::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function rowErrors(): HasMany
    {
        return $this->hasMany(ImportRowError::class)->orderBy('row_number')->orderBy('id');
    }

    /**
     * @return array<string, string> application field => CSV header
     */
    public function mapping(): array
    {
        return array_filter((array) ($this->mapping_json ?? []), fn ($header): bool => is_string($header) && $header !== '');
    }

    public function isCompleted(): bool
    {
        return $this->status === ImportStatus::Completed;
    }

    public function isValidated(): bool
    {
        return $this->status === ImportStatus::Validated;
    }

    public function scopeAccessibleBy(Builder $query, ?User $user): Builder
    {
        return $query->whereHas('project', fn (Builder $project) => $project->accessibleBy($user));
    }
}
