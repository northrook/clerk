<?php

declare(strict_types=1);

namespace Core\Profiler;

use Symfony\Component\Stopwatch\Stopwatch;

/**
 * @phpstan-require-implements Interface\SettableProfilerInterface
 */
trait SettableStopwatchProfiler
{
    use StopwatchProfiler;

    public function setProfiler( ?Stopwatch $stopwatch ) : void
    {
        $this->assignProfiler( $stopwatch );
    }
}
