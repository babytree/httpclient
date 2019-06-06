# PHP异步http/https客户端
绝大部分互联网公司的php-fpm都跑在单进程单线程模式下，随着微服务架构的兴起，普通的curl已经不能满足复杂业务场景的需求。而curl_multi和guzzle尽管支持多个http请求的异步执行，但是它们的api对后端开发同学并不友好。

babytree/httpclient是宝宝树在复杂业务场景下积累的php http客户端。

## 特性
- http请求和业务代码可以异步执行
- 多个http请求可以异步执行
- 满足psr规范
- 比guzzle、curl_multi更友好的api
- 经过了线上复杂业务场景的考验

其中，http请求和业务代码的异步执行，是curl_multi和guzzle所不支持的。使用httpclient，在复杂业务场景下，可以将总体代码运行时间进一步缩短，进而提高QPS。

## 依赖
- php 7.0+
- 如果要进行单元测试，需要安装phpunit和go

## 安装
```sh
composer require babytree/httpclient 1.0.0
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
```php
$options = array(
	//请求超时时间
	RequestOptions::TIMEOUT => 3,
	//debug, $stream不指定时输出到标准设备
	RequestOptions::DEBUG  => $stream,
	//设置header
	RequestOptions::HEADERS => [
	        'timestamp'    => time() * 1000,
	        'signature'    => $signature,
	        'platform'     => 1,
	        'token'        => $meitun_token,
	    ],
	//代理
	RequestOptions::PROXY   => '172.16.99.239:8888',
	//post json格式 默认添加header 'Content-Type', 'application/json;charset=utf-8'
	RequestOptions::JSON => array(
	    'baby_id' => '11111',
	    'baby_name' => '对对对',
	    'baby_gender' => '男',
	    ),
	//post form表单格式 默认添加header 'Content-Type', 'application/x-www-form-urlencoded;charset=UTF-8'
	RequestOptions::FORM_PARAMS => array(
	    'baby_id' => '11111',
	    'baby_name' => '对对对',
	    'baby_gender' => '男',
	    ),
	//post上传文件 默认添加header 'Content-Type', 'multipart/form-data; boundary='
	RequestOptions::MULTIPART => array(
	    'id'        => 1,
	    'user_id'   => 2,
	    'svg_file1' => '/home/baiwei/poster_backgroup.png',
	    'svg_file2' => '/home/baiwei/poster_event.png',
	    ),
);
```

## 范例
### 上传文件
```php
$request_client = new RequestClient();

// 如要要测试，可以使用tests/server.go提供的上传功能来作为测试服务器
$server_url = "http://127.0.0.1:18888/upload";

$options = array(
        RequestOptions::DEBUG  => 1,
        RequestOptions::MULTIPART => array(
            'id'        => 1,
            'user_id'   => 2,
            'file' => '/home/baiwei/poster_backgroup.png',
            ),
        );
$request_uniq = $request_client->addRequest($server_url, $options, RequestClient::MODE_ASYNC);
$ret = $request_client->getResponse($request_uniq);
// 对请求结果进行处理
// ...
```
