<?php

declare(strict_types=1);

namespace PumlSplitter\Output;

/**
 * Runs commands with the native process functions (no shell, no extra Composer
 * dependency). Child stdout/stderr are redirected to temp files rather than
 * pipes, so a chatty process can never deadlock against a full pipe buffer. A
 * missing executable surfaces as exit code 127.
 */
final class NativeProcessRunner implements ProcessRunner
{
    public function run(array $command): ProcessResult
    {
        $stdoutFile = $this->tempFile('out');
        $stderrFile = $this->tempFile('err');

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $stdoutFile, 'w'],
            2 => ['file', $stderrFile, 'w'],
        ];

        $pipes = [];
        $process = @proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            $this->cleanup($stdoutFile, $stderrFile);

            return new ProcessResult(127, '', 'unable to start process: ' . ($command[0] ?? ''));
        }

        if (isset($pipes[0])) {
            fclose($pipes[0]);
        }
        $exitCode = proc_close($process);

        $stdout = (string) @file_get_contents($stdoutFile);
        $stderr = (string) @file_get_contents($stderrFile);
        $this->cleanup($stdoutFile, $stderrFile);

        return new ProcessResult($exitCode, $stdout, $stderr);
    }

    private function tempFile(string $suffix): string
    {
        $path = tempnam(sys_get_temp_dir(), 'puml_' . $suffix . '_');

        return $path === false
            ? sys_get_temp_dir() . '/puml_' . $suffix . '_' . uniqid('', true)
            : $path;
    }

    private function cleanup(string ...$files): void
    {
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }
}
