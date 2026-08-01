<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get all users for the company.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Get all AI chats for the company.
     */
    public function aiChats(): HasMany
    {
        return $this->hasMany(AiChat::class);
    }

    /**
     * Get all uploaded files for the company.
     */
    public function uploadedFiles(): HasMany
    {
        return $this->hasMany(UploadedFile::class);
    }

    /**
     * Get all extracted data for the company.
     */
    public function extractedData(): HasMany
    {
        return $this->hasMany(DataSourceExtraction::class);
    }

    /**
     * Get all dashboards for the company.
     */
    public function dashboards(): HasMany
    {
        return $this->hasMany(Dashboard::class);
    }
}
