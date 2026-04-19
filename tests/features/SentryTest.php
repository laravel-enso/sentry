<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use LaravelEnso\Sentry\Exceptions\Handler;
use LaravelEnso\Users\Models\User;
use Mockery as m;
use PHPUnit\Framework\Attributes\Test;
use Sentry\Laravel\Integration;
use Tests\TestCase;

class SentryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['env'] = 'testing';

        $this->seed();
        $this->user = User::first();
        $this->user->is_active = true;
        $this->user->save();

        Cache::flush();
        Config::set('enso.sentry.dedupeInterval', 5);
    }

    protected function tearDown(): void
    {
        m::close();

        parent::tearDown();
    }

    #[Test]
    public function reports_exception_and_stores_event_id_for_authenticated_user_in_production(): void
    {
        $this->actingAs($this->user);
        $this->app['env'] = 'production';
        $this->bindSentry('event-1');

        Handler::report(new RuntimeException('boom'));

        $this->assertSame('event-1', Handler::eventId());
    }

    #[Test]
    public function does_not_store_event_id_outside_production(): void
    {
        $this->actingAs($this->user);
        $sentry = m::mock();
        $sentry->shouldReceive('configureScope')->once()->andReturnNull();
        $this->app->instance('sentry', $sentry);

        Handler::report(new RuntimeException('boom'));

        $this->assertNull(Handler::eventId());
    }

    #[Test]
    public function adds_user_and_role_context_to_sentry_scope(): void
    {
        $this->actingAs($this->user);

        $scope = new class()
        {
            public array $user = [];
            public array $extra = [];

            public function setUser(array $user): self
            {
                $this->user = $user;

                return $this;
            }

            public function setExtra(string $key, mixed $value): self
            {
                $this->extra[$key] = $value;

                return $this;
            }
        };

        $sentry = m::mock();
        $sentry->shouldReceive('configureScope')
            ->once()
            ->with(m::on(function ($closure) use ($scope) {
                $closure($scope);

                return true;
            }));
        $sentry->shouldReceive('getLastEventId')->once()->andReturn('event-1');

        $this->app['env'] = 'production';
        $this->app->instance('sentry', $sentry);

        Handler::report(new RuntimeException('context'));

        $this->assertSame([
            'id' => $this->user->id,
            'username' => $this->user->person->name,
            'email' => $this->user->email,
        ], $scope->user);
        $this->assertSame($this->user->role->name, $scope->extra['role']);
    }

    #[Test]
    public function returns_cached_event_id_for_current_user(): void
    {
        $this->actingAs($this->user);

        Cache::forever('sentry-events', [$this->user->id => 'event-123']);

        $this->assertSame('event-123', Handler::eventId());
    }

    #[Test]
    public function returns_null_event_id_when_no_event_is_cached(): void
    {
        $this->actingAs($this->user);

        $this->assertNull(Handler::eventId());
    }

    #[Test]
    public function deduplicates_repeated_exceptions_within_interval(): void
    {
        $this->actingAs($this->user);
        $this->app['env'] = 'production';
        $this->bindSentry('event-1', 1);

        Handler::report(new RuntimeException('same-error'));
        Handler::report(new RuntimeException('same-error'));

        $this->assertSame('event-1', Handler::eventId());
    }

    #[Test]
    public function uses_file_cache_store_for_redis_exceptions(): void
    {
        if (! class_exists('RedisException')) {
            $this->markTestSkipped('Redis extension is not installed.');
        }

        $store = m::mock();
        $store->shouldReceive('has')->once()->andReturnFalse();
        $store->shouldReceive('put')->once()->andReturnTrue();

        Cache::shouldReceive('store')->once()->with('file')->andReturn($store);

        Handler::report(new \RedisException('redis unavailable'));

        $this->assertTrue(true);
    }

    #[Test]
    public function resolves_user_from_web_guard_when_default_guard_is_invalid(): void
    {
        Config::set('auth.defaults.guard', 'invalid');
        $this->actingAs($this->user, 'web');
        $this->app['env'] = 'production';
        $this->bindSentry('event-web');

        Handler::report(new RuntimeException('web-user'));

        $this->assertSame('event-web', Handler::eventId());
    }

    #[Test]
    public function resolves_user_from_sanctum_guard(): void
    {
        Config::set('auth.defaults.guard', 'sanctum');
        Sanctum::actingAs($this->user);
        $this->app['env'] = 'production';
        $this->bindSentry('event-sanctum');

        Handler::report(new RuntimeException('sanctum-user'));

        $this->assertSame('event-sanctum', Handler::eventId());
    }

    #[Test]
    public function sentry_endpoint_returns_event_id(): void
    {
        Cache::forever('sentry-events', [$this->user->id => 'endpoint-event']);

        $this->actingAs($this->user)
            ->get(route('sentry'))
            ->assertOk()
            ->assertJson(['eventId' => 'endpoint-event']);
    }

    #[Test]
    public function sentry_endpoint_requires_authentication(): void
    {
        $this->get(route('sentry'))
            ->assertRedirect(route('login'));
    }

    private function bindSentry(string $eventId, int $times = 1): void
    {
        $sentry = m::mock();
        $sentry->shouldReceive('configureScope')->times($times)->andReturnNull();
        $sentry->shouldReceive('getLastEventId')->times($times)->andReturn($eventId);

        $this->app->instance('sentry', $sentry);
    }
}
