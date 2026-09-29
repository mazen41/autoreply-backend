<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotKnowledgeAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'bot_id',
        'business_knowledge_file_id',
        'channel_id',
    ];

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class, 'bot_id');
    }

    public function knowledgeFile(): BelongsTo
    {
        return $this->belongsTo(BusinessKnowledgeFile::class, 'business_knowledge_file_id');
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'channel_id');
    }
}
