<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class QueryCacheService
{
    public static function remember(string $key, int $ttl, callable $callback)
    {
        return Cache::remember($key, $ttl, $callback);
    }

    public static function userKey(string $prefix, int $userId, array $params = []): string
    {
        $paramString = $params ? '_' . md5(serialize($params)) : '';
        return "{$prefix}_user_{$userId}{$paramString}";
    }

    public static function teamKey(string $prefix, int $teamId, array $params = []): string
    {
        $paramString = $params ? '_' . md5(serialize($params)) : '';
        return "{$prefix}_team_{$teamId}{$paramString}";
    }

    public static function boardKey(string $prefix, int $boardId, array $params = []): string
    {
        $paramString = $params ? '_' . md5(serialize($params)) : '';
        return "{$prefix}_board_{$boardId}{$paramString}";
    }

    public static function invalidateUserCache(int $userId, array $prefixes = []): void
    {
        $defaultPrefixes = ['boards', 'teams', 'tasks', 'notifications'];
        $allPrefixes = array_merge($defaultPrefixes, $prefixes);
        
        foreach ($allPrefixes as $prefix) {
            Cache::forget(self::userKey($prefix, $userId));
        }
    }

    public static function invalidateTeamCache(int $teamId, array $prefixes = []): void
    {
        $defaultPrefixes = ['team_members', 'team_boards', 'team_tasks'];
        $allPrefixes = array_merge($defaultPrefixes, $prefixes);
        
        foreach ($allPrefixes as $prefix) {
            Cache::forget(self::teamKey($prefix, $teamId));
        }
    }

    public static function invalidateBoardCache(int $boardId, array $prefixes = []): void
    {
        $defaultPrefixes = ['board_tasks', 'board_columns'];
        $allPrefixes = array_merge($defaultPrefixes, $prefixes);
        
        foreach ($allPrefixes as $prefix) {
            Cache::forget(self::boardKey($prefix, $boardId));
        }
    }
}
