<?php
namespace Babytree\HttpClient\Handler;

use Babytree\HttpClient\Socket\HttpSocket;
use Psr\Http\Message\RequestInterface;
use Babytree\HttpClient\Psr\RequestOptions;
use Babytree\HttpClient\Psr\Response;
use Babytree\HttpClient\Psr\Request;
use Closure, Exception;

class SocketHandler extends Handler {

    //获取socket
    const FN_GET_SOCKET = 1;
    //真正剩余超时时间
    const FN_GET_REAL_SURPLUS_TIMEOUT = 2;
    //检查是否超时
    const FN_GET_CHECK_IS_TIMEOUT = 3;


    /**
     * startRequest 
     * 
     * @param Request $request 
     * @param array $options 
     * @access public
     * @return Response 
     */
    public function startRequest(RequestInterface $request, array $options = []) {
        $http_socket = $this->initHttpSocket($request);
        $this->parseOptions($options, $request, $http_socket);
        return $this->socketRequest($http_socket, $request);
    }

    public function selectSocketRequest($socket_response_list, $timeout = null) {
        $tv_usec = null;
        $socket_list = [];
        $socket_request_uniq_list =[];

        $min_surplus_timeout = null; 
        $min_request_uniq    = null;

        foreach ($socket_response_list as $request_uniq => $response_callback) {
            $socket = $response_callback(SocketHandler::FN_GET_SOCKET);
            $surplus_timeout = $response_callback(SocketHandler::FN_GET_REAL_SURPLUS_TIMEOUT);

            //超时的请求
            if ($surplus_timeout <= 0) {
                return $request_uniq;
            }

            if (is_null($min_surplus_timeout) || $surplus_timeout < $min_surplus_timeout) {
                $min_request_uniq = $request_uniq;
                $min_surplus_timeout = $surplus_timeout;
                array_unshift($socket_list, $socket);
            } else {
                array_push($socket_list, $socket);
            }
            $socket_request_uniq_list[$socket] = $request_uniq;
        }

        if (is_null($timeout) || $min_surplus_timeout < $timeout) {
            $timeout = (int) $min_surplus_timeout;
            $tv_usec = ((int)(($min_surplus_timeout) * 1000000)) % 1000000 + 10000;
        }
        $now_time_line = microtime(true);
        if (stream_select($socket_list, $write = NULL, $except = NULL, $timeout, $tv_usec)) {
            return $socket_request_uniq_list[reset($socket_list)];
        }
        $select_time = max(microtime(true) - $now_time_line, 0);

        $min_surplus_timeout = intval(($min_surplus_timeout - $select_time) * 100) / 100;
        if ($min_surplus_timeout <= 0) {
            return $min_request_uniq;
        }

        return false;
    }

    /**
     * initHttpSocket 
     * 初始化httpsocket
     * @param mixed $request 
     * @access private
     * @return void
     */
    private function initHttpSocket($request) {
        $http_socket = new HttpSocket();
        $http_socket->setTimeOut(3);
        if ($request->getUri()->getScheme() == 'https') {
            $http_socket->useSsl();
        }
        return $http_socket;
    } 

    /**
     * getOptionsMethodConfig 
     * 获取options处理方法 
     * @access protected
     * @return void
     */
    protected function getOptionsMethodConfig() {
        list($request, $http_socket) = func_get_args();
        $options_method_config = parent::getOptionsMethodConfig();
        
        $methods = array_flip(get_class_methods(__CLASS__));

        foreach ($options_method_config as $option => $method) {
            if(!isset($methods[$method])) {
                continue;
            }

            $options_method_config[$option] = function ($value) use ($method, $request, $http_socket) {
                $this->$method($value, $request, $http_socket);
                return true;
            };
        }
        return $options_method_config;
    } 

    /**
     * setHttpVersion 
     * 设置Http版本 
     * @param mixed $version 
     * @param mixed $request 
     * @param mixed $conf 
     * @access protected
     * @return void
     */
    protected function setHttpVersion($version, $request, $http_socket) {
        if ($version == '1.1') {
            $request->withProtocolVersion('1.1');
        } elseif ($version == '2.0') {
            $request->withProtocolVersion('2.0');
        } else {
            $request->withProtocolVersion('1.0');
        }
    }

    /**
     * setRequestTimeOut 
     * 设置超时时间 秒 
     * @param mixed $version 
     * @param mixed $request 
     * @param mixed $conf 
     * @access protected
     * @return void
     */
    protected function setRequestTimeOut($timeout, $request, $http_socket) {
        $http_socket->setTimeOut($timeout);
    }

    /**
     * setRequestHeaders
     * 设置Headers 
     * @param mixed $headers 
     * @param mixed $request 
     * @param mixed $conf 
     * @access protected
     * @return void
     */
    protected function setRequestHeaders($headers, $request, $http_socket) {
        if (!is_array($headers)) {
            return true;    
        }

        foreach ($headers as $key => $value) {
             $request->withHeader($key, $value);
        }
    }

    /**
     * setMultiPart
     * 
     * @param mixed $body 
     * @param mixed $request 
     * @param mixed $conf 
     * @access protected
     * @return void
     */
    protected function setMultiPart($body, $request, &$conf) {
        $request->withMethod('POST');
        $boundary = sha1(uniqid('', true));
        $request->withHeader('Content-Type', 'multipart/form-data; boundary=' . $boundary);
        
        $array_body = [];
        foreach ($body as $key => $value) {
            $key   = trim($key);
            $value = trim($value);
            $array_body[] = '--' . $boundary;
            if (file_exists($value)) {
                $array_body[] = sprintf('Content-Disposition: form-data; name="%s"; filename="%s"', $key, $value);
                $array_body[] = 'Content-Type: application/octet-stream';
                $array_body[] = '';
                $array_body[] = file_get_contents($value);
            } else {
                $array_body[] = sprintf('Content-Disposition: form-data; name="%s"', $key);
                $array_body[] = '';
                $array_body[] = $value;
            }
        }
        $array_body[] = '--' . $boundary;
        $array_body[] = '';
        $body = implode(Request::EOL, $array_body);
        $request->withStringBody($body);
    }

    /**
     * setRequestBodyFormatJson 
     * 设置json格式请求body 
     * @param mixed $body 
     * @param mixed $request 
     * @param mixed $conf 
     * @access protected
     * @return void
     */
    protected function setRequestBodyFormatJson($body, $request, $http_socket) {
        $body = json_encode($body);
        $request->withMethod('POST');
        $request->withHeader('Content-Type', 'application/json;charset=utf-8');
        $request->withStringBody($body);
    }

    /**
     * setRequestBodyFormParams 
     * 设置form表单请求body 
     * @param mixed $body 
     * @param mixed $request 
     * @param mixed $conf 
     * @access protected
     * @return void
     */
    protected function setRequestBodyFormParams($body, $request, $http_socket) {
        $body = http_build_query($body);
        $request->withMethod('POST');
        $request->withHeader('Content-Type', 'application/x-www-form-urlencoded;charset=UTF-8');
        $request->withStringBody($body);
    }

    /**
     * setRequestProxy 
     * 设置代理 
     * @param mixed $proxy 
     * @param mixed $request 
     * @param mixed $conf 
     * @access protected
     * @return void
     */
    protected function setRequestProxy($proxy, $request, $http_socket) {
        if ($proxy) {
            $http_socket->setProxy($proxy);
        }
    }

    /**
     * setRequestCookies 
     * 设置cookie 
     * @param mixed $cookies 
     * @param mixed $request 
     * @param mixed $conf 
     * @access protected
     * @return void
     */
    protected function setRequestCookies($cookies, $request, $http_socket) {
        $request->withHeader('Cookie', $cookies);
    }

    /**
     * setRequestDebug 
     * 设置debug 
     * @param mixed $debug 
     * @param mixed $request 
     * @param mixed $conf 
     * @access protected
     * @return void
     */
    protected function setRequestDebug($value, $request, $http_socket) {
        $http_socket->setDebug($value);
    }

    /**
     * socketRequest 
     * 将request转为curl conf 
     * @param mixed $request 
     * @param mixed $conf 
     * @param mixed $response 
     * @access protected
     * @return void
     */
    protected function socketRequest($http_socket, $request) {
        $http_socket->setRequest($request);
        $http_socket->connect();
        $http_socket->write();
        return function ($fn_type = 0) use ($http_socket) {
            switch ($fn_type) {
                case SocketHandler::FN_GET_SOCKET:
                    return $http_socket->getSocket();
                case SocketHandler::FN_GET_REAL_SURPLUS_TIMEOUT:
                    return $http_socket->getSurplusTimeOut(false);
                case SocketHandler::FN_GET_CHECK_IS_TIMEOUT:
                    return $http_socket->getSurplusTimeOut(false, true);
                default:
                    return $http_socket->read();
            }
        };
    }
}
