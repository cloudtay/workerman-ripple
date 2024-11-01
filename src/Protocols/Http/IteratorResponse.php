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

namespace Workerman\Ripple\Protocols\Http;

use Closure;
use Iterator;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Response;

use function array_merge;
use function Co\defer;

/**
 * @Description
 */
class IteratorResponse extends Response
{
    /*** @var \Iterator|mixed */
    protected Iterator $iterator;

    /**
     * @param Iterator|Closure $iterator      迭代器
     * @param TcpConnection    $tcpConnection TCP连接
     * @param bool             $autopilot     自动驾驶
     */
    public function __construct(
        Iterator|Closure                 $iterator,
        protected readonly TcpConnection $tcpConnection,
        protected readonly bool          $closeWhenFinish = false,
        protected readonly bool          $autopilot = true,
    ) {
        if ($iterator instanceof Closure) {
            $iterator = $iterator();
        }
        $this->iterator = $iterator;
        if ($autopilot) {
            defer(fn () => $this->processIterator());
        }
        parent::__construct(200, array_merge([], []));
    }

    /**
     * @return $this
     */
    public function processIterator(): static
    {
        foreach ($this->iterator as $frame) {
            $this->tcpConnection->send($frame, true);
        }

        if ($this->closeWhenFinish) {
            $this->close();
        }

        return $this;
    }

    /**
     * @return void
     */
    public function close(): void
    {
        $this->tcpConnection->close();
    }

    /**
     * @param Iterator|Closure $iterator
     * @param TcpConnection    $tcpConnection
     * @param bool             $closeWhenFinish
     * @param bool             $autopilot
     *
     * @return IteratorResponse
     */
    public static function create(
        Iterator|Closure $iterator,
        TcpConnection    $tcpConnection,
        bool             $closeWhenFinish = false,
        bool             $autopilot = true,
    ): IteratorResponse {
        return new static($iterator, $tcpConnection, $closeWhenFinish, $autopilot);
    }
}
