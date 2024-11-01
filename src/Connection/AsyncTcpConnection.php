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

use Co\IO;
use Exception;
use Ripple\Socket\Tunnel\Http;
use Ripple\Socket\Tunnel\Socks5;
use Ripple\Stream\Exception\ConnectionException;
use Workerman\Events\EventInterface;
use Workerman\Worker;

use function call_user_func;
use function function_exists;
use function method_exists;
use function microtime;
use function parse_url;
use function round;
use function socket_import_stream;
use function socket_set_option;
use function stream_set_blocking;
use function stream_set_read_buffer;
use function stream_socket_get_name;

use const DIRECTORY_SEPARATOR;
use const SO_KEEPALIVE;
use const SOL_SOCKET;
use const SOL_TCP;
use const TCP_NODELAY;

class AsyncTcpConnection extends \Workerman\Connection\AsyncTcpConnection
{
    /**
     * @param string $proxy
     *
     * @return void
     */
    public function connectViaProxy(string $proxy): void
    {
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

        if ($this->transport === 'ssl') {
            $tunnelSocket->enableSSL();
            $this->_sslHandshakeCompleted = true;
        }
        return $tunnelSocket->stream;
    }

    /**
     * @Ripple:Hook
     * @return void
     */
    public function checkConnection(): void
    {
        // Remove EV_EXPECT for windows.
        if (DIRECTORY_SEPARATOR === '\\') {
            Worker::$globalEvent->del($this->_socket, EventInterface::EV_EXCEPT);
        }

        // Remove write listener.
        Worker::$globalEvent->del($this->_socket, EventInterface::EV_WRITE);

        if ($this->_status !== self::STATUS_CONNECTING) {
            return;
        }

        // Check socket state.
        if ($address = stream_socket_get_name($this->_socket, true)) {
            // Nonblocking.
            stream_set_blocking($this->_socket, false);
            // Compatible with hhvm
            if (function_exists('stream_set_read_buffer')) {
                stream_set_read_buffer($this->_socket, 0);
            }
            // Try to open keepalive for tcp and disable Nagle algorithm.
            if (function_exists('socket_import_stream') && $this->transport === 'tcp') {
                $raw_socket = socket_import_stream($this->_socket);
                socket_set_option($raw_socket, SOL_SOCKET, SO_KEEPALIVE, 1);
                socket_set_option($raw_socket, SOL_TCP, TCP_NODELAY, 1);
            }

            /*** Ripple:Overwrite */
            // SSL handshake.
            if ($this->transport === 'ssl' && !$this->_sslHandshakeCompleted) {
                /*** Ripple:Overwrite-end */
                $this->_sslHandshakeCompleted = $this->doSslHandshake($this->_socket);
                if ($this->_sslHandshakeCompleted === false) {
                    return;
                }
            } else {
                // There are some data waiting to send.
                if ($this->_sendBuffer) {
                    Worker::$globalEvent->add($this->_socket, EventInterface::EV_WRITE, array($this, 'baseWrite'));
                }
            }

            // Register a listener waiting read event.
            Worker::$globalEvent->add($this->_socket, EventInterface::EV_READ, array($this, 'baseRead'));

            $this->_status        = self::STATUS_ESTABLISHED;
            $this->_remoteAddress = $address;

            // Try to emit onConnect callback.
            if ($this->onConnect) {
                try {
                    call_user_func($this->onConnect, $this);
                } catch (Exception $e) {
                    Worker::stopAll(250, $e);
                }
            }
            // Try to emit protocol::onConnect
            if ($this->protocol && method_exists($this->protocol, 'onConnect')) {
                try {
                    call_user_func(array($this->protocol, 'onConnect'), $this);
                } catch (Exception $e) {
                    Worker::stopAll(250, $e);
                }
            }
        } else {
            // Connection failed.
            $this->emitError(WORKERMAN_CONNECT_FAIL, 'connect ' . $this->_remoteAddress . ' fail after ' . round(microtime(true) - $this->_connectStartTime, 4) . ' seconds');
            if ($this->_status === self::STATUS_CLOSING) {
                $this->destroy();
            }
            if ($this->_status === self::STATUS_CLOSED) {
                $this->onConnect = null;
            }
        }
    }
}
