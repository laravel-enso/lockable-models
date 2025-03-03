<?php

namespace LaravelEnso\LockableModels\Exceptions;

use LaravelEnso\Helpers\Exceptions\EnsoException;
use LaravelEnso\LockableModels\Models\ModelLock;

class ModelLockException extends EnsoException
{
    public static function locked(ModelLock $lock)
    {
        return new self(__('Locked by: :user', ['user' => $lock->user->person->name]));
    }
}
