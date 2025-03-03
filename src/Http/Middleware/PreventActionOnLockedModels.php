<?php

namespace LaravelEnso\LockableModels\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use LaravelEnso\LockableModels\Exceptions\ModelLockException as Exception;
use LaravelEnso\LockableModels\Models\LockableModel;

class PreventActionOnLockedModels
{
    public function handle(Request $request, Closure $next)
    {
        $lockable = Collection::wrap($request->route()->parameters())
            ->filter(fn ($param) => $param instanceof LockableModel);

        $lock = $lockable->map->lock->filter()
            ->reject->allowed($request->user())
            ->first();

        if ($lock) {
            throw Exception::locked($lock);
        }

        $lockable->each->lockFor($request->user());

        return $next($request);
    }
}
