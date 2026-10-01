<?php

namespace App\Models;

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

    use HasFactory, Notifiable, HasApiTokens, HasRoles;

    protected $fillable = [
        'name',
        'email',
        'password',
        'company_id',
        'is_active',
        'ai_consent_at',
        'terms_accepted_at',
        'marketing_consent_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeOfCompany(Builder $query, ?int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function isCompanyAdmin(): bool
    {
        return $this->hasRole('company_admin');
    }

    public function isUsable(): bool
    {
        return (bool) $this->is_active
            && ($this->company === null || $this->company->is_active);
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super_admin');
    }

    public function isCompanyOwner(): bool
    {
        return $this->company !== null
            && $this->company->owner_id === $this->id;
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }
}
