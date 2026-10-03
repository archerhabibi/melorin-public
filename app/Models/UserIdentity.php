<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * هویت بیرونی یک User (Google، ...). کلید: provider + provider_user_id.
 * Email فقط Snapshot اطلاعاتی است و هرگز کلید تطبیق نیست (GOOGLE-SIGNIN-CONTRACT §G13).
 */
class UserIdentity extends Model
{
    public const PROVIDER_GOOGLE = 'google';

    protected $fillable = ['user_id', 'provider', 'provider_user_id', 'provider_email', 'last_login_at'];

    protected $casts = ['last_login_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
