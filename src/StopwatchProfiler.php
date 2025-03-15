<?php

declare(strict_types=1);

namespace Core\Profiler;

use Symfony\Component\Stopwatch\Stopwatch;

trait StopwatchProfiler
{
    protected readonly ?ClerkProfiler $profiler;

    protected function assignProfiler(
        null|Stopwatch|ClerkProfiler $profiler,
        ?string                      $category = null,
    ) : void {
        $this->profiler = ClerkProfiler::from( $profiler, $category ?? $this::class );
    }
}
