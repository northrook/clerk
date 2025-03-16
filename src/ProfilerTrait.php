<?php

declare(strict_types=1);

namespace Core\Profiler;

use Symfony\Component\Stopwatch\Stopwatch;

/**
 * @phpstan-require-implements Interface\Profilable
 */
trait ProfilerTrait
{
    use StopwatchProfiler;

    public function setProfiler(
        ?Stopwatch $stopwatch,
        ?string    $category = null,
    ) : void {
        $this->assignProfiler( $stopwatch, $category );
    }
}
