<?php declare(strict_types=1);

include __DIR__ . '/../vendor/autoload.php';

use Workerman\Connection\TcpConnection;
use Workerman\Ripple\Driver;
use Workerman\Timer;
use Workerman\Worker;

use function Co\go;

Worker::$eventLoopClass = Driver::class;

$worker = new Worker('tcp://0.0.0.0:8001');
$worker->name = 'RippleTestServer';
$worker->count = 1;

$connectionCount = 0;
$messageCount = 0;

$worker->onWorkerStart = static function (Worker $worker) {
    echo "Worker进程启动: PID=" . \getmypid() . ", ID={$worker->id}\n";

    Timer::add(5, static function () {
        echo "定时器测试: " . \date('Y-m-d H:i:s') . "\n";
        echo "memory_get_usage > " . \memory_get_usage() . "\n";
    });

    Timer::add(10, static function () {
        echo "一次性定时器触发: " . \date('Y-m-d H:i:s') . "\n";
    }, [], false);
};

$worker->onConnect = static function (TcpConnection $connection) use (&$connectionCount) {
    $connectionCount++;
    echo "新连接建立: {$connection->getRemoteIp()}:{$connection->getRemotePort()}\n";
    echo "当前连接数: {$connectionCount}\n";

    $connection->send("发送 'help' 查看可用命令\n");
};

$worker->onMessage = static function (TcpConnection $connection, string $data) use (&$messageCount) {
    $messageCount++;
    $data = \trim($data);

    echo "收到消息: {$data} (来自: {$connection->getRemoteIp()})\n";
    echo "消息计数: {$messageCount}\n";

    switch (\strtolower($data)) {
        case 'help':
            $connection->send("help, time, echo <msg>, sleep <sec>, close\n");
            break;

        case 'time':
            $connection->send("当前时间: " . \date('Y-m-d H:i:s') . "\n");
            break;

        case 'close':
            $connection->send("再见!\n");
            $connection->close();
            break;

        default:
            if (\str_starts_with($data, 'echo ')) {
                $msg = \substr($data, 5);
                $connection->send("回显: {$msg}\n");
            } elseif (\str_starts_with($data, 'sleep ')) {
                $sec = (int)\substr($data, 6);
                if ($sec > 0 && $sec <= 10) {
                    $connection->send("开始睡眠 {$sec} 秒...\n");
                    \Co\sleep($sec);
                    $connection->send("睡眠结束!\n");
                } else {
                    $connection->send("睡眠时间必须在1-10秒之间\n");
                }
            } else {
                go(static function () use ($connection, $data) {
                    \Co\sleep(0.1);
                    $connection->send("异步处理结果: {$data}\n");
                });
            }
            break;
    }
};

$worker->onClose = static function (TcpConnection $connection) use (&$connectionCount) {
    $connectionCount--;
    echo "连接关闭: {$connection->getRemoteIp()}:{$connection->getRemotePort()}\n";
    echo "当前连接数: {$connectionCount}\n";
};

$worker->onError = static function (TcpConnection $connection, int $code, string $msg) {
    echo "连接错误: {$code} - {$msg}\n";
};

$worker->onWorkerStop = static function (Worker $worker) {
    echo "Worker进程停止: PID=" . \getmypid() . "\n";
};

Worker::runAll();
