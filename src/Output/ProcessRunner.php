<?php

declare(strict_types=1);

namespace PumlSplitter\Output;

/**
 * Runs an external command. Abstracted so {@see SvgRenderer} can be tested with a
 * fake runner and never needs a real `plantuml` binary.
 */
interface ProcessRunner
{
    /**
     * @param list<string> $command program and arguments
     */
    public function run(array $command): ProcessResult;
}
