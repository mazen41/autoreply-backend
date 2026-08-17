<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MessageFeedback extends Model
{
    protected $table = 'message_feedbacks';

    protected $fillable = [
        'message_id',
        'user_id',
        'feedback',
        'comment',
        'issue_type',
        'confidence_score',
        'detected_dialect',
    ];

    protected $casts = [
        'confidence_score' => 'float',
    ];

    public function message()
    {
        return $this->belongsTo(Message::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopePositive($query)
    {
        return $query->where('feedback', 'positive');
    }

    public function scopeNegative($query)
    {
        return $query->where('feedback', 'negative');
    }

    public function scopeByIssueType($query, $issueType)
    {
        return $query->where('issue_type', $issueType);
    }

    public function scopeByDialect($query, $dialect)
    {
        return $query->where('detected_dialect', $dialect);
    }
}