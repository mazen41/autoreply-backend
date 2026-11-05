<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AiCreditTransaction extends Model
{
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['metadata' => 'array', 'amount' => 'integer', 'balance_before' => 'integer', 'balance_after' => 'integer', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('AI credit transactions are append-only; add a compensating transaction instead.'));
        static::deleting(fn () => throw new LogicException('AI credit transactions are append-only and cannot be deleted.'));
    }

    public function wallet() { return $this->belongsTo(AiCreditWallet::class, 'wallet_id'); }
    public function business() { return $this->belongsTo(BusinessProfile::class); }
}
