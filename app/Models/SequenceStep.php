<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SequenceStep extends Model
{
    protected $fillable = [
        'sequence_id',
        'step_order',
        'message',
        'delay_hours',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function sequence()
    {
        return $this->belongsTo(Sequence::class);
    }

    public function sequenceUsers()
    {
        return $this->hasMany(SequenceUser::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('step_order');
    }
}
