<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportCaseComment extends Model
{
    protected $fillable = ['support_case_id', 'author_id', 'body'];

    public function supportCase(): BelongsTo { return $this->belongsTo(SupportCase::class); }
    public function author(): BelongsTo { return $this->belongsTo(User::class, 'author_id'); }
}
