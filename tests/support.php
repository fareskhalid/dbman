<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function fails(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (Throwable $exception) {
        check(str_contains($exception->getMessage(), $message), 'Expected failure containing: ' . $message);
        return;
    }
    throw new RuntimeException('Expected operation to fail: ' . $message);
}

function temporaryProject(): string
{
    $directory = sys_get_temp_dir() . '/dbman-check-' . bin2hex(random_bytes(6));
    mkdir($directory . '/database/migrations', 0700, true);
    mkdir($directory . '/database/seeders', 0700, true);
    return $directory;
}

function removeTemporaryProject(string $directory): void
{
    if (!str_starts_with(basename($directory), 'dbman-check-') || dirname($directory) !== sys_get_temp_dir()) {
        throw new RuntimeException('Refusing to remove a non-test directory.');
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $file) {
        if ($file->isDir() && !$file->isLink()) {
            rmdir($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }
    rmdir($directory);
}

/** @return array{int, string, string} */
function runProcess(array $command, string $directory, array $environment = []): array
{
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $directory, array_merge(getenv(), $environment));
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start test process.');
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $output, $error];
}
