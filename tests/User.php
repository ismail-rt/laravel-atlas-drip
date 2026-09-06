<?php

namespace Tests;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Lad\Contracts\RecipientInterface;

class User extends Authenticatable implements RecipientInterface
{
    use Notifiable;

    protected $table = 'users';

    protected $guarded = [];

    protected $attributes = [
        'password' => '$2y$12$e8g3m90F1/t...',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'marketing_emails_opted_out_at' => 'datetime',
            'is_admin' => 'boolean',
        ];
    }

    public function properties(): HasMany
    {
        return $this->hasMany(Property::class, 'user_id');
    }

    public function isAdmin(): bool
    {
        return (bool) $this->is_admin;
    }

    public function hasOptedOutOfLifecycle(): bool
    {
        return $this->marketing_emails_opted_out_at !== null;
    }

    public function hasOptedOutOfDrip(): bool
    {
        return $this->hasOptedOutOfLifecycle();
    }
}
