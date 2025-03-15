<?php

namespace Core\Profiler\Interface;

use Symfony\Component\Stopwatch\Stopwatch;

/**
 * Indicates the implementing class is using the {@see \Core\ClerkProfiler} trait.
 */
interface SettableProfilerInterface
{
    public function setProfiler( ?Stopwatch $stopwatch ) : void;
}
