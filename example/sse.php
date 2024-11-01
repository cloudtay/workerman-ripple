<?php declare(strict_types=1);

use Ripple\Http\Client\Capture\ServerSentEvents;
use Ripple\Http\Guzzle;

use function Co\async;
use function Co\wait;

include __DIR__ . '/../vendor/autoload.php';

$token  = \readline('Please input your token: ');
$header = [
    'Content-Type'    => 'application/json',
    'Authorization'   => "Bearer {$token}",
    'Accept'          => 'text/event-stream',
    'X-DashScope-SSE' => 'enable'
];

$body = [
    'model' => 'qwen-max',
    'input' => [
        'model'    => 'qwen-max',
        'messages' => [
            [
                'role'    => 'system',
                'content' => '你是ripple辅导员',
            ],
            [
                'role'    => 'user',
                'content' => '你好',
            ]
        ]
    ],
];

$capture = new ServerSentEvents();
async(static function () use ($header, $body, $capture) {
    $guzzle = Guzzle::newClient();
    $guzzle->post('https://dashscope.aliyuncs.com/api/v1/services/aigc/text-generation/generation', [
        'headers' => $header,
        'json'    => $body,
        'capture' => $capture
    ]);
});

foreach ($capture->getIterator() as $event) {
    \var_dump($event);
}

echo 'done', \PHP_EOL;
wait();
