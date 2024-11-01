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

namespace Workerman\Ripple\Connection;

use Closure;
use Co\IO;
use Exception;
use Ripple\Socket\Tunnel\Http;
use Ripple\Socket\Tunnel\Socks5;
use Ripple\Stream\Exception\ConnectionException;
use Workerman\Connection\TcpConnection;
use Workerman\Events\EventInterface;
use Workerman\Worker;

use function call_user_func;
use function microtime;
use function parse_url;

use const DIRECTORY_SEPARATOR;

class AsyncTcpConnection extends \Workerman\Connection\AsyncTcpConnection
{
    private Closure|null $connectionFactory = null;

    /**
     * @param string $proxy
     *
     * @return void
     */
    public function connectViaProxy(string $proxy): void
    {
        if (!$this->connectionFactory) {
            $this->connectionFactory = fn () => $this->connectViaProxy($proxy);
        }

        if ($this->transport === 'unix') {
            $this->connect();
            return;
        }

        if ($this->_status !== self::STATUS_INITIAL && $this->_status !== self::STATUS_CLOSING &&
            $this->_status !== self::STATUS_CLOSED) {
            return;
        }
        $this->_status           = self::STATUS_CONNECTING;
        $this->_connectStartTime = microtime(true);

        /*** Ripple:Overwrite */
        try {
            $this->_socket = $this->connectProxy($proxy);
        } catch (Exception $e) {
            $this->emitError(WORKERMAN_CONNECT_FAIL, $e->getMessage());
            if ($this->_status === self::STATUS_CLOSING) {
                $this->destroy();
            }
            if ($this->_status === self::STATUS_CLOSED) {
                $this->onConnect = null;
            }
            return;
        }
        /** Ripple:Overwrite-end */

        // If failed attempt to emit onError callback.

        // Add socket to global event loop waiting connection is successfully established or faild.
        Worker::$globalEvent->add($this->_socket, EventInterface::EV_WRITE, array($this, 'checkConnection'));
        // For windows.
        if (DIRECTORY_SEPARATOR === '\\') {
            Worker::$globalEvent->add($this->_socket, EventInterface::EV_EXCEPT, array($this, 'checkConnection'));
        }
    }

    /**
     * @param string $proxy
     *
     * @return mixed
     * @throws \Ripple\Stream\Exception\ConnectionException
     */
    protected function connectProxy(string $proxy): mixed
    {
        $parse = parse_url($proxy);
        if (!isset($parse['host'], $parse['port'])) {
            throw new ConnectionException('Invalid proxy address', ConnectionException::CONNECTION_ERROR);
        }

        $payload = [
            'host' => $this->_remoteHost,
            'port' => $this->_remotePort
        ];
        if (isset($parse['user'], $parse['pass'])) {
            $payload['username'] = $parse['user'];
            $payload['password'] = $parse['pass'];
        }

        switch ($parse['scheme']) {
            case 'socks':
            case 'socks5':
                $tunnelSocket = Socks5::connect("tcp://{$parse['host']}:{$parse['port']}", $payload)->getSocketStream();
                break;
            case 'http':
                $tunnelSocket = Http::connect("tcp://{$parse['host']}:{$parse['port']}", $payload)->getSocketStream();
                break;
            case 'https':
                $tunnel       = IO::Socket()->connectWithSSL("tcp://{$parse['host']}:{$parse['port']}");
                $tunnelSocket = Http::connect($tunnel, $payload)->getSocketStream();
                break;
            default:
                throw new ConnectionException('Unsupported proxy protocol', ConnectionException::CONNECTION_ERROR);
        }

        return $tunnelSocket->stream;
    }

    /**
     * @param int $after
     *
     * @return void
     */
    public function reconnect($after = 0): void
    {
        if (!$this->connectionFactory) {
            parent::reconnect($after);
            return;
        }

        $this->status = TcpConnection::STATUS_INITIAL;
        \Co\sleep($after);
        call_user_func($this->connectionFactory);
    }
}
