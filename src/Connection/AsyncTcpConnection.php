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
use Ripple\Socket\Tunnel\Http;
use Ripple\Socket\Tunnel\Socks5;
use Ripple\Stream\Exception\ConnectionException;
use Workerman\Connection\TcpConnection;
use Workerman\Worker;

use function call_user_func;
use function intval;
use function is_resource;
use function method_exists;
use function microtime;
use function parse_url;
use function stream_context_create;
use function stream_socket_client;

use const DIRECTORY_SEPARATOR;
use const STREAM_CLIENT_ASYNC_CONNECT;

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
        }

        if ($this->status !== self::STATUS_INITIAL && $this->status !== self::STATUS_CLOSING &&
            $this->status !== self::STATUS_CLOSED) {
            return;
        }

        if (!$this->eventLoop) {
            $this->eventLoop = Worker::$globalEvent;
        }

        $this->status           = self::STATUS_CONNECTING;
        $this->connectStartTime = microtime(true);
        if (!$this->remotePort) {
            $this->remotePort    = $this->transport === 'ssl' ? 443 : 80;
            $this->remoteAddress = $this->remoteHost . ':' . $this->remotePort;
        }
        // Open socket connection asynchronously.
        if ($this->proxySocks5) {
            $this->socketContext['ssl']['peer_name'] = $this->remoteHost;
            $context                                 = stream_context_create($this->socketContext);
            $this->socket                            = stream_socket_client("tcp://$this->proxySocks5", $errno, $err_str, 0, STREAM_CLIENT_ASYNC_CONNECT, $context);
        } elseif ($this->proxyHttp) {
            $this->socketContext['ssl']['peer_name'] = $this->remoteHost;
            $context                                 = stream_context_create($this->socketContext);
            $this->socket                            = stream_socket_client("tcp://$this->proxyHttp", $errno, $err_str, 0, STREAM_CLIENT_ASYNC_CONNECT, $context);
        } elseif ($this->socketContext) {
            $context = stream_context_create($this->socketContext);
            try {
                $this->socket = $this->connectProxy($proxy, $context);
            } catch (ConnectionException $e) {
                $err_str = $e->getMessage();
            }
        } else {
            try {
                $this->socket = $this->connectProxy($proxy);
            } catch (ConnectionException $e) {
                $err_str = $e->getMessage();
            }
        }
        // If failed attempt to emit onError callback.
        if (!$this->socket || !is_resource($this->socket)) {
            $this->emitError(static::CONNECT_FAIL, $err_str);
            if ($this->status === self::STATUS_CLOSING) {
                $this->destroy();
            }
            if ($this->status === self::STATUS_CLOSED) {
                $this->onConnect = null;
            }
            return;
        }
        // Add socket to global event loop waiting connection is successfully established or failed.
        $this->eventLoop->onWritable($this->socket, $this->checkConnection(...));
        // For windows.
        if (DIRECTORY_SEPARATOR === '\\' && method_exists($this->eventLoop, 'onExcept')) {
            $this->eventLoop->onExcept($this->socket, $this->checkConnection(...));
        }
    }

    /**
     * @param string     $proxy
     * @param mixed|null $context
     *
     * @return mixed
     * @throws \Ripple\Stream\Exception\ConnectionException
     */
    protected function connectProxy(string $proxy, mixed $context = null): mixed
    {
        $parse = parse_url($proxy);
        if (!isset($parse['host'], $parse['port'])) {
            throw new ConnectionException('Invalid proxy address', ConnectionException::CONNECTION_ERROR);
        }

        $payload = [
            'host' => $this->remoteHost,
            'port' => intval($this->remotePort)
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
    public function reconnect(int $after = 0): void
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
