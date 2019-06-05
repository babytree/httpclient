<?php
namespace Babytree\HttpClient;

use Babytree\HttpClient\Handler\CurlHandler;
use Babytree\HttpClient\Handler\SocketHandler;
use Babytree\HttpClient\Handler\Handler;
use Babytree\HttpClient\Psr\RequestOptions;
use Babytree\HttpClient\Psr\Request;
use Babytree\HttpClient\Psr\Response;
use Closure, Exception;

/**
 * RequestClient 
 *
 * @package
 * @version $Id$
 * @author
 */
class RequestClient {

    const VSERSION = '2.0.0';
    
    //异步请求
    const MODE_ASYNC = 1;
    //同步请求
    const MODE_SYNC  = 2;

    const PARAM_KEY_URL           = 'url';
    const PARAM_KEY_OPTIONS       = 'options';
    const PARAM_KEY_MODE          = 'mode';
    const PARAM_KEY_RES_FORMAT_FN = 'res_format_func';
    const PARAM_KEY_REQUEST       = 'request';
    const PARAM_KEY_RESPONSE      = 'response';
    const PARAM_KEY_RES_CALLBACK  = 'res_callback';
    const PARAM_KEY_EXCEPTION     = 'exception';

    private $request_list = [];

    public function __construct() {
    }

    /**
     * addRequest 
     * 添加请求 
     * @param string $url 
     * @param array $options 
     * @param int $mode 
     * @param callable $response_format_func 
     * @access public
     * @return string 
     */
    public function addRequest(string $url, array $options = [], int $mode = self::MODE_SYNC, callable $response_format_func = null) {
        $mode = $mode == self::MODE_ASYNC ? $mode == self::MODE_ASYNC : self::MODE_SYNC;
        $request_uniq = \Babytree\HttpClient\getRequestUniq($url, $options, $mode); 

        $this->setRequestListParam($request_uniq, self::PARAM_KEY_URL, $url);
        $this->setRequestListParam($request_uniq, self::PARAM_KEY_OPTIONS, $options);
        $this->setRequestListParam($request_uniq, self::PARAM_KEY_MODE, $mode);

        if (!is_null($response_format_func) && is_callable ($response_format_func)) {
            $this->setRequestListParam($request_uniq, self::PARAM_KEY_RES_FORMAT_FN, $response_format_func);
        }

        $this->sendRequest($request_uniq);
        return $request_uniq;
    }

    /**
     * sendRequest
     * 
     * @param mixed $request_uniq 
     * @access protected
     * @return void
     */
    protected function sendRequest($request_uniq) {
        $url     = $this->getRequestListParam($request_uniq, self::PARAM_KEY_URL);
        $mode    = $this->getRequestListParam($request_uniq, self::PARAM_KEY_MODE);
        $options = $this->getRequestListParam($request_uniq, self::PARAM_KEY_OPTIONS);

        if (isset($options[RequestOptions::DEBUG])) {
            $options[RequestOptions::HEADERS]['request_uniq_id'] = $request_uniq;
        }

        $request = new Request('GET', $url);
        $request->withHeader('Connection', 'Keep-Alive');
        $request->withHeader('User-Agent', 'Mozilla/4.0 (compatible; MSIE 7.0; Windows NT 5.1; .NET CLR 1.0.3705; .NET CLR 1.1.4322)');
        $request->withHeader('Accept-Language', 'zh-Hans;q=1, en-US;q=0.9');
        $request->withHeader('Accept-Encoding', 'gzip, deflate');
        $request->withHeader('Accept', '*/*');

        $handler = $this->getHandler($mode);

        try {
            $request_ret = $handler->startRequest($request, $options);
            $this->setRequestListParam($request_uniq, self::PARAM_KEY_REQUEST, $request);

            if ($request_ret instanceof Closure) {
                $this->setRequestListParam($request_uniq, self::PARAM_KEY_RES_CALLBACK, $request_ret);
            } else if ($request_ret instanceof Response) {
                $this->setRequestListParam($request_uniq, self::PARAM_KEY_RESPONSE, $request_ret);
            } else {
                throw new Exception ();
            }
        } catch (Exception $e) {
            $this->setRequestListParam($request_uniq, self::PARAM_KEY_EXCEPTION, $e);
        }
    }

    /**
     * getResponse 
     * 获取response 
     * @param mixed $request_uniq 
     * @access public
     * @return void
     */
    public function getResponse($request_uniq) {
        if (!$this->existsRequest($request_uniq)) {
            throw new Exception('request not exists');
        }
        $response_callback = $this->getRequestListParam($request_uniq, self::PARAM_KEY_RES_CALLBACK);
        if ($response_callback) {
            try {
                $response = $response_callback();
                if ($response instanceof Response) {
                    $this->setRequestListParam($request_uniq, self::PARAM_KEY_RESPONSE, $response);
                } else {
                    throw new Exception ();
                }
            } catch (Exception $e) {
                $this->setRequestListParam($request_uniq, self::PARAM_KEY_EXCEPTION, $e);
            }
        }

        $response = $this->getRequestListParam($request_uniq, self::PARAM_KEY_RESPONSE);
        if ($response) {
            $body    = (string)$response->getBody();
            $code    = $response->getStatusCode();
            $headers = $response->getHeaders();

            $response_format_func = $this->getRequestListParam($request_uniq, self::PARAM_KEY_RES_FORMAT_FN);
            $this->cleanRequest($request_uniq);
            if ($response_format_func) {
                return call_user_func($response_format_func, $body, $request_uniq);
            }

            return $body;
        }


        $exception = $this->getRequestListParam($request_uniq, self::PARAM_KEY_EXCEPTION);

        $this->cleanRequest($request_uniq);

        if ($exception) {
            throw $exception;
        }

        return false;
    }

    /**
     * selectGetAsyncResponse
     * stream_select 获取有response的 request 
     * @param bool $ret_request_uniq 
     * @param bool $timeout 
     * @access public
     * @return void
     */
    public function selectGetAsyncResponse(&$ret_request_uniq = null, $timeout = null) {
        $socket_response_list = [];
        foreach ($this->request_list as $request_uniq => $data) {
            //非异步
            if ($this->getRequestListParam($request_uniq, self::PARAM_KEY_MODE) != self::MODE_ASYNC) {
                continue;
            }

            $exception = $this->getRequestListParam($request_uniq, self::PARAM_KEY_EXCEPTION);
            if ($exception) {
                $ret_request_uniq = $request_uniq;
                return $this->getResponse($ret_request_uniq);
            }

            $response_callback = $this->getRequestListParam($request_uniq, self::PARAM_KEY_RES_CALLBACK);
            $socket_response_list[$request_uniq] = $response_callback;
        }

        if (!$socket_response_list) {
            return true;
        }

        $ret_request_uniq = SocketHandler::selectSocketRequest($socket_response_list, $timeout);
        if ($ret_request_uniq) {
            return $this->getResponse($ret_request_uniq);
        }

        return false;
    }

    /**
     * getHandler 
     * 获取处理Request Handler 
     * @param mixed $mode 
     * @access protected
     * @return void
     */
    protected function getHandler($mode) {
        static $handlers = [];

        if (!isset($handlers[$mode]) || !($handlers[$mode] instanceof Handler)) {
            switch ($mode) {
                case self::MODE_ASYNC:
                    $handlers[$mode] = new SocketHandler();
                    break;
                case self::MODE_SYNC:
                    $handlers[$mode] = new CurlHandler();
                    break;
            }
        }

        return $handlers[$mode];
    }

    /**
     * existsRequest 
     * 请求是否存在 
     * @param mixed $request_uniq 
     * @access protected
     * @return void
     */
    protected function existsRequest($request_uniq) {
        return isset($this->request_list[$request_uniq]) ? true : false;
    }

    /**
     * cleanRequest
     * 
     * @param mixed $request_uniq 
     * @access protected
     * @return void
     */
    protected function cleanRequest($request_uniq) {
        if (isset($this->request_list[$request_uniq])) {
            unset($this->request_list[$request_uniq]); 
        }
    }

    /**
     * setRequestListParam 
     * 设置属性 
     * @param mixed $request_uniq 
     * @param mixed $param_key 
     * @param mixed $param_value 
     * @access protected
     * @return void
     */
    protected function setRequestListParam($request_uniq, $param_key, $param_value) {
        $this->request_list[$request_uniq][$param_key] = $param_value; 
    }

    /**
     * getRequestListParam 
     * 获取属性值 
     * @param mixed $request_uniq 
     * @param mixed $param_key 
     * @access protected
     * @return void
     */
    protected function getRequestListParam($request_uniq, $param_key) {
        return isset($this->request_list[$request_uniq][$param_key]) ? $this->request_list[$request_uniq][$param_key] : false; 
    }
}
