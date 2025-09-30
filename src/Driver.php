<?php declare(strict_types=1);
/**
 * Copyright © 2024 cclilshy
 * Email: jingnigg@gmail.com
 *
 * This software is licensed under the MIT License.
 * For full license details, please visit: https://opensource.org/licenses/MIT
 *
 * By using this software, you agree to the terms of the license.
 * Contributions, suggestions, and feedback are always welcome!
 */

namespace Workerman\Ripple;

use Closure;
use Ripple\Event;
use Ripple\Time;
use Workerman\Events\EventInterface;
use Workerman\Worker;

use function array_filter;
use function array_values;
use function call_user_func;
use function call_user_func_array;
use function Co\go;
use function Co\sleep;
use function Co\wait;
use function count;
use function explode;
use function function_exists;
use function get_resource_id;
use function is_array;
use function is_string;
use function pcntl_signal;
use function spl_object_id;
use function str_contains;

use const SIG_IGN;
use const SIGINT;

class Driver implements EventInterface
{
    /**
     * @var array 定时器对象数组
     */
    protected array $_timer = [];

    /**
     * @var array 定时器ID到对象的映射
     */
    protected array $_timerIds = [];

    /**
     * @var array 文件描述符到读取事件ID的映射
     */
    protected array $_fd2RIDs = [];

    /**
     * @var array 文件描述符到写入事件ID的映射
     */
    protected array $_fd2WIDs = [];

    /**
     * @var array 信号到事件ID的映射
     */
    protected array $_signal2ids = [];

    /**
     * @return void
     */
    public function run(): void
    {
        /**
         * 不会再有任何事发生
         * Workerman会将结束的进程视为异常然后重启, 循环往复
         */
        while (1) {
            wait();
            sleep(1);
        }
    }

    /**
     * @return void
     */
    public function stop(): void
    {
        $this->destroy();
        pcntl_signal(SIGINT, SIG_IGN);
    }

    /**
     * @param float    $delay
     * @param callable $func
     * @param array    $args
     *
     * @return int
     */
    public function delay(float $delay, callable $func, array $args = []): int
    {
        $timer = Time::afterFunc($delay, static function () use ($func, $args) {
            call_user_func_array($func, $args);
        });
        $timerId = spl_object_id($timer);

        $this->_timer[] = $timer;
        $this->_timerIds[$timerId] = $timer;

        return $timerId;
    }

    /**
     * @param float    $interval
     * @param callable $func
     * @param array    $args
     *
     * @return int
     */
    public function repeat(float $interval, callable $func, array $args = []): int
    {
        $ticker = Time::ticker($interval);
        $timerId = spl_object_id($ticker);

        $this->_timer[] = $ticker;
        $this->_timerIds[$timerId] = $ticker;

        go(static function () use ($ticker, $func, $args) {
            while (!$ticker->isStopped()) {
                $ticker->channel()->receive();
                go(static function () use ($func, $args) {
                    call_user_func_array($func, $args);
                });
            }
        });

        return $timerId;
    }

    /**
     * @param          $stream
     * @param callable $func
     *
     * @return void
     */
    public function onReadable($stream, callable $func): void
    {
        $eventId = Event::watchRead($stream, static fn () => go(static fn () => $func($stream)));
        $streamId = get_resource_id($stream);
        $this->_fd2RIDs[$streamId][] = $eventId;
    }

    /**
     * @param $stream
     *
     * @return bool
     */
    public function offReadable($stream): bool
    {
        if (!$stream) {
            return false;
        }

        $streamId = get_resource_id($stream);
        if (isset($this->_fd2RIDs[$streamId])) {
            foreach ($this->_fd2RIDs[$streamId] as $eventId) {
                Event::unwatch($eventId);
            }
            unset($this->_fd2RIDs[$streamId]);
            return true;
        }
        return false;
    }

    /**
     * @param          $stream
     * @param callable $func
     *
     * @return void
     */
    public function onWritable($stream, callable $func): void
    {
        $eventId = Event::watchWrite($stream, static fn () => go(static fn () => $func($stream)));
        $streamId = get_resource_id($stream);
        $this->_fd2WIDs[$streamId][] = $eventId;
    }

    /**
     * @param $stream
     *
     * @return bool
     */
    public function offWritable($stream): bool
    {
        if (!$stream) {
            return false;
        }

        $streamId = get_resource_id($stream);
        if (isset($this->_fd2WIDs[$streamId])) {
            foreach ($this->_fd2WIDs[$streamId] as $eventId) {
                Event::unwatch($eventId);
            }
            unset($this->_fd2WIDs[$streamId]);
            return true;
        }
        return false;
    }

    /**
     * @param int      $signal
     * @param callable $func
     *
     * @return void
     */
    public function onSignal(int $signal, callable $func): void
    {
        // 兼容 Workerman 的信号处理
        if ($func instanceof Closure) {
            $closure = static fn () => go(static fn () => $func($signal));
        } elseif (is_array($func)) {
            $closure = static fn () => go(static fn () => call_user_func($func, $signal));
        } elseif (is_string($func)) {
            if (str_contains($func, '::')) {
                $explode = explode('::', $func);
                $closure = static fn () => go(static fn () => call_user_func($explode, $signal));
            } elseif (function_exists($func)) {
                $closure = static fn () => go(static fn () => $func($signal));
            } else {
                return;
            }
        } else {
            return;
        }

        $id = Event::watchSignal($signal, $closure);
        $this->_signal2ids[$signal] = $id;
    }

    /**
     * @param int $signal
     *
     * @return bool
     */
    public function offSignal(int $signal): bool
    {
        $signalId = $this->_signal2ids[$signal] ?? null;
        if ($signalId) {
            Event::unwatch($signalId);
            unset($this->_signal2ids[$signal]);
            return true;
        }
        return false;
    }

    /**
     * @param int $timerId
     *
     * @return bool
     */
    public function offRepeat(int $timerId): bool
    {
        return $this->offDelay($timerId);
    }

    /**
     * @param int $timerId
     *
     * @return bool
     */
    public function offDelay(int $timerId): bool
    {
        if (isset($this->_timerIds[$timerId])) {
            $timer = $this->_timerIds[$timerId];
            $timer->stop();

            unset($this->_timerIds[$timerId]);
            $this->_timer = array_values(array_filter($this->_timer, fn ($t) => $t !== $timer));
            return true;
        }
        return false;
    }

    /**
     * @return void
     */
    public function deleteAllTimer(): void
    {
        foreach ($this->_timer as $timer) {
            $timer->stop();
        }
        $this->_timer = [];
        $this->_timerIds = [];
    }

    /**
     * @return int
     */
    public function getTimerCount(): int
    {
        return count($this->_timer);
    }

    /**
     * @param callable $errorHandler
     *
     * @return void
     */
    public function setErrorHandler(callable $errorHandler): void
    {
    }

    /**
     * @return void
     */
    public function destroy(): void
    {
        // 清理所有定时器
        $this->deleteAllTimer();

        // 清理所有事件监听器
        foreach ($this->_fd2RIDs as $eventIds) {
            foreach ($eventIds as $eventId) {
                Event::unwatch($eventId);
            }
        }
        foreach ($this->_fd2WIDs as $eventIds) {
            foreach ($eventIds as $eventId) {
                Event::unwatch($eventId);
            }
        }
        foreach ($this->_signal2ids as $eventId) {
            Event::unwatch($eventId);
        }

        $this->_fd2RIDs = [];
        $this->_fd2WIDs = [];
        $this->_signal2ids = [];
    }

    /**
     * @return void
     */
    public static function runAll(): void
    {
        Worker::$eventLoopClass = static::class;
        Worker::runAll();
    }
}
