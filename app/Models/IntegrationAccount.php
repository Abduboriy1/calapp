<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationAccount extends Model
{
    protected $fillable = [
        'user_id','provider','provider_user_id','access_token','refresh_token','expires_at','scope','meta','revoked_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'meta'       => 'array',
        // Use Laravel's encrypted casts for tokens (Laravel 10+):
        'access_token'  => 'encrypted',
        'refresh_token' => 'encrypted',
    ];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    public function isActive(): bool {
        return is_null($this->revoked_at);
    }
}
