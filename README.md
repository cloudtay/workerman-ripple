<p style="text-align: center">
<img src="assets/logo.png" style="height: 200px;width: 200px;" alt="logo">
</p>

`ripple`协程引擎的`Workerman`版驱动, 使得`Workerman`支持协程编程,
从而可以使用`ripple`协程引擎的各种特性

### 兼容版本

| Workerman版本 | 支持状态 |
|-------------|------|
| 4.1.x       | 长期支持 |
| 5.0.x       | 长期支持 |

### 安装

```shell
composer require cloudtay/workerman-ripple
```

#### Workerman使用方法

```php
use Workerman\Worker;

Worker::$eventLoopClass = Workerman\Ripple\Driver::class;
Worker::runAll();
```

#### Webman使用方法

> 编辑`server.php`

```php
return [
    'event_loop' => Workerman\Ripple\Driver::class,
    // other configurations
];
```

### 使用文档

你可以访问`ripple`的[文档](https://ripple.cloudtay.com/)开始阅读

我们建议你从[手动安装](https://ripple.cloudtay.com/docs/install/professional)开始, 便于更好地理解ripple的工作流程

如果你想快速部署并使用`ripple`的服务, 你可以直接访问[快速部署](https://ripple.cloudtay.com/docs/install/server)

