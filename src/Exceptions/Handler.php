<?php

namespace LaravelEnso\Sentry\Exceptions;

use Carbon\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
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

        if (Auth::check()) {
            self::addContext();
        }

        Integration::captureUnhandledException($exception);

        if (Auth::check() && App::isProduction()) {
            self::storeEventId();
        }
    }

    public static function eventId(): ?string
    {
        $events = Cache::get(self::UserEventKey);
        $eventId = $events[Auth::user()->id] ?? null;

        return $eventId;
    }

    private static function storeEventId(): void
    {
        $events = Cache::get(self::UserEventKey, []);
        $events[Auth::user()->id] = App::make('sentry')->getLastEventId();

        Cache::forever(self::UserEventKey, $events);
    }

    private static function addContext(): void
    {
        App::make('sentry')->configureScope(fn ($scope) => $scope->setUser([
            'id' => Auth::user()->id,
            'username' => Auth::user()->person->name,
            'email' => Auth::user()->email,
        ])->setExtra('role', Auth::user()->role->name));
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
