<?php

declare(strict_types=1);

namespace LskyPro\Support;

final class Queue
{
    public static function supportsActionScheduler(): bool
    {
        return \function_exists('as_enqueue_async_action') || \function_exists('as_schedule_single_action');
    }

    /**
     * 入队一个异步任务。
     *
     * - 若站点安装了 Action Scheduler，则优先使用（更可靠）。
     * - 否则 fallback 到 WP-Cron 的单次事件。
     *
     * @param array<int, mixed> $args
     */
    public static function enqueue(string $hook, array $args = [], int $delaySeconds = 0, string $group = 'lskypro'): bool
    {
        $delaySeconds = $delaySeconds < 0 ? 0 : $delaySeconds;
        $timestamp = \time() + $delaySeconds;

        if (\function_exists('as_enqueue_async_action')) {
            \as_enqueue_async_action($hook, $args, $group);
            return true;
        }

        if (\function_exists('as_schedule_single_action')) {
            \as_schedule_single_action($timestamp, $hook, $args, $group);
            return true;
        }

        if (!\function_exists('wp_schedule_single_event')) {
            return false;
        }

        if (\function_exists('wp_next_scheduled')) {
            $next = \wp_next_scheduled($hook, $args);
            if (\is_int($next) && $next > 0) {
                return true;
            }
        }

        return (bool) \wp_schedule_single_event($timestamp, $hook, $args);
    }
}

