<?php

declare(strict_types=1);

namespace App\Core;

interface Job
{
    /** @param array<string,mixed> $payload */
    public function handle(array $payload): void;
}

/** In-process domain events: e-mail, webhooks and plugins hook in here. */
final class Events
{
    /** @var array<string,list<callable>> */
    private static array $listeners = [];

    public static function listen(string $event, callable $listener): void
    {
        self::$listeners[$event][] = $listener;
    }

    /** @param array<string,mixed> $payload */
    public static function dispatch(string $event, array $payload = []): void
    {
        foreach (self::$listeners[$event] ?? [] as $listener) {
            try {
                $listener($payload);
            } catch (\Throwable $e) {
                Logger::error('events.listener_failed', ['event' => $event, 'error' => $e->getMessage()]);
            }
        }
    }

    public static function reset(): void
    {
        self::$listeners = [];
    }
}

/**
 * Database backed queue. Claiming a job is a conditional UPDATE, so two workers
 * can never process the same row. Retries use exponential backoff; exhausted
 * jobs stay in the table (failed_at set) for inspection in the admin panel.
 */
final class Queue
{
    public const DEFAULT_QUEUE = 'default';

    /** @param array<string,mixed> $payload */
    public static function push(string $job, array $payload = [], int $delaySeconds = 0, string $queue = self::DEFAULT_QUEUE, int $maxAttempts = 5): int
    {
        return App::db()->insert('jobs', [
            'queue' => $queue,
            'job' => $job,
            'payload_json' => Json::encode($payload),
            'attempts' => 0,
            'max_attempts' => $maxAttempts,
            'available_at' => gmdate('Y-m-d H:i:s', time() + max(0, $delaySeconds)),
            'created_at' => Database::now(),
        ]);
    }

    /** @return array<string,mixed>|null */
    public static function reserve(string $queue = self::DEFAULT_QUEUE, ?string $worker = null): ?array
    {
        $db = App::db();
        $now = gmdate('Y-m-d H:i:s');
        $row = $db->selectOne(
            'SELECT * FROM jobs WHERE queue = :queue AND failed_at IS NULL AND reserved_at IS NULL AND available_at <= :now ORDER BY id ASC LIMIT 1',
            ['queue' => $queue, 'now' => $now]
        );
        if ($row === null) {
            return null;
        }
        $worker ??= gethostname() . ':' . getmypid();
        $claimed = $db->execute(
            'UPDATE jobs SET reserved_at = :now, reserved_by = :worker, attempts = attempts + 1 WHERE id = :id AND reserved_at IS NULL',
            ['now' => $now, 'worker' => Str::limit((string) $worker, 60), 'id' => (int) $row['id']]
        );
        if ($claimed === 0) {
            return null;
        }
        $row['attempts'] = (int) $row['attempts'] + 1;

        return $row;
    }

    public static function runNext(string $queue = self::DEFAULT_QUEUE): bool
    {
        $job = self::reserve($queue);
        if ($job === null) {
            return false;
        }
        self::execute($job);

        return true;
    }

    public static function work(string $queue = self::DEFAULT_QUEUE, int $maxJobs = 0): int
    {
        $processed = 0;
        while ($maxJobs === 0 || $processed < $maxJobs) {
            if (!self::runNext($queue)) {
                if ($maxJobs === 0) {
                    usleep(500000);
                    continue;
                }
                break;
            }
            $processed++;
        }

        return $processed;
    }

    /** @param array<string,mixed> $job */
    private static function execute(array $job): void
    {
        $class = (string) $job['job'];
        $payload = Json::decode((string) ($job['payload_json'] ?? ''));
        try {
            if (!class_exists($class)) {
                throw new \RuntimeException('Unknown job class: ' . $class);
            }
            $instance = new $class();
            if (!$instance instanceof Job) {
                throw new \RuntimeException('Job does not implement Job: ' . $class);
            }
            $instance->handle($payload);
            App::db()->update('jobs', [
                'reserved_at' => null,
                'reserved_by' => null,
                'failed_at' => null,
                'last_error' => null,
            ], 'id = :id', ['id' => (int) $job['id']]);
        } catch (\Throwable $e) {
            self::fail($job, $e->getMessage());
            Logger::error('queue.job_failed', ['job' => $class, 'error' => $e->getMessage(), 'attempt' => $job['attempts'] ?? 0]);
        }
    }

    /** @param array<string,mixed> $job */
    private static function fail(array $job, string $error): void
    {
        $attempts = (int) ($job['attempts'] ?? 1);
        $maxAttempts = (int) ($job['max_attempts'] ?? 5);
        $data = [
            'reserved_at' => null,
            'reserved_by' => null,
            'last_error' => Str::limit($error, 2000),
        ];
        if ($attempts >= $maxAttempts) {
            $data['failed_at'] = Database::now();
            Events::dispatch('job.failed', ['job' => $job['job'], 'payload' => Json::decode((string) ($job['payload_json'] ?? '')), 'error' => $error]);
        } else {
            // 30s, 2m, 8m, 32m ... capped at 6h
            $backoff = (int) min(21600, 30 * (4 ** max(0, $attempts - 1)));
            $data['available_at'] = gmdate('Y-m-d H:i:s', time() + $backoff);
        }
        App::db()->update('jobs', $data, 'id = :id', ['id' => (int) $job['id']]);
    }

    public static function pendingCount(string $queue = self::DEFAULT_QUEUE): int
    {
        return (int) App::db()->value(
            'SELECT COUNT(*) FROM jobs WHERE queue = :queue AND failed_at IS NULL',
            ['queue' => $queue]
        );
    }

    public static function failedCount(): int
    {
        return (int) App::db()->value('SELECT COUNT(*) FROM jobs WHERE failed_at IS NOT NULL');
    }
}
