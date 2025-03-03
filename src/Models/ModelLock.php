<?php

namespace LaravelEnso\LockableModels\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LaravelEnso\Users\Models\User;

abstract class ModelLock extends Model
{
    public function expired(): bool
    {
        return $this->expires_at->isBefore(Carbon::now());
    }

    public function allowed(User $user): bool
    {
        return $this->expired() || $this->user->is($user);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeIsExpired(Builder $query): Builder
    {
        return $query->where('expires_at', '<', Carbon::now());
    }
}
