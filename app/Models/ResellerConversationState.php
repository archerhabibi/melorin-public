<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResellerConversationState extends Model
{
    protected $fillable = ['reseller_id', 'telegram_chat_id', 'user_id', 'step', 'payload'];

    protected $casts = [
        'telegram_chat_id' => 'integer',
        'payload' => 'array',
    ];

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
