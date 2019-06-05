# PHP异步http/https客户端
绝大部分互联网公司的php-fpm都跑在单进程单线程模式下，随着微服务架构的兴起，普通的curl已经不能满足复杂业务场景的需求。而curl_multi和guzzle尽管支持多个http请求的异步执行，但是它们的api对后端开发同学并不友好。

babytree-com/httpclient是宝宝树在复杂业务场景下积累的php http客户端。

## 特性
- http请求和业务代码可以异步执行
- 多个http请求可以异步执行
- 满足psr规范
- 比guzzle、curl_multi更友好的api
- 全中文文档
- 线上复杂业务场景下的考验

其中，http请求和业务代码的异步执行，是curl_multi和guzzle所不支持的。使用httpclient，在复杂业务场景下，可以将总体代码运行时间进一步缩短，进而提高QPS。

## 依赖
- php 7.0+
- 如果要进行单元测试，需要安装phpunit和go

## 安装
```sh
composer install babytree-com/httpclient 1.0.0
```

## 使用方法
### 基础用法
```php
use Babytree\HttpClient\Psr\RequestOptions;
use Babytree\HttpClient\RequestClient;

$options = array(
    // some options
);
$request_uniq = $request_client->addRequest($some_api, $options, RequestClient::MODE_ASYNC);
$ret = $request_client->getResponse($request_uniq);

// 对请求结果进行业务操作
// ...

```

### 业务逻辑和http请求异步
```php
use Babytree\HttpClient\Psr\RequestOptions;
use Babytree\HttpClient\RequestClient;

$options = array(
    // some options
);
$request_uniq = $request_client->addRequest($some_api, $options, RequestClient::MODE_ASYNC);

// 这里可以放可以和请求并行处理的业务逻辑 
// some business code

$ret = $request_client->getResponse($request_uniq);

// 对请求结果进行业务操作
// ...
```

### 多个请求异步
```php
use Babytree\HttpClient\Psr\RequestOptions;
use Babytree\HttpClient\RequestClient;

$request_client = new RequestClient();
$options = array(
    // some options
);
$multi_urls = array(
            $api1,
            $api2,
            $api3,
            ...
        );
$request_list = array();
foreach ($multi_urls as $url) {
    $request_uniq = $request_client->addRequest($url, $options, RequestClient::MODE_ASYNC);
    $request_list[$request_uniq] = $request_uniq;
}

// 这里可以放可以和请求并行处理的业务逻辑 
// some business code

do {
    $request_uniq = null;
    try {
        $ret = $request_client->selectGetAsyncResponse($request_uniq, null);
    } catch (\Exception $e) {
    }
    if ($request_uniq && isset($request_list[$request_uniq])) {
        unset($request_list[$request_uniq]);
    }
    if (!$request_list) {
        break;
    }
} while (true);

// 对请求结果进行业务操作
// ...
```

## 运行单元测试
```sh
phpunit tests ./
```

## 选项
TODO: 列出选项，如果有必要，可以写个例子
## 范例
### 上传文件
TODO: 上传文件的例子

