<?php

namespace Core\Profiler\Interface;

use Symfony\Component\Stopwatch\Stopwatch;

/**
 * Indicates the implementing class is using the {@see \Core\ClerkProfiler} trait.
 */
interface Profilable
{
    public function setProfiler(
        ?Stopwatch $stopwatch,
        ?string    $category = null,
    ) : void;
}
