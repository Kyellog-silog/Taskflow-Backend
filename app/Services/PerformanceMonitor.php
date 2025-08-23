<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class PerformanceMonitor
{
    private static bool $enabled = false;
    private static array $timers = [];

    /**
     * Enable performance monitoring
     */
    public static function enable(): void
    {
        self::$enabled = true;
    }

    /**
     * Disable performance monitoring
     */
    public static function disable(): void
    {
        self::$enabled = false;
    }

    /**
     * Start timing an operation
     */
    public static function startTimer(string $name, array $context = []): void
    {
        if (!self::$enabled) return;

        try {
            self::$timers[$name] = [
                'start' => microtime(true),
                'context' => $context,
                'queries_start' => self::getQueryCount(),
            ];
        } catch (\Exception $e) {
            Log::error('Error starting timer', [
                'timer' => $name,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * End timing an operation and log results
     */
    public static function endTimer(string $name, array $additionalContext = []): float
    {
        if (!self::$enabled || !isset(self::$timers[$name])) {
            return 0.0;
        }

        try {
            $timer = self::$timers[$name];
            $duration = microtime(true) - $timer['start'];
            $queriesExecuted = self::getQueryCount() - $timer['queries_start'];

            $logData = array_merge($timer['context'], $additionalContext, [
                'operation' => $name,
                'duration_ms' => round($duration * 1000, 2),
                'queries_executed' => $queriesExecuted,
                'memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            ]);

            // Log performance data
            if ($duration > 1) {
                Log::warning('Slow operation detected', $logData);
            } elseif ($duration > 0.5) {
                Log::info('Performance warning', $logData);
            } else {
                Log::debug('Performance timing', $logData);
            }

            unset(self::$timers[$name]);
            return $duration;
        } catch (\Exception $e) {
            Log::error('Error ending timer', [
                'timer' => $name,
                'error' => $e->getMessage()
            ]);
            return 0.0;
        }
    }

    /**
     * Measure a closure execution time
     */
    public static function measure(string $name, callable $callback, array $context = [])
    {
        self::startTimer($name, $context);
        try {
            $result = $callback();
            self::endTimer($name, ['success' => true]);
            return $result;
        } catch (\Throwable $e) {
            self::endTimer($name, [
                'success' => false,
                'error' => $e->getMessage(),
                'error_class' => get_class($e)
            ]);
            throw $e;
        }
    }

    /**
     * Get current query count
     */
    private static function getQueryCount(): int
    {
        try {
            return count(\DB::getQueryLog());
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Enable query logging for this request
     */
    public static function enableQueryLogging(): void
    {
        if (!self::$enabled) return;
        
        try {
            \DB::enableQueryLog();
        } catch (\Exception $e) {
            Log::error('Error enabling query logging', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Log query statistics
     */
    public static function logQueryStats(string $operation): void
    {
        if (!self::$enabled) return;

        try {
            $queries = \DB::getQueryLog();
            if (empty($queries)) return;

            $totalTime = 0;
            $slowQueries = [];
            
            foreach ($queries as $query) {
                $time = $query['time'] ?? 0;
                $totalTime += $time;
                
                if ($time > 100) { // Queries slower than 100ms
                    $slowQueries[] = [
                        'sql' => $query['query'] ?? $query['sql'] ?? 'Unknown query',
                        'time_ms' => $time,
                        'bindings' => $query['bindings'] ?? []
                    ];
                }
            }

            $logData = [
                'operation' => $operation,
                'total_queries' => count($queries),
                'total_query_time_ms' => round($totalTime, 2),
                'avg_query_time_ms' => count($queries) > 0 ? round($totalTime / count($queries), 2) : 0,
                'slow_queries_count' => count($slowQueries),
            ];

            if (!empty($slowQueries)) {
                $logData['slow_queries'] = $slowQueries;
                Log::warning('Slow queries detected', $logData);
            } else {
                Log::info('Query performance stats', $logData);
            }

            // Clear query log for next operation
            \DB::flushQueryLog();
        } catch (\Exception $e) {
            Log::error('Error logging query stats', [
                'operation' => $operation,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get current memory usage
     */
    public static function getMemoryUsage(): array
    {
        return [
            'current_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
        ];
    }

    /**
     * Log request summary
     */
    public static function logRequestSummary(string $endpoint, float $totalTime, int $responseSize = 0): void
    {
        if (!self::$enabled) return;

        try {
            $memory = self::getMemoryUsage();
            
            Log::info('Request performance summary', [
                'endpoint' => $endpoint,
                'total_time_ms' => round($totalTime * 1000, 2),
                'response_size_kb' => round($responseSize / 1024, 2),
                'memory_current_mb' => $memory['current_mb'],
                'memory_peak_mb' => $memory['peak_mb'],
            ]);
        } catch (\Exception $e) {
            Log::error('Error logging request summary', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage()
            ]);
        }
    }
}
