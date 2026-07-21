<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasApiTokens;

    protected $fillable = [
        'name',
        'email',
        'password',
        'plan',
        'onboarding_completed',
        'is_admin',
        'google_id',
        'facebook_id',
        'avatar',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at'     => 'datetime',
            'password'              => 'hashed',
            'onboarding_completed'  => 'boolean',
            'is_admin'              => 'boolean',
        ];
    }

    public function subscription()
    {
        return $this->hasOne(Subscription::class)->active();
    }

    public function activeSubscription()
    {
        return $this->hasOne(Subscription::class)->where('status', 'active')
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now());
    }
    public function channels()
    {
        return $this->hasMany(Channel::class);
    }
}

