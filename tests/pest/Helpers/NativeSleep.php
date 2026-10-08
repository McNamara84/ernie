<?php

declare(strict_types=1);

namespace Tests\Helpers {
    /**
     * Record native service delays while a test explicitly opts in. Outside that
     * scope the namespace adapters delegate to PHP's original functions.
     */
    final class NativeSleep
    {
        /** @var list<int>|null */
        private static ?array $seconds = null;

        /** @var list<int>|null */
        private static ?array $microseconds = null;

        public static function fake(): void
        {
            self::$seconds = [];
            self::$microseconds = [];
        }

        public static function restore(): void
        {
            self::$seconds = null;
            self::$microseconds = null;
        }

        /** @return list<int> */
        public static function seconds(): array
        {
            return self::$seconds ?? [];
        }

        /** @return list<int> */
        public static function microseconds(): array
        {
            return self::$microseconds ?? [];
        }

        public static function sleep(int $seconds): int
        {
            if (self::$seconds === null) {
                return \sleep($seconds);
            }
            if ($seconds < 0) {
                throw new \ValueError('Sleep duration must be non-negative.');
            }
            self::$seconds[] = $seconds;

            return 0;
        }

        public static function usleep(int $microseconds): void
        {
            if (self::$microseconds === null) {
                \usleep($microseconds);

                return;
            }
            if ($microseconds < 0) {
                throw new \ValueError('Sleep duration must be non-negative.');
            }
            self::$microseconds[] = $microseconds;
        }
    }
}
