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
use Ripple\Stream\Exception\ConnectionException;
use Ripple\Time;
use Throwable;
use Workerman\Events\EventInterface;
use Workerman\Worker;

use function call_user_func;
use function call_user_func_array;
use function Co\go;
use function Co\wait;
use function count;
use function explode;
use function function_exists;
use function get_resource_id;
use function is_array;
use function is_string;
use function str_contains;
use function spl_object_id;
use function array_filter;
use function array_values;

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
     * @var array
     */
    protected array $_fd2RIDs = [];

    /**
     * @var array
     */
    protected array $_fd2WIDs = [];

    /**
     * @var array
     */
    protected array $_signal2ids = [];

    /**
     * @param       $fd //callback
     * @param       $flag //类型
     * @param       $func //回调
     * @param array $args //参数列表
     *
     * @return bool|int
     * @throws ConnectionException
     */
    public function add($fd, $flag, $func, $args = []): bool|int
    {
        switch ($flag) {
            case EventInterface::EV_SIGNAL:
                try {
                    // 兼容 Workerman 的信号处理
                    if ($func instanceof Closure) {
                        $closure = static fn () => go(static fn () => $func($fd));
                    }

                    // 兼容 Workerman 数组Callback方式
                    if (is_array($func)) {
                        $closure = static fn () => go(static fn () => call_user_func($func, $fd));
                    }

                    // 兼容 Workerman 字符串Callback方式
                    if (is_string($func)) {
                        if (str_contains($func, '::')) {
                            $explode = explode('::', $func);
                            $closure = static fn () => go(static fn () => call_user_func($explode, $fd));
                        }

                        if (function_exists($func)) {
                            $closure = static fn () => go(static fn () => $func($fd));
                        }
                    }

                    if (!isset($closure)) {
                        return false;
                    }

                    $id = Event::watchSignal($fd, $closure);
                    $this->_signal2ids[$fd] = $id;
                    return $id;
                } catch (Throwable) {
                    return false;
                }

            case EventInterface::EV_TIMER:
                $ticker = Time::ticker($fd);
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

            case EventInterface::EV_TIMER_ONCE:
                $timer = Time::afterFunc($fd, static function () use ($func, $args) {
                    go(static function () use ($func, $args) {
                        call_user_func_array($func, $args);
                    });
                });
                $timerId = spl_object_id($timer);

                $this->_timer[] = $timer;
                $this->_timerIds[$timerId] = $timer;

                return $timerId;

            case EventInterface::EV_READ:
                $eventId =  Event::watchRead($fd, static fn () => go(static fn () => $func($fd)));
                $streamId = get_resource_id($fd);
                $this->_fd2RIDs[$streamId][] = $eventId;
                return $eventId;

            case EventInterface::EV_WRITE:
                $eventId = Event::watchWrite($fd, static fn () => go(static fn () => $func($fd)));
                $streamId = get_resource_id($fd);
                $this->_fd2WIDs[$streamId][] = $eventId;
                return $eventId;
        }
        return false;
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/27 22:00
     *
     * @param $fd
     * @param $flag
     *
     * @return void
     */
    public function del($fd, $flag): void
    {
        if ($flag === EventInterface::EV_TIMER || $flag === EventInterface::EV_TIMER_ONCE) {
            if (isset($this->_timerIds[$fd])) {
                $timer = $this->_timerIds[$fd];
                $timer->stop();

                unset($this->_timerIds[$fd]);
                $this->_timer = array_values(array_filter($this->_timer, fn ($t) => $t !== $timer));
            }
            return;
        }

        if ($flag === EventInterface::EV_READ || $flag === EventInterface::EV_WRITE) {
            if (!$fd) {
                return;
            }

            $streamId = get_resource_id($fd);
            if ($flag === EventInterface::EV_READ) {
                foreach ($this->_fd2RIDs[$streamId] ?? [] as $eventId) {
                    Event::unwatch($eventId);
                }

                unset($this->_fd2RIDs[$streamId]);
            } else {
                foreach ($this->_fd2WIDs[$streamId] ?? [] as $eventId) {
                    Event::unwatch($eventId);
                }

                unset($this->_fd2WIDs[$streamId]);
            }
            return;
        }

        if ($flag === EventInterface::EV_SIGNAL) {
            $signalId = $this->_signal2ids[$fd] ?? null;
            if ($signalId) {
                Event::unwatch($signalId);
                unset($this->_signal2ids[$fd]);
            }
        }
    }


    /**
     * @return void
     */
    public function clearAllTimer(): void
    {
        foreach ($this->_timer as $timer) {
            $timer->stop();
        }
        $this->_timer = [];
        $this->_timerIds = [];
    }

    /**
     * @return void
     * @throws Throwable
     */
    public function loop(): void
    {
        /**
         * 不会再有任何事发生
         * Workerman会将结束的进程视为异常然后重启, 循环往复
         */

        while (1) {
            wait();
            \Co\sleep(1);
        }
    }

    /**
     * @return int
     */
    public function getTimerCount(): int
    {
        return count($this->_timer);
    }

    /**
     * @return void
     */
    public function destroy(): void
    {
        // 清理所有定时器
        $this->clearAllTimer();

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
        $this->_timerIds = [];
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
