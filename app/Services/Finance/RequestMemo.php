<?php

namespace App\Services\Finance;

use Closure;
use WeakReference;

/**
 * A memo that lives for one read-only HTTP request (P7 H3). Pages such as the dashboard ask the same question (the fiscal year's
 * periods, the lineage maps, the line figures) from several services; this answers it once. It is never active outside a routed
 * GET/HEAD request, so jobs, commands, tests that call services directly and every write keep reading live data.
 */
final class RequestMemo
{
    private static ?WeakReference $request = null;

    /** @var array<string, mixed> */
    private static array $values = [];

    public static function remember(string $key, Closure $compute): mixed
    {
        if (! self::active()) {
            return $compute();
        }
        $request = request();
        if (self::$request?->get() !== $request) {
            self::$request = WeakReference::create($request);
            self::$values = [];
        }

        return array_key_exists($key, self::$values) ? self::$values[$key] : (self::$values[$key] = $compute());
    }

    private static function active(): bool
    {
        if (! app()->bound('request')) {
            return false;
        }
        $request = request();

        return $request->isMethodSafe() && $request->route() !== null;
    }
}
