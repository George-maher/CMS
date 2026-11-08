<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class CacheService
{
    private const DEFAULT_TTL = 300;

    private const LONG_TTL = 3600;

    private const DAY_TTL = 86400;

    private const VERSION_TTL = 86400;

    private function versionKey(string $namespace, ?int $churchId): string
    {
        return "_cv:{$namespace}:{$churchId}";
    }

    private function getVersion(string $namespace, ?int $churchId): int
    {
        return (int) Cache::remember(
            $this->versionKey($namespace, $churchId),
            self::VERSION_TTL,
            fn () => 1,
        );
    }

    private function vKey(string $namespace, ?int $churchId, string $key): string
    {
        return "{$namespace}:{$churchId}:v{$this->getVersion($namespace, $churchId)}:{$key}";
    }

    private function invalidateNamespace(string $namespace, ?int $churchId): void
    {
        if ($churchId === null) {
            return;
        }
        $version = $this->getVersion($namespace, $churchId) + 1;
        Cache::put($this->versionKey($namespace, $churchId), $version, self::VERSION_TTL);
    }

    /**
     * @template T
     *
     * @param  int|string|null  $scopeKey  class id, or a scope discriminator
     *                                     (e.g. "classes:1,2") so a class-scoped
     *                                     result can never share the
     *                                     church-wide cache entry.
     * @param  \Closure(): T  $callback
     * @return T
     */
    public function rememberAttendanceToday(?int $churchId, int|string|null $scopeKey, \Closure $callback): mixed
    {
        if ($churchId === null) {
            return $callback();
        }
        $key = $this->vKey('attendance', $churchId, 'today:'.($scopeKey ?? 'all'));

        return Cache::remember($key, self::DEFAULT_TTL, $callback);
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    public function rememberAttendanceStats(?int $churchId, int $userId, \Closure $callback): mixed
    {
        if ($churchId === null) {
            return $callback();
        }
        $key = $this->vKey('attendance', $churchId, "stats:{$userId}");

        return Cache::remember($key, self::DEFAULT_TTL, $callback);
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    public function rememberLeaderboard(?int $churchId, int|string|null $classYearId, int $limit, \Closure $callback): mixed
    {
        if ($churchId === null) {
            return $callback();
        }
        $key = $this->vKey('points', $churchId, 'leaderboard:'.($classYearId ?? 'all').":{$limit}");

        return Cache::remember($key, self::LONG_TTL, $callback);
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    public function rememberPointsBalance(?int $churchId, int $userId, \Closure $callback): mixed
    {
        if ($churchId === null) {
            return $callback();
        }
        $key = $this->vKey('points', $churchId, "balance:{$userId}");

        return Cache::remember($key, self::DEFAULT_TTL, $callback);
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    /**
     * @param  string  $scopeKey  authorization scope discriminator
     *                            ("global", "church", "stage:5", "class:1,2",
     *                            ...). Dashboard aggregates are computed per
     *                            scope, so the cache entry must be per scope —
     *                            otherwise a stage admin's stage-scoped
     *                            totals would be served to the church admin
     *                            (or vice versa) from the shared key.
     *
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    public function rememberDashboardStats(?int $churchId, string $scopeKey, \Closure $callback): mixed
    {
        if ($churchId === null) {
            return $callback();
        }
        $key = $this->vKey('dashboard', $churchId, 'stats:'.$scopeKey);

        return Cache::remember($key, self::LONG_TTL, $callback);
    }

    /**
     * @param  string  $scopeKey  authorization scope discriminator (e.g.
     *                            "all", "stage:5") — stage-scoped results must
     *                            never share the church-wide cache entry.
     *
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    public function rememberStagesLeaderboards(?int $churchId, string $scopeKey, \Closure $callback): mixed
    {
        if ($churchId === null) {
            return $callback();
        }
        $key = $this->vKey('leaderboard', $churchId, 'stages:'.$scopeKey);

        return Cache::remember($key, self::LONG_TTL, $callback);
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    public function rememberActiveVerse(?int $churchId, \Closure $callback): mixed
    {
        if ($churchId === null) {
            return $callback();
        }
        $key = $this->vKey('verse', $churchId, 'active');

        return Cache::remember($key, self::DAY_TTL, $callback);
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    public function rememberEventList(?int $churchId, string $filterHash, \Closure $callback): mixed
    {
        if ($churchId === null) {
            return $callback();
        }
        $key = $this->vKey('events', $churchId, "list:{$filterHash}");

        return Cache::remember($key, self::LONG_TTL, $callback);
    }

    /**
     * @param  int|string|array<int>|null  $classScope  the resolved class scope
     *                                                  (null = church-wide, a
     *                                                  class id, or the full
     *                                                  id list) — it is part
     *                                                  of the key so a
     *                                                  scoped summary can
     *                                                  never be served from
     *                                                  the church-wide entry.
     *
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    public function rememberContextSummary(?int $churchId, ?string $dateFrom, ?string $dateTo, int|string|array|null $classScope, \Closure $callback): mixed
    {
        if ($churchId === null) {
            return $callback();
        }
        $hash = md5(serialize([$dateFrom, $dateTo, $classScope]));
        $key = $this->vKey('attendance', $churchId, "context:summary:{$hash}");

        return Cache::remember($key, self::DEFAULT_TTL, $callback);
    }

    public function invalidateAttendance(?int $churchId): void
    {
        $this->invalidateNamespace('attendance', $churchId);
    }

    public function invalidatePoints(?int $churchId): void
    {
        $this->invalidateNamespace('points', $churchId);
    }

    public function invalidateDashboard(?int $churchId): void
    {
        $this->invalidateNamespace('dashboard', $churchId);
    }

    public function invalidateStagesLeaderboards(?int $churchId): void
    {
        $this->invalidateNamespace('leaderboard', $churchId);
    }

    public function invalidateVerse(?int $churchId): void
    {
        $this->invalidateNamespace('verse', $churchId);
    }

    public function invalidateEvents(?int $churchId): void
    {
        $this->invalidateNamespace('events', $churchId);
    }

    public function invalidateAllChurch(?int $churchId): void
    {
        foreach (['attendance', 'points', 'dashboard', 'verse', 'events', 'leaderboard'] as $ns) {
            $this->invalidateNamespace($ns, $churchId);
        }
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    public function rememberUserAuth(int $userId, \Closure $callback): mixed
    {
        $key = "user_auth:{$userId}";

        return Cache::remember($key, self::DEFAULT_TTL, $callback);
    }

    public function invalidateUserAuth(int $userId): void
    {
        Cache::forget("user_auth:{$userId}");
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    public function rememberUnreadCount(int $userId, \Closure $callback): mixed
    {
        $key = "notifications:unread:{$userId}";

        return Cache::remember($key, 15, $callback);
    }

    public function invalidateUnreadCount(int $userId): void
    {
        Cache::forget("notifications:unread:{$userId}");
    }

    public function flush(): void
    {
        Cache::flush();
    }
}
