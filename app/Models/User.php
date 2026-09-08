<?php

namespace App\Models;

use App\Enums\Permission;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
        'email_verified_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withTimestamps();
    }

    /**
     * Projects where this user is the primary SEO owner.
     *
     * @return HasMany<Project, $this>
     */
    public function primaryProjects(): HasMany
    {
        return $this->hasMany(Project::class, 'primary_seo_user_id');
    }

    /**
     * Projects where this user is an additional team member.
     *
     * @return BelongsToMany<Project, $this>
     */
    public function teamProjects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class)
            ->withPivot('project_role')
            ->withTimestamps();
    }

    /**
     * Every project this user may see, according to their role.
     *
     * @return Builder<Project>
     */
    public function accessibleProjects(): Builder
    {
        return Project::query()->accessibleBy($this);
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The user's single system role, if one has been assigned.
     */
    public function role(): ?UserRole
    {
        return $this->roles->first()?->key;
    }

    public function hasRole(UserRole $role): bool
    {
        return $this->role() === $role;
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(UserRole::SuperAdmin);
    }

    /**
     * Inactive users hold no permissions regardless of role.
     */
    public function hasPermission(Permission $permission): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return $this->role()?->hasPermission($permission) ?? false;
    }

    /**
     * Only active users with an assigned role may use the panel.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active && $this->role() !== null;
    }
}
