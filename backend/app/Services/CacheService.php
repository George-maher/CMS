<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class CacheService
{
    private const DEFAULT_TTL = 300;
    private const LONG_TTL = 3600;
    private const DAY_TTL = 86400;
    private const VERSION_TTL = 86400;

    private function versionKey(string $namespace, int $churchId): string
    {
        return "_cv:{$namespace}:{$churchId}";
    }

    private function getVersion(string $namespace, int $churchId): int
    {
        return (int) Cache::remember(
            $this->versionKey($namespace, $churchId),
            self::VERSION_TTL,
            fn() => 1,
        );
    }

    private function vKey(string $namespace, int $churchId, string $key): string
    {
        return "{$namespace}:{$churchId}:v{$this->getVersion($namespace, $churchId)}:{$key}";
    }

    private function invalidateNamespace(string $namespace, int $churchId): void
    {
        $version = $this->getVersion($namespace, $churchId) + 1;
        Cache::put($this->versionKey($namespace, $churchId), $version, self::VERSION_TTL);
    }

    public function rememberAttendanceToday(int $churchId, ?int $classYearId, callable $callback): mixed
    {
        $key = $this->vKey('attendance', $churchId, 'today:' . ($classYearId ?? 'all'));
        return Cache::remember($key, self::DEFAULT_TTL, $callback);
    }

    public function rememberAttendanceStats(int $churchId, int $userId, callable $callback): mixed
    {
        $key = $this->vKey('attendance', $churchId, "stats:{$userId}");
        return Cache::remember($key, self::DEFAULT_TTL, $callback);
    }

    public function rememberLeaderboard(int $churchId, ?int $classYearId, int $limit, callable $callback): mixed
    {
        $key = $this->vKey('points', $churchId, "leaderboard:" . ($classYearId ?? 'all') . ":{$limit}");
        return Cache::remember($key, self::LONG_TTL, $callback);
    }

    public function rememberPointsBalance(int $churchId, int $userId, callable $callback): mixed
    {
        $key = $this->vKey('points', $churchId, "balance:{$userId}");
        return Cache::remember($key, self::DEFAULT_TTL, $callback);
    }

    public function rememberDashboardStats(int $churchId, callable $callback): mixed
    {
        $key = $this->vKey('dashboard', $churchId, 'stats');
        return Cache::remember($key, self::LONG_TTL, $callback);
    }

    public function rememberActiveVerse(int $churchId, callable $callback): mixed
    {
        $key = $this->vKey('verse', $churchId, 'active');
        return Cache::remember($key, self::DAY_TTL, $callback);
    }

    public function rememberEventList(int $churchId, string $filterHash, callable $callback): mixed
    {
        $key = $this->vKey('events', $churchId, "list:{$filterHash}");
        return Cache::remember($key, self::LONG_TTL, $callback);
    }

    public function rememberContextSummary(int $churchId, ?string $dateFrom, ?string $dateTo, ?int $classYearId, callable $callback): mixed
    {
        $hash = md5(serialize([$dateFrom, $dateTo, $classYearId]));
        $key = $this->vKey('attendance', $churchId, "context:summary:{$hash}");
        return Cache::remember($key, self::DEFAULT_TTL, $callback);
    }

    public function invalidateAttendance(int $churchId): void
    {
        $this->invalidateNamespace('attendance', $churchId);
    }

    public function invalidatePoints(int $churchId): void
    {
        $this->invalidateNamespace('points', $churchId);
    }

    public function invalidateDashboard(int $churchId): void
    {
        $this->invalidateNamespace('dashboard', $churchId);
    }

    public function invalidateVerse(int $churchId): void
    {
        $this->invalidateNamespace('verse', $churchId);
    }

    public function invalidateEvents(int $churchId): void
    {
        $this->invalidateNamespace('events', $churchId);
    }

    public function invalidateAllChurch(int $churchId): void
    {
        foreach (['attendance', 'points', 'dashboard', 'verse', 'events'] as $ns) {
            $this->invalidateNamespace($ns, $churchId);
        }
    }

    public function flush(): void
    {
        Cache::flush();
    }
}
