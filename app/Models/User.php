<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasApiTokens, HasRoles;

    protected $fillable = [
        'name',
        'email',
        'password',
        'company_id',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Ограничивает выборку сотрудниками одной компании.
     * Используется везде, где company_admin работает со своей командой.
     */
    public function scopeOfCompany(Builder $query, ?int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function isCompanyAdmin(): bool
    {
        return $this->hasRole('company_admin');
    }

    /**
     * Пригодна ли учётная запись к работе: не отключён ни сам сотрудник,
     * ни его компания.
     *
     * То же самое проверяет middleware EnsureUserIsActive, но подписка на
     * канал вещания идёт мимо него — через /broadcasting/auth. Без этой
     * проверки отключённый сотрудник со старым токеном продолжал бы получать
     * события компании (см. routes/channels.php).
     */
    public function isUsable(): bool
    {
        return (bool) $this->is_active
            && ($this->company === null || $this->company->is_active);
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super_admin');
    }

    /**
     * Владелец компании — тот, кто её зарегистрировал.
     * Его нельзя удалить или лишить прав администратора.
     */
    public function isCompanyOwner(): bool
    {
        return $this->company !== null
            && $this->company->owner_id === $this->id;
    }

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
}
