<?php

namespace Illuminate\Console\Process;

use Closure;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyTimeoutException;
use Symfony\Component\Process\Process;

class PendingProcess
{
    /**
     * The process factory instance.
     *
     * @var \Illuminate\Console\Process\Factory
     */
    protected $factory;

    /**
     * The command to invoke the process.
     *
     * @var array<array-key, string>|string|null
     */
    public $command;

    /**
     * The working directory of the process.
     *
     * @var string
     */
    public $path;

    /**
     * The maximum number of seconds the process may run.
     *
     * @var int|null
     */
    public $timeout = 60;

    /**
     * The maximum number of seconds the process may go without returning output.
     *
     * @var int
     */
    public $idleTimeout;

    /**
     * The additional environment variables for the process.
     *
     * @var array
     */
    public $environment = [];

    /**
     * Indicates whether output should be disabled for the process.
     *
     * @var array
     */
    public $withoutOutput = false;

    /**
     * Indicates if TTY mode should be enabled.
     *
     * @var bool
     */
    public $tty = false;

    /**
     * The options that will be passed to "proc_open".
     *
     * @var array
     */
    public $options = [];

    /**
     * The registered fake handler callbacks.
     *
     * @var array
     */
    protected $fakeHandlers = [];

    /**
     * Create a new pending process instance.
     *
     * @param  \Illuminate\Console\Process\Factory  $factory
     * @return void
     */
    public function __construct(Factory $factory)
    {
        $this->factory = $factory;
    }

    /**
     * Specify the command that will invoke the process.
     *
     * @param  array<array-key, string>|string  $command
     * @return $this
     */
    public function command(array|string $command)
    {
        $this->command = $command;

        return $this;
    }

    /**
     * Specify the working directory of the process.
     *
     * @param  string  $path
     * @return $this
     */
    public function path(string $path)
    {
        $this->path = $path;

        return $this;
    }

    /**
     * Specify the maximum number of seconds the process may run.
     *
     * @param  int  $timeout
     * @return $this
     */
    public function timeout(int $timeout)
    {
        $this->timeout = $timeout;

        return $this;
    }

    /**
     * Specify the maximum number of seconds a process may go without returning output.
     *
     * @param  int  $timeout
     * @return $this
     */
    public function idleTimeout(int $timeout)
    {
        $this->idleTimeout = $timeout;

        return $this;
    }

    /**
     * Indicate that the process may run forever without timing out.
     *
     * @return $this
     */
    public function forever()
    {
        $this->timeout = null;

        return $this;
    }

    /**
     * Set the additional environent variables for the process.
     *
     * @param  array  $environment
     */
    public function env(array $environment)
    {
        $this->environment = $environment;

        return $this;
    }

    /**
     * Disable output for the process.
     *
     * @return $this
     */
    public function withoutOutput()
    {
        $this->withoutOutput = true;

        return $this;
    }

    /**
     * Enable TTY mode for the process.
     *
     * @param  bool  $tty
     * @return $this
     */
    public function tty(bool $tty = true)
    {
        $this->tty = $tty;

        return $this;
    }

    /**
     * Set the "proc_open" options that should be used when invoking the process.
     *
     * @param  array  $options
     * @return $this
     */
    public function options(array $options)
    {
        $this->options = $options;

        return $this;
    }

    /**
     * Run the process.
     *
     * @param  array<array-key, string>|string|null  $command
     * @param  callable|null  $output
     * @return \Illuminate\Contracts\Console\Process\ProcessResult
     */
    public function run(array|string $command = null, callable $output = null)
    {
        $this->command = $command ?: $this->command;

        try {
            $process = $this->toSymfonyProcess($command);

            if ($fake = $this->fakeFor($command = $process->getCommandline())) {
                return tap($this->resolveSynchronousFake($command, $fake), function ($result) {
                    $this->factory->recordIfRecording($this, $result);
                });
            } elseif ($this->factory->isRecording() && $this->factory->preventingStrayProcesses()) {
                throw new RuntimeException('Attempted process ['.(string) $this->command.'] without a matching fake.');
            }

            return new ProcessResult(tap($process)->run($output));
        } catch (SymfonyTimeoutException $e) {
            throw new ProcessTimedOutException($e, new ProcessResult($process));
        }
    }

    /**
     * Start the process in the background.
     *
     * @param  array<array-key, string>|string|null  $command
     * @param  callable  $output
     * @return \Illuminate\Console\Process\InvokedProcess
     */
    public function start(array|string $command = null, callable $output = null)
    {
        $this->command = $command ?: $this->command;

        $process = $this->toSymfonyProcess($command);

        if ($fake = $this->fakeFor($command = $process->getCommandline())) {
            return tap($this->resolveAsynchronousFake($command, $output, $fake), function (FakeInvokedProcess $process) {
                $this->factory->recordIfRecording($this, $process->predictProcessResult());
            });
        } elseif ($this->factory->isRecording() && $this->factory->preventingStrayProcesses()) {
            throw new RuntimeException('Attempted process ['.(string) $this->command.'] without a matching fake.');
        }

        return new InvokedProcess(tap($process)->start($output));
    }

    /**
     * Get a Symfony Process instance from the current pending command.
     *
     * @param  array<array-key, string>|string|null  $command
     * @return \Symfony\Component\Process\Process
     */
    protected function toSymfonyProcess(array|string|null $command)
    {
        $command = $command ?? $this->command;

        $process = is_iterable($command)
                ? new Process($command, null, $this->environment)
                : Process::fromShellCommandline((string) $command, null, $this->environment);

        $process->setWorkingDirectory((string) ($this->path ?? getcwd()));
        $process->setTimeout($this->timeout);

        if ($this->idleTimeout) {
            $process->setIdleTimeout($this->idleTimeout);
        }

        if ($this->withoutOutput) {
            $process->disableOutput();
        }

        if ($this->tty) {
            $process->setTty(true);
        }

        if (! empty($this->options)) {
            $process->setOptions($this->options);
        }

        return $process;
    }

    /**
     * Specify the fake process result handlers for the pending process.
     *
     * @param  array  $fakeHandlers
     * @return $this
     */
    public function withFakeHandlers(array $fakeHandlers)
    {
        $this->fakeHandlers = $fakeHandlers;

        return $this;
    }

    /**
     * Get the fake handler for the given command, if applicable.
     *
     * @param  string  $command
     * @return \Closure|null
     */
    protected function fakeFor(string $command)
    {
        return collect($this->fakeHandlers)
                ->first(fn ($handler, $pattern) => Str::is($pattern, $command));
    }

    /**
     * Resolve the given fake handler for a synchronous process.
     *
     * @param  string  $command
     * @param  \Closure  $fake
     * @return mixed
     */
    protected function resolveSynchronousFake(string $command, Closure $fake)
    {
        $result = $fake($this);

        if (is_string($result) || is_array($result)) {
            return (new FakeProcessResult(output: $result))->withCommand($command);
        } elseif ($result instanceof ProcessResult) {
            return $result;
        } elseif ($result instanceof FakeProcessResult) {
            return $result->withCommand($command);
        } elseif ($result instanceof FakeProcessDescription) {
            return $result->toProcessResult($command);
        } elseif ($result instanceof FakeProcessSequence) {
            return $this->resolveSynchronousFake($command, fn () => $result());
        }

        throw new LogicException("Unsupported synchronous process fake result provided.");
    }

    /**
     * Resolve the given fake handler for an asynchronous process.
     *
     * @param  string  $command
     * @param  callable|null  $output
     * @param  \Closure  $fake
     * @return \Illuminate\Console\Process\FakeInvokedProcess
     */
    protected function resolveAsynchronousFake(string $command, ?callable $output, Closure $fake)
    {
        $result = $fake($this);

        if (is_string($result) || is_array($result)) {
            $result = new FakeProcessResult(output: $result);
        }

        if ($result instanceof ProcessResult) {
            return (new FakeInvokedProcess(
                $command,
                (new FakeProcessDescription)
                    ->output($result->output())
                    ->errorOutput($result->errorOutput())
                    ->runsFor(iterations: 0)
                    ->exitCode($result->exitCode())
            ))->withOutputHandler($output);
        } elseif ($result instanceof FakeProcessResult) {
            return (new FakeInvokedProcess(
                $command,
                (new FakeProcessDescription)
                    ->output($result->output())
                    ->errorOutput($result->errorOutput())
                    ->runsFor(iterations: 0)
                    ->exitCode($result->exitCode())
            ))->withOutputHandler($output);
        } elseif ($result instanceof FakeProcessDescription) {
            return (new FakeInvokedProcess($command, $result))->withOutputHandler($output);
        } elseif ($result instanceof FakeProcessSequence) {
            return $this->resolveAsynchronousFake($command, $output, fn () => $result());
        }

        throw new LogicException("Unsupported asynchronous process fake result provided.");
    }
}
