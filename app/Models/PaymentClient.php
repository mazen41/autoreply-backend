<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentClient extends Model
{
    protected $connection = 'central_payments';

    protected $fillable = [
        'public_id', 'slug', 'name', 'api_key_hash', 'hmac_secret',
        'notification_secret', 'backend_url', 'frontend_url', 'is_active', 'metadata',
    ];

    protected $hidden = ['api_key_hash', 'hmac_secret', 'notification_secret'];

    protected function casts(): array
    {
        return [
            'hmac_secret' => 'encrypted',
            'notification_secret' => 'encrypted',
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function payments(): HasMany
    {
        return $this->hasMany(CentralPayment::class);
    }
}
