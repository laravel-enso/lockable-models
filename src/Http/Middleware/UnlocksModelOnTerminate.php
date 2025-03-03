<?php

namespace LaravelEnso\LockableModels\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use LaravelEnso\LockableModels\Models\LockableModel;
use Symfony\Component\HttpFoundation\Response;

class UnlocksModelOnTerminate
{
    public function handle(Request $request, Closure $next)
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if ($response->getStatusCode() !== 200) {
            return;
        }

        Collection::wrap($request->route()->parameters())
            ->filter(fn ($param) => $param instanceof LockableModel)
            ->each
            ->unlockFor($request->user());
    }
}
