<?php

namespace Amp\Process\Internal\Posix;

use Amp\ByteStream\ResourceInputStream;
use Amp\ByteStream\ResourceOutputStream;
use Amp\Deferred;
use Amp\Loop;
use Amp\Process\Internal\ProcessHandle;
use Amp\Process\Internal\ProcessRunner;
use Amp\Process\Internal\ProcessStatus;
use Amp\Process\ProcessException;
use Amp\Process\ProcessInputStream;
use Amp\Process\ProcessOutputStream;
use Amp\Promise;

final class Runner implements ProcessRunner {
    const FD_SPEC = [
        ["pipe", "r"], // stdin
        ["pipe", "w"], // stdout
        ["pipe", "w"], // stderr
        ["pipe", "w"], // exit code pipe
    ];

    public static function onProcessEndExtraDataPipeReadable($watcher, $stream, Handle $handle) {
        Loop::cancel($watcher);

        $handle->status = ProcessStatus::ENDED;

        if (!\is_resource($stream) || \feof($stream)) {
            $handle->joinDeferred->fail(new ProcessException("Process ended unexpectedly"));
        } else {
            $handle->joinDeferred->resolve((int) \rtrim(@\stream_get_contents($stream)));
        }
    }

    public static function onProcessStartExtraDataPipeReadable($watcher, $stream, $data) {
        Loop::cancel($watcher);

        $pid = \rtrim(@\fgets($stream));

        /** @var $deferreds Deferred[] */
        list($handle, $pipes, $deferreds) = $data;

        if (!$pid || !\is_numeric($pid)) {
            $error = new ProcessException("Could not determine PID");
            $handle->pidDeferred->fail($error);
            $handle->joinDeferred->fail($error);
            foreach ($deferreds as $deferred) {
                /** @var $deferred Deferred */
                $deferred->fail($error);
            }
            return;
        }

        $handle->status = ProcessStatus::RUNNING;
        $handle->pidDeferred->resolve((int) $pid);
        $deferreds[0]->resolve(new ResourceOutputStream($pipes[0]));
        $deferreds[1]->resolve(new ResourceInputStream($pipes[1]));
        $deferreds[2]->resolve(new ResourceInputStream($pipes[2]));

        $handle->extraDataPipeWatcher = Loop::onReadable($stream, [self::class, 'onProcessEndExtraDataPipeReadable'], $handle);
        Loop::unreference($handle->extraDataPipeWatcher);

        $handle->sockets->resolve();
    }

    /** @inheritdoc */
    public function start(string $command, string $cwd = null, array $env = [], array $options = []): ProcessHandle {
        $command = \sprintf(
            '{ (%s) <&3 3<&- 3>/dev/null & } 3<&0;' .
            'pid=$!; echo $pid >&3; wait $pid; RC=$?; echo $RC >&3; exit $RC',
            $command
        );

        $handle = new Handle;
        $handle->proc = @\proc_open($command, self::FD_SPEC, $pipes, $cwd ?: null, $env ?: null, $options);

        if (!\is_resource($handle->proc)) {
            $message = "Could not start process";
            if ($error = \error_get_last()) {
                $message .= \sprintf(" Errno: %d; %s", $error["type"], $error["message"]);
            }
            throw new ProcessException($message);
        }

        $status = \proc_get_status($handle->proc);

        if (!$status) {
            \proc_close($handle->proc);
            throw new ProcessException("Could not get process status");
        }

        $stdinDeferred = new Deferred;
        $handle->stdin = new ProcessOutputStream($stdinDeferred->promise());

        $stdoutDeferred = new Deferred;
        $handle->stdout = new ProcessInputStream($stdoutDeferred->promise());

        $stderrDeferred = new Deferred;
        $handle->stderr = new ProcessInputStream($stderrDeferred->promise());

        $handle->extraDataPipe = $pipes[3];

        \stream_set_blocking($pipes[3], false);

        Loop::onReadable($pipes[3], [self::class, 'onProcessStartExtraDataPipeReadable'], [$handle, $pipes, [
            $stdinDeferred, $stdoutDeferred, $stderrDeferred
        ]]);

        return $handle;
    }

    /** @inheritdoc */
    public function join(ProcessHandle $handle): Promise {
        /** @var Handle $handle */
        if ($handle->extraDataPipeWatcher !== null) {
            Loop::reference($handle->extraDataPipeWatcher);
        }

        return $handle->joinDeferred->promise();
    }

    /** @inheritdoc */
    public function kill(ProcessHandle $handle) {
        /** @var Handle $handle */
        if (!\proc_terminate($handle->proc, 9)) { // Forcefully kill the process using SIGKILL.
            throw new ProcessException("Terminating process failed");
        }

        Loop::cancel($handle->extraDataPipeWatcher);
        $handle->extraDataPipeWatcher = null;

        $handle->status = ProcessStatus::ENDED;
        $handle->joinDeferred->fail(new ProcessException("The process was killed"));
    }

    /** @inheritdoc */
    public function signal(ProcessHandle $handle, int $signo) {
        /** @var Handle $handle */
        if (!\proc_terminate($handle->proc, $signo)) {
            throw new ProcessException("Sending signal to process failed");
        }
    }

    /** @inheritdoc */
    public function destroy(ProcessHandle $handle) {
        /** @var Handle $handle */
        if ($handle->status < ProcessStatus::ENDED && \getmypid() === $handle->originalParentPid) {
            $this->kill($handle);
        }

        if ($handle->extraDataPipeWatcher !== null) {
            Loop::cancel($handle->extraDataPipeWatcher);
        }

        if (\is_resource($handle->extraDataPipe)) {
            \fclose($handle->extraDataPipe);
        }

        $handle->stdin->close();
        $handle->stdout->close();
        $handle->stderr->close();

        if (\is_resource($handle->proc)) {
            \proc_close($handle->proc);
        }
    }
}