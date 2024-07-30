<?php declare(strict_types=1);
/*
 * Copyright (c) 2023-2024.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * 特此免费授予任何获得本软件及相关文档文件（“软件”）副本的人，不受限制地处理
 * 本软件，包括但不限于使用、复制、修改、合并、出版、发行、再许可和/或销售
 * 软件副本的权利，并允许向其提供本软件的人做出上述行为，但须符合以下条件：
 *
 * 上述版权声明和本许可声明应包含在本软件的所有副本或主要部分中。
 *
 * 本软件按“原样”提供，不提供任何形式的保证，无论是明示或暗示的，
 * 包括但不限于适销性、特定目的的适用性和非侵权性的保证。在任何情况下，
 * 无论是合同诉讼、侵权行为还是其他方面，作者或版权持有人均不对
 * 由于软件或软件的使用或其他交易而引起的任何索赔、损害或其他责任承担责任。
 */

namespace Psc\Library\System\Process;

use Closure;
use Psc\Core\Output;
use Psc\Core\LibraryAbstract;
use Psc\Library\System\Exception\ProcessException;
use Revolt\EventLoop;
use Revolt\EventLoop\UnsupportedFeatureException;
use Throwable;

use function call_user_func;
use function P\promise;
use function P\reinstall;
use function P\run;
use function pcntl_fork;
use function pcntl_wait;
use function pcntl_wexitstatus;
use function pcntl_wifexited;
use function posix_getpid;
use function posix_getppid;

use const SIGCHLD;
use const SIGKILL;
use const WNOHANG;
use const WUNTRACED;

/**
 *
 */
class Process extends LibraryAbstract
{
    /**
     * @var LibraryAbstract
     */
    protected static LibraryAbstract $instance;

    /**
     * @var array
     */
    private array $process2promiseCallback = [];

    /**
     * @var Runtime[]
     */
    private array $process2runtime = [];

    /**
     * @var array
     */
    private array $onFork = [];

    /**
     * @throws UnsupportedFeatureException
     */
    public function __construct()
    {
        $this->registerSignalHandler();
    }

    /**
     *
     */
    public function __destruct()
    {
        $this->destroy();
    }

    /**
     * @return void
     * @throws UnsupportedFeatureException
     */
    private function registerSignalHandler(): void
    {
        $this->onSignal(SIGCHLD, fn () => $this->signalSIGCHLDHandler());
    }


    /**
     * @return void
     */
    private function signalSIGCHLDHandler(): void
    {
        while (1) {
            $childrenId = pcntl_wait($status, WNOHANG | WUNTRACED);

            if ($childrenId <= 0) {
                break;
            }

            $this->onProcessExit($childrenId, $status);
        }
    }

    /**
     * @param int $processId
     * @param int $status
     * @return void
     */
    private function onProcessExit(int $processId, int $status): void
    {
        $exit            = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : -1;
        $promiseCallback = $this->process2promiseCallback[$processId] ?? null;
        if (!$promiseCallback) {
            return;
        }

        if ($exit === -1) {
            call_user_func($promiseCallback['reject'], new ProcessException('The process is abnormal.', $exit));
        } else {
            call_user_func($promiseCallback['resolve'], $exit);
        }

        unset($this->process2promiseCallback[$processId]);
        unset($this->process2runtime[$processId]);
    }

    /**
     * @return void
     */
    private function destroy(): void
    {
        foreach ($this->process2runtime as $runtime) {
            $runtime->signal(SIGKILL);
        }
    }

    /**
     * @var int
     */
    private int $index = 0;

    /**
     * @param Closure $closure
     * @return int
     */
    public function registerForkHandler(Closure $closure): int
    {
        $this->onFork[$this->index] = $closure;
        return $this->index++;
    }

    /**
     * @param int $index
     * @return void
     */
    public function cancelForkHandler(int $index): void
    {
        unset($this->onFork[$index]);
    }

    /**
     * @return void
     * @throws UnsupportedFeatureException|Throwable
     */
    public function noticeFork(): void
    {
        $this->registerSignalHandler();

        foreach ($this->onFork as $key => $closure) {
            try {
                unset($this->onFork[$key]);
                $closure();
            } catch (Throwable $e) {
                Output::error($e->getMessage());
            }
        }

        $this->process2promiseCallback = [];
        $this->process2runtime         = [];
    }

    /**
     * @return int
     */
    public function getPid(): int
    {
        return posix_getpid();
    }

    /**
     * @return int
     */
    public function getPPid(): int
    {
        return posix_getppid();
    }

    /**
     * @param Closure $closure
     * @return Task
     */
    public function task(Closure $closure): Task
    {
        return new Task(function (...$args) use ($closure) {
            $processId = pcntl_fork();

            if ($processId === -1) {
                throw new ProcessException('Fork failed.');
            }

            if ($processId === 0) {
                if (!EventLoop::getDriver()->isRunning()) {
                    EventLoop::setDriver(
                        (new EventLoop\DriverFactory())->create()
                    );

                    $this->noticeFork();

                    $result = call_user_func($closure, ...$args);
                    if($result !== null) {
                        exit(0);
                    }
                    run();
                }

                reinstall(function () use ($closure, $args) {
                    //reload process event
                    $this->noticeFork();

                    //call user function
                    $result = call_user_func($closure, ...$args);
                    if($result !== null) {
                        exit(0);
                    }
                }, true);
            }

            $promise = promise(function ($r, $d) use ($processId) {
                $this->process2promiseCallback[$processId] = [
                    'resolve' => $r,
                    'reject'  => $d,
                ];
            });

            $runtime = new Runtime(
                $promise,
                $processId,
            );

            $this->process2runtime[$processId] = $runtime;
            return $runtime;
        });
    }

    /**
     * @param int     $signalCode
     * @param Closure $handler
     * @return string
     * @throws UnsupportedFeatureException
     */
    public function onSignal(int $signalCode, Closure $handler): string
    {
        return EventLoop::onSignal($signalCode, $handler);
    }
}
