<?php

declare(strict_types=1);

namespace Core\Profiler;

use Symfony\Component\Stopwatch\Stopwatch;

trait StopwatchProfiler
{
    protected readonly ?ClerkProfiler $profiler;

    protected function  assignProfiler( null|Stopwatch|ClerkProfiler $profiler ) : void
    {
        $this->profiler = ClerkProfiler::from( $profiler, $this::class );
    }

    public function __destruct()
    {
        $this->profiler?->stop( category : $this::class );
    }
}
