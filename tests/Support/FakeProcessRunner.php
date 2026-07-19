<?php

declare(strict_types=1);

namespace PumlSplitter\Tests\Support;

use PumlSplitter\Output\ProcessResult;
use PumlSplitter\Output\ProcessRunner;

/**
 * A scripted {@see ProcessRunner} for tests: records commands and returns
 * configurable exit codes, so SvgRenderer can be tested without PlantUML.
 */
final class FakeProcessRunner implements ProcessRunner
{
    /** @var list<list<string>> */
    public array $commands = [];
    public int $versionExit = 0;
    public int $renderExit = 0;
    public string $renderStdout = '';
    public string $renderStderr = '';

    /**
     * When non-empty, consumed one exit code per render call (in order)
     * instead of the flat $renderExit — for scripting "the Nth batch fails,
     * earlier ones succeed".
     *
     * @var list<int>
     */
    public array $renderExitSequence = [];

    public function run(array $command): ProcessResult
    {
        $this->commands[] = $command;

        if (in_array('-version', $command, true)) {
            return new ProcessResult($this->versionExit, 'PlantUML 1.0', '');
        }

        $exit = $this->renderExitSequence !== [] ? array_shift($this->renderExitSequence) : $this->renderExit;

        return new ProcessResult($exit, $this->renderStdout, $this->renderStderr);
    }
}
