<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PHPUnit\Framework\TestCase;

use Babytree\HttpClient\Psr\Request;
use Babytree\HttpClient\Psr\RequestOptions;
use Babytree\HttpClient\RequestClient;

final class HttpClientTest extends TestCase {

    protected static $server_pid;
    protected static $server_url = "http://127.0.0.1:18888/sleep";

    public static function setUpBeforeClass() {
        echo "编译测试服务器，请稍等5秒\n";
	    $command = sprintf(
            'cd %s && go build -o test_server && %s/test_server >/dev/null 2>&1 & echo $!',
            __DIR__, __DIR__
        );

        $output = array();
        exec($command, $output);
        sleep(5);
        self::$server_pid = (int) $output[0];
    }

    public static function tearDownAfterClass() {
        exec('kill ' . self::$server_pid);
	}

    public function testAsyncRequest() {
        $start_time = microtime(true);
        $request_client = new RequestClient();
        $options = array(
            //RequestOptions::DEBUG  => null,
        );
        $request_uniq = $request_client->addRequest(self::$server_url, $options, RequestClient::MODE_ASYNC);

        // 这里使用sleep模拟业务操作 
        sleep(1);
        $ret = $request_client->getResponse($request_uniq);
        $this->assertEquals("返回值", $ret, "返回结果错误");
        $cost_time = microtime(true) - $start_time;
        //如果是异步的，那么总时间应该是1秒多点
        $this->assertLessThan(1.2, $cost_time, "耗费的时间为{$cost_time}, 超过了1.2秒，异步测试失败");
    }

    public function testAsyncMultiRequest() {
        $start_time = microtime(true);
        $request_client = new RequestClient();
        $options = array(
            //RequestOptions::DEBUG  => null,
        );
        $multi_urls = array(
            self::$server_url,
            self::$server_url,
            self::$server_url,
            self::$server_url,
            self::$server_url,
        );
        $request_list = array();
        foreach ($multi_urls as $url) {
            $request_uniq = $request_client->addRequest(self::$server_url, $options, RequestClient::MODE_ASYNC);
            $request_list[$request_uniq] = $request_uniq;
        }

        // 这里使用sleep模拟业务操作 
        sleep(1);
        
        do {
            $request_uniq = null;
            try {
                $body = $request_client->selectGetAsyncResponse($request_uniq, null);
                $this->assertEquals("返回值", $body, "返回结果错误");
            } catch (\Exception $e) {
                $this->assertTrue(false, sprintf('返回异常 request_uniq:%s, body:%s', $request_uniq, $e->getMessage()) . PHP_EOL);
            }
            if ($request_uniq && isset($request_list[$request_uniq])) {
                unset($request_list[$request_uniq]);
            }
            if (!$request_list) {
                break;
            }
        } while (true);

        $cost_time = microtime(true) - $start_time;
        //如果是异步的，那么总时间应该是1秒多点
        $this->assertLessThan(1.2, $cost_time, "耗费的时间为{$cost_time}, 超过了1.2秒，异步测试失败");
    }

    public function testCurlMulti() {
        $start_time = microtime(true);

        $chs = array();
        
        $multi_urls = array(
            self::$server_url,
            self::$server_url,
            self::$server_url,
            self::$server_url,
            self::$server_url,
        );       

        $mh = curl_multi_init();
        foreach ($multi_urls as $url) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_HEADER, 0);
            $chs[] = $ch;
            curl_multi_add_handle($mh,$ch);
        }

        $active = null;
        do {
            $mrc = curl_multi_exec($mh, $active);
        }
        while ($mrc == CURLM_CALL_MULTI_PERFORM);

        while ($active && $mrc == CURLM_OK) {
            if (curl_multi_select($mh) != -1) {
                do {
                    $mrc = curl_multi_exec($mh, $active);
                } while ($mrc == CURLM_CALL_MULTI_PERFORM);
            }
        }

        // 这里使用sleep模拟业务操作 
        sleep(1);

        foreach ($chs as $ch) {
            $body = curl_multi_getcontent($ch);
            $this->assertEquals("返回值", $body, "返回结果错误");
            curl_multi_remove_handle($mh, $ch);
        }
        curl_multi_close($mh); 

        $cost_time = microtime(true) - $start_time;
        //如果是异步的，那么总时间应该是1秒多点
        $this->assertGreaterThan(2, $cost_time, "耗费的时间为{$cost_time}, 没有超过了2秒，不符合预期");
    }
}
