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
use Ripple\Kernel;
use Ripple\Process\Process;
use Ripple\Stream;
use Throwable;
use Workerman\Events\EventInterface;
use Workerman\Worker;
use Ripple\Utils\Format;

use function array_search;
use function call_user_func;
use function call_user_func_array;
use function Co\cancel;
use function Co\delay;
use function Co\onSignal;
use function Co\repeat;
use function Co\stop;
use function Co\wait;
use function count;
use function explode;
use function function_exists;
use function get_resource_id;
use function getmypid;
use function is_array;
use function is_string;
use function posix_getpid;
use function sleep;
use function str_contains;

class Driver implements EventInterface
{
    /*** @var int */
    private static int $baseProcessId;

    /*** @var array */
    protected array $_timer = [];

    /*** @var array */
    protected array $_fd2RIDs = [];

    /*** @var array */
    protected array $_fd2WIDs = [];

    /*** @var array */
    protected array $_signal2ids = [];

    /**
     * @param       $fd   //callback
     * @param       $flag //类型
     * @param       $func //回调
     * @param array $args //参数列表
     *
     * @return bool|int
     */
    public function add($fd, $flag, $func, $args = []): bool|int
    {
        switch ($flag) {
            case EventInterface::EV_SIGNAL:
                try {
                    // 兼容 Workerman 的信号处理
                    if ($func instanceof Closure) {
                        $closure = static fn () => $func($fd);
                    }

                    // 兼容 Workerman 数组Callback方式
                    if (is_array($func)) {
                        $closure = static fn () => call_user_func($func, $fd);
                    }

                    // 兼容 Workerman 字符串Callback方式
                    if (is_string($func)) {
                        if (str_contains($func, '::')) {
                            $explode = explode('::', $func);
                            $closure = static fn () => call_user_func($explode, $fd);
                        }

                        if (function_exists($func)) {
                            $closure = static fn () => $func($fd);
                        }
                    }

                    if (!isset($closure)) {
                        return false;
                    }


                    $id                     = onSignal($fd, $closure);
                    $this->_signal2ids[$fd] = Format::string2int($id);
                    return Format::string2int($id);
                } catch (Throwable) {
                    return false;
                }

            case EventInterface::EV_TIMER:
                $this->_timer[] = $timerId = repeat(static function () use ($func, $args) {
                    try {
                        call_user_func_array($func, $args);
                    } catch (Throwable $e) {
                        Worker::stopAll(250, $e);
                    }
                }, $fd);
                return Format::string2int($timerId);

            case EventInterface::EV_TIMER_ONCE:
                $this->_timer[] = $timerId = delay(static function () use ($func, $args) {
                    try {
                        call_user_func_array($func, $args);
                    } catch (Throwable $e) {
                        Worker::stopAll(250, $e);
                    }
                }, $fd);
                return Format::string2int($timerId);

            case EventInterface::EV_READ:
                $stream  = new Stream($fd);
                $eventId = $stream->onReadable(static function (Stream $stream) use ($func) {
                    $func($stream->stream);
                });

                $this->_fd2RIDs[$stream->id][] = Format::string2int($eventId);
                return Format::string2int($eventId);

            case EventInterface::EV_WRITE:
                $stream  = new Stream($fd);
                $eventId = $stream->onWriteable(static function (Stream $stream) use ($func) {
                    $func($stream->stream);
                });

                $this->_fd2WIDs[$stream->id][] = Format::string2int($eventId);
                return Format::string2int($eventId);
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
            $this->cancel($fd);
            unset($this->_timer[array_search(Format::int2string($fd), $this->_timer)]);
            return;
        }

        if ($flag === EventInterface::EV_READ || $flag === EventInterface::EV_WRITE) {
            if (!$fd) {
                return;
            }

            $streamId = get_resource_id($fd);
            if ($flag === EventInterface::EV_READ) {
                foreach ($this->_fd2RIDs[$streamId] ?? [] as $eventId) {
                    $this->cancel($eventId);
                }
                unset($this->_fd2RIDs[$streamId]);
            } else {
                foreach ($this->_fd2WIDs[$streamId] ?? [] as $eventId) {
                    $this->cancel($eventId);
                }
                unset($this->_fd2WIDs[$streamId]);
            }
            return;
        }

        if ($flag === EventInterface::EV_SIGNAL) {
            $signalId = $this->_signal2ids[$fd] ?? null;
            if ($signalId) {
                $this->cancel($signalId);
                unset($this->_signal2ids[$fd]);
            }
        }
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/27 22:01
     *
     * @param int $id
     *
     * @return void
     */
    private function cancel(int $id): void
    {
        cancel(Format::int2string($id));
    }

    /**
     * @return void
     */
    public function clearAllTimer(): void
    {
        foreach ($this->_timer as $timerId) {
            $this->cancel($timerId);
        }
    }

    /**
     * @return void
     * @throws Throwable
     */
    public function loop(): void
    {
        if (!isset(static::$baseProcessId)) {
            static::$baseProcessId = (Kernel::getInstance()->supportProcessControl() ? getmypid() : posix_getpid());
        } elseif (static::$baseProcessId !== (Kernel::getInstance()->supportProcessControl() ? getmypid() : posix_getpid())) {
            static::$baseProcessId = (Kernel::getInstance()->supportProcessControl() ? getmypid() : posix_getpid());
            Process::getInstance()->distributeForked();
        }
        wait();

        /**
         * 不会再有任何事发生
         *
         * Workerman会将结束的进程视为异常然后重启, 循环往复
         */
        while (1) {
            wait();
            sleep(1);
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
        stop();
    }

    /**
     * @return void
     */
    public static function configure(): void
    {
        static::$baseProcessId  = (Kernel::getInstance()->supportProcessControl() ? getmypid() : posix_getpid());
        Worker::$eventLoopClass = static::class;
    }

    /**
     * @return void
     */
    public static function runAll(): void
    {
        static::configure();
        Worker::runAll();
    }
}
