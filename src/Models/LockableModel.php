<?php

namespace LaravelEnso\LockableModels\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use LaravelEnso\Users\Models\User;

abstract class LockableModel extends Model
{
    protected $guarded = [];

    public function lock(): Relation
    {
        $self = static::class;
        $relation = "{$self}Lock";

        return $this->hasOne($relation);
    }

    public function lockFor(User $user): void
    {
        $existing = DB::table($this->lock()->getRelated()->getTable())
            ->where($this->lock()->getForeignKeyName(), $this->id)
            ->lockForUpdate()
            ->first();

        if ($existing) {
            return;
        }

        $this->lock()->updateOrCreate(['user_id' => $user->id], [
            'expires_at' => Carbon::now()->addMinutes($this->lockForMinutes()),
        ]);
    }

    public function unlockFor(User $user): void
    {
        $this->lock()->whereUserId($user->id)->first()->delete();
    }

    public function lockForMinutes(): int
    {
        return Config::get('enso.lockableModels.lock_duration');
    }
}
