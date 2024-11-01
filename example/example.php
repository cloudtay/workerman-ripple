<?php declare(strict_types=1);

include __DIR__ . '/../vendor/autoload.php';

use Workerman\Connection\TcpConnection;
use Workerman\Ripple\Driver;
use Workerman\Ripple\Utils;
use Workerman\Worker;

$worker = new Worker('text://127.0.0.1:8001');

$worker->onWorkerStart = static function () {
    $asyncTcpConnection = Utils::asyncTcpConnection('ssl://www.google.com:443');
    $asyncTcpConnection->onConnect = static function (TcpConnection $connection) {
        echo 'Connected to google.com' , \PHP_EOL;
        $connection->send("GET / HTTP/1.1\r\nHost: www.google.com\r\nConnection: close\r\n\r\n");
    };

    $asyncTcpConnection->onMessage = static function (TcpConnection $connection, string $data) {
        echo 'Received data from google.com: ' . \substr($data, 0, 10),'...' . \PHP_EOL;
    };

    $asyncTcpConnection->connectViaProxy('socks5://127.0.0.1:1080');
};

$worker->onMessage = static function (TcpConnection $connection, string $data) {
    switch (\trim($data, "\n\r\t\v\0")) {
        case 'ping':
            \Co\sleep(1);
            $connection->send('pong');
            break;
        case 'curl':
            try {
                $guzzle = Utils::guzzle();
                $response = $guzzle->get('https://www.baidu.com');
                $connection->send('status: ' . $response->getStatusCode());
            } catch (Throwable $e) {
                $connection->send('error: ' . $e->getMessage());
            }
            break;
        default:
            $connection->send('Invalid command');
    }
};

Worker::$eventLoopClass = Driver::class;
Worker::runAll();
