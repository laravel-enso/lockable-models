<?php

require_once __DIR__.'/../../src/Models/ModelLock.php';
require_once __DIR__.'/../../src/Models/LockableModel.php';
require_once __DIR__.'/../../src/Exceptions/ModelLockException.php';
require_once __DIR__.'/../../src/Http/Middleware/PreventActionOnLockedModels.php';
require_once __DIR__.'/../../src/Http/Middleware/UnlocksModelOnTerminate.php';

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use LaravelEnso\LockableModels\Exceptions\ModelLockException;
use LaravelEnso\LockableModels\Http\Middleware\PreventActionOnLockedModels;
use LaravelEnso\LockableModels\Http\Middleware\UnlocksModelOnTerminate;
use LaravelEnso\LockableModels\Models\LockableModel;
use LaravelEnso\LockableModels\Models\ModelLock;
use LaravelEnso\Users\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;
use Illuminate\Routing\Middleware\SubstituteBindings;

class LockableModelsTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();

        Config::set('enso.lockableModels.lock_duration', 5);

        $this->seed();
        $this->createTestTables();
        $this->registerTestRoutes();
    }

    #[Test]
    public function lockable_model_creates_lock_for_current_user(): void
    {
        $model = LockableTestModel::create();
        $user = User::first();

        $this->actingAs($user)
            ->get(route('lockable.prevent', $model))
            ->assertOk();

        $this->assertDatabaseHas('lockable_test_model_locks', [
            'lockable_test_model_id' => $model->id,
            'user_id' => $user->id,
        ]);
    }

    #[Test]
    public function same_user_can_reenter_existing_lock_without_error(): void
    {
        $model = LockableTestModel::create();
        $user = User::first();

        $this->actingAs($user)->get(route('lockable.prevent', $model))->assertOk();
        $firstExpiry = $model->lock()->first()->expires_at;

        Carbon::setTestNow(Carbon::parse($firstExpiry)->addMinute());

        $this->actingAs($user)->get(route('lockable.prevent', $model))->assertOk();

        $model->refresh();
        $lock = $model->lock;

        $this->assertSame($user->id, $lock->user_id);
        $this->assertCount(1, LockableTestModelLock::all());
        $this->assertTrue($lock->expires_at->isAfter($firstExpiry));

        Carbon::setTestNow();
    }

    #[Test]
    public function different_user_cannot_act_on_non_expired_lock(): void
    {
        $model = LockableTestModel::create();
        [$firstUser, $secondUser] = User::query()->take(2)->get();

        $model->lock()->create([
            'user_id' => $firstUser->id,
            'expires_at' => now()->addMinute(),
        ]);

        $this->withoutExceptionHandling();
        $this->expectException(ModelLockException::class);
        $this->expectExceptionMessage("Locked by: {$firstUser->appellative()}");

        $this->actingAs($secondUser)->get(route('lockable.prevent', $model));
    }

    #[Test]
    public function expired_lock_allows_other_user(): void
    {
        $model = LockableTestModel::create();
        [$firstUser, $secondUser] = User::query()->take(2)->get();

        $model->lock()->create([
            'user_id' => $firstUser->id,
            'expires_at' => now()->subMinute(),
        ]);

        $this->actingAs($secondUser)
            ->get(route('lockable.prevent', $model))
            ->assertOk();

        $model->refresh();

        $this->assertSame($secondUser->id, $model->lock->user_id);
        $this->assertTrue($model->lock->expires_at->isAfter(now()));
    }

    #[Test]
    public function unlock_for_removes_lock_of_current_user(): void
    {
        $model = LockableTestModel::create();
        $user = User::first();

        $model->lock()->create([
            'user_id' => $user->id,
            'expires_at' => now()->addMinute(),
        ]);

        $model->unlockFor($user);

        $this->assertNull($model->fresh()->lock);
    }

    #[Test]
    public function unlock_for_is_safe_when_user_has_no_lock(): void
    {
        $model = LockableTestModel::create();
        [$firstUser, $secondUser] = User::query()->take(2)->get();

        $model->lock()->create([
            'user_id' => $firstUser->id,
            'expires_at' => now()->addMinute(),
        ]);

        $model->unlockFor($secondUser);

        $this->assertDatabaseHas('lockable_test_model_locks', [
            'lockable_test_model_id' => $model->id,
            'user_id' => $firstUser->id,
        ]);
    }

    #[Test]
    public function terminate_unlocks_only_on_200_response(): void
    {
        $model = LockableTestModel::create();
        $user = User::first();

        $this->actingAs($user)
            ->get(route('lockable.full', $model))
            ->assertOk();

        $this->assertDatabaseMissing('lockable_test_model_locks', [
            'lockable_test_model_id' => $model->id,
            'user_id' => $user->id,
        ]);
    }

    #[Test]
    public function terminate_keeps_lock_for_non_200_response(): void
    {
        $model = LockableTestModel::create();
        $user = User::first();

        $this->actingAs($user)
            ->get(route('lockable.fail', $model))
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->assertDatabaseHas('lockable_test_model_locks', [
            'lockable_test_model_id' => $model->id,
            'user_id' => $user->id,
        ]);
    }

    #[Test]
    public function model_lock_allowed_returns_true_for_owner(): void
    {
        $user = User::first();
        $lock = LockableTestModelLock::create([
            'lockable_test_model_id' => LockableTestModel::create()->id,
            'user_id' => $user->id,
            'expires_at' => now()->addMinute(),
        ]);

        $this->assertTrue($lock->allowed($user));
    }

    #[Test]
    public function model_lock_allowed_returns_true_for_expired_lock(): void
    {
        [$firstUser, $secondUser] = User::query()->take(2)->get();

        $lock = LockableTestModelLock::create([
            'lockable_test_model_id' => LockableTestModel::create()->id,
            'user_id' => $firstUser->id,
            'expires_at' => now()->subMinute(),
        ]);

        $this->assertTrue($lock->allowed($secondUser));
    }

    #[Test]
    public function model_lock_scope_is_expired_filters_expired_records(): void
    {
        $model = LockableTestModel::create();
        $user = User::first();

        LockableTestModelLock::create([
            'lockable_test_model_id' => $model->id,
            'user_id' => $user->id,
            'expires_at' => now()->subMinute(),
        ]);

        LockableTestModelLock::create([
            'lockable_test_model_id' => LockableTestModel::create()->id,
            'user_id' => $user->id,
            'expires_at' => now()->addMinute(),
        ]);

        $this->assertCount(1, LockableTestModelLock::isExpired()->get());
    }

    #[Test]
    public function lock_duration_comes_from_config(): void
    {
        Config::set('enso.lockableModels.lock_duration', 17);

        $this->assertSame(17, LockableTestModel::create()->lockForMinutes());
    }

    #[Test]
    public function locked_exception_contains_person_name(): void
    {
        $user = User::first();
        $lock = LockableTestModelLock::create([
            'lockable_test_model_id' => LockableTestModel::create()->id,
            'user_id' => $user->id,
            'expires_at' => now()->addMinute(),
        ]);

        $this->assertSame(
            "Locked by: {$user->appellative()}",
            ModelLockException::locked($lock)->getMessage()
        );
    }

    private function createTestTables(): void
    {
        Schema::create('lockable_test_models', function ($table) {
            $table->increments('id');
            $table->timestamps();
        });

        Schema::create('lockable_test_model_locks', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('lockable_test_model_id');
            $table->unsignedInteger('user_id');
            $table->dateTime('expires_at');
            $table->timestamps();
        });
    }

    private function registerTestRoutes(): void
    {
        Route::middleware([SubstituteBindings::class, PreventActionOnLockedModels::class])
            ->get('/test-lockables/prevent/{lockableTestModel}', fn (LockableTestModel $lockableTestModel) => response()->json([
                'id' => $lockableTestModel->id,
            ]))
            ->name('lockable.prevent');

        Route::middleware([SubstituteBindings::class, PreventActionOnLockedModels::class, UnlocksModelOnTerminate::class])
            ->get('/test-lockables/full/{lockableTestModel}', fn (LockableTestModel $lockableTestModel) => response()->json([
                'id' => $lockableTestModel->id,
            ]))
            ->name('lockable.full');

        Route::middleware([SubstituteBindings::class, PreventActionOnLockedModels::class, UnlocksModelOnTerminate::class])
            ->get('/test-lockables/fail/{lockableTestModel}', fn (LockableTestModel $lockableTestModel) => response()->json([
                'id' => $lockableTestModel->id,
            ], Response::HTTP_UNPROCESSABLE_ENTITY))
            ->name('lockable.fail');

        app('router')->getRoutes()->refreshNameLookups();
        app('router')->getRoutes()->refreshActionLookups();
    }
}

class LockableTestModel extends LockableModel
{
    protected $table = 'lockable_test_models';
}

class LockableTestModelLock extends ModelLock
{
    protected $table = 'lockable_test_model_locks';
}
