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
use Exception;
use GuzzleHttp\Client;
use Iterator;
use Ripple\Http\Guzzle;
use Workerman\Connection\TcpConnection;
use Workerman\Ripple\Connection\AsyncTcpConnection;
use Workerman\Ripple\Protocols\Http\IteratorResponse;

class Utils
{
    /**
     * @param Iterator|Closure $iterator
     * @param TcpConnection    $tcpConnection
     * @param bool             $closeWhenFinish
     * @param bool             $autopilot
     *
     * @return IteratorResponse
     */
    public static function iteratorResponse(
        Iterator|Closure $iterator,
        TcpConnection    $tcpConnection,
        bool             $closeWhenFinish = false,
        bool             $autopilot = true,
    ): IteratorResponse {
        return IteratorResponse::create($iterator, $tcpConnection, $closeWhenFinish, $autopilot);
    }

    /**
     * @param string $remoteAddress
     * @param array  $contextOption
     *
     * @return AsyncTcpConnection
     * @throws Exception
     */
    public static function asyncTcpConnection(
        string $remoteAddress,
        array  $contextOption = []
    ): AsyncTcpConnection {
        return new AsyncTcpConnection($remoteAddress, $contextOption);
    }


    /**
     * @param array $config
     *
     * @return \GuzzleHttp\Client
     */
    public static function guzzle(array $config = []): Client
    {
        return Guzzle::newClient($config);
    }
}
