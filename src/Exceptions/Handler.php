<?php

namespace LaravelEnso\Sentry\Exceptions;

use Carbon\Carbon;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RedisException;
use Sentry\Laravel\Integration;
use Throwable;

class Handler
{
    private const UserEventKey = 'sentry-events';
    private const RecentEventsPrefix = 'recent-exceptions:';

    public static function report(Throwable $exception): void
    {
        if (self::shouldSkip($exception)) {
            return;
        }

        if ($user = self::user()) {
            self::addContext($user);
        }

        Integration::captureUnhandledException($exception);

        if ($user && App::isProduction()) {
            self::storeEventId($user);
        }
    }

    public static function eventId(): ?string
    {
        $events = Cache::get(self::UserEventKey, []);
        $eventId = $events[self::user()?->id] ?? null;

        return $eventId;
    }

    private static function storeEventId(Authenticatable $user): void
    {
        $events = Cache::get(self::UserEventKey, []);
        $events[$user->id] = App::make('sentry')->getLastEventId();

        Cache::forever(self::UserEventKey, $events);
    }

    private static function addContext(Authenticatable $user): void
    {
        App::make('sentry')->configureScope(fn ($scope) => $scope->setUser([
            'id' => $user->id,
            'username' => $user->person->name,
            'email' => $user->email,
        ])->setExtra('role', $user->role->name));
    }

    private static function user(): ?Authenticatable
    {
        foreach (array_unique(array_filter([
            Config::get('auth.defaults.guard'),
            'web',
            'sanctum',
        ])) as $guard) {
            try {
                if ($user = Auth::guard($guard)->user()) {
                    return $user;
                }
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        return null;
    }

    private static function shouldSkip(Throwable $exception): bool
    {
        $key = Str::of($exception::class)->snake()->slug()
            ->prepend(self::RecentEventsPrefix)
            ->append(':', Str::of($exception->getMessage())->snake()->slug())
            ->__toString();

        $store = $exception instanceof RedisException ? 'file' : null;

        $cache = Cache::store($store);

        if ($cache->has($key)) {
            return true;
        }

        $interval = Config::get('enso.sentry.dedupeInterval');
        $cache->put($key, true, Carbon::now()->addMinutes($interval));

        return false;
    }
}
