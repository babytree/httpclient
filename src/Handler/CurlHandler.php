<?php
namespace Babytree\HttpClient\Handler;

use Psr\Http\Message\RequestInterface;
use Babytree\HttpClient\Psr\RequestOptions;
use Babytree\HttpClient\Psr\Response;
use Babytree\HttpClient\Psr\Request;
use Closure, Exception;

class CurlHandler extends Handler {
    
    /**
     * startRequest 
     * 
     * @param Request $request 
     * @param array $options 
     * @access public
     * @return Respons 
     */
    public function startRequest(RequestInterface $request, array $options = []) {
        $conf     = $this->getDefaultConf($request);
        $conf_ret = $this->parseOptions($options, $request, $conf);
        if (is_array($conf_ret)) {
            $conf = $conf_ret;
        }
        $response = new Response();
        $this->parseRequestToConf($request, $conf, $response);

        $this->startCurl($conf, $errno, $error);

        //处理异常情况
        if ($errno) {
            $error_message = sprintf("%s(error code: %d) url:%s", $error, $errno, $request->getUri());
            throw new Exception($error_message, $errno);
        }

        \Babytree\HttpClient\decodeResponseBody($response);

        return $response;
    }

    /**
     * startCurl 
     * curl请求 
     * @param mixed $conf 
     * @access private
     * @return void
     */
    private function startCurl($conf, &$errno, &$error) {
        $ch = curl_init();
        curl_setopt_array($ch, $conf);
        curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);
    }

    /**
     * getDefaultConf 
     * 默认curl conf 
     * @param mixed $request 
     * @access private
     * @return void
     */
    private function getDefaultConf($request) {
        $conf = [
            CURLOPT_RETURNTRANSFER => 0,
            CURLOPT_HEADER         => 0,
            CURLOPT_CONNECTTIMEOUT => 150,
            CURLOPT_TIMEOUT        => 3
        ];
        return $conf;
    } 

    /**
     * getOptionsMethodConfig 
     * 获取options处理方法 
     * @access protected
     * @return void
     */
    protected function getOptionsMethodConfig() {
        list($request, $conf) = func_get_args();
        $options_method_config = parent::getOptionsMethodConfig();
        
        $methods = array_flip(get_class_methods(__CLASS__));

        foreach ($options_method_config as $option => $method) {
            if(!isset($methods[$method])) {
                continue;
            }

            $options_method_config[$option] = function ($value) use ($method, $request, &$conf) {
                $this->$method($value, $request, $conf);
                return $conf;
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
    protected function setHttpVersion($version, $request, &$conf) {
        if ($version == '1.1') {
            $request->withProtocolVersion('1.1');
        } elseif ($version == '2.0') {
            $request->withProtocolVersion('2.0');
        } else {
            $request->withProtocolVersion('1.0');
        }
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
    protected function setRequestHeaders($headers, $request, &$conf) {
        if (!is_array($headers)) {
            return true;    
        }

        foreach ($headers as $key => $value) {
             $request->withHeader($key, $value);
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
    protected function setRequestTimeOut($timeout, $request, &$conf) {
        $conf[CURLOPT_TIMEOUT] = $timeout;
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
        
        foreach ($body as $key => $value) {
            if (file_exists($value)) {
                $body[$key] = new \CURLFile($value);
            }
        }
        $conf[CURLOPT_POSTFIELDS] = $body;
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
    protected function setRequestBodyFormatJson($body, $request, &$conf) {
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
    protected function setRequestBodyFormParams($body, $request, &$conf) {
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
    protected function setRequestProxy($proxy, $request, &$conf) {
        $conf[CURLOPT_PROXY] = $proxy; 
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
    protected function setRequestCookies($cookies, $request, &$conf) {
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
    protected function setRequestDebug($value, $request, &$conf) {
        $conf[CURLOPT_STDERR] = \Babytree\HttpClient\debug_resource($value);
        $conf[CURLOPT_VERBOSE] = true;
    }

    /**
     * parseRequestToConf 
     * 将request转为curl conf 
     * @param mixed $request 
     * @param mixed $conf 
     * @param mixed $response 
     * @access protected
     * @return void
     */
    protected function parseRequestToConf($request, &$conf, $response) {
        $conf[CURLOPT_CUSTOMREQUEST] = $request->getMethod();
        $conf[CURLOPT_URL]           = (string) $request->getUri()->withFragment('');
        foreach ($request->getHeaders() as $key => $value) {
            $value = implode('; ', $value);
            $conf[CURLOPT_HTTPHEADER][] = sprintf('%s: %s', $key, $value);
        }

        $set_hearder_times = 0;
        $conf[CURLOPT_HEADERFUNCTION] = function ($ch, $row) use ($response, &$set_hearder_times) {
            $set_hearder_times++;
            return $this->getResponseHeaderFunc($ch, $row, $response, $set_hearder_times);
        };

        $conf[CURLOPT_WRITEFUNCTION] = function ($ch, $tmp_body) use ($response){
            return $this->getResponseBodyFunc($ch, $tmp_body, $response);
        };

        if (($size = $request->getBody()->getSize()) > 0) {
            $conf[CURLOPT_POSTFIELDS] = $request->getBody()->read($size);
        }

        $version = $request->getProtocolVersion();
        if ($version == '1.1') {
            $conf[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_1_1;
        } elseif ($version == '2.0') {
            $conf[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_2_0;
        } else {
            $conf[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_1_0;
        }
    }

    /**
     * getResponseHeaderFunc 
     * response header 回调 
     * @param mixed $ch 
     * @param mixed $h 
     * @param mixed $response 
     * @access public
     * @return void
     */
    public function getResponseHeaderFunc($ch, $row, $response, &$set_hearder_times) {
        $format_row = trim($row);

        if ($format_row) {
            if ($set_hearder_times == 1) {
                 list($version, $status, $reason) = explode(' ', $format_row);
                 $response->withStatus($status, $reason);
                 $response->withProtocolVersion(\Babytree\HttpClient\parseProtocolVersion($version));
            } else {
                list($key, $value) = explode(': ', $format_row);
                $response->withHeader($key, $value);
            }
        }
        if (!$format_row) {
            $set_hearder_times = 0;
        }
        return strlen($row);
    }

    /**
     * getResponseBodyFunc 
     * response body回调 
     * @param mixed $ch 
     * @param mixed $b 
     * @param mixed $response 
     * @access public
     * @return void
     */
    public function getResponseBodyFunc($ch, $tmp_body, $response) {
        $response->tmp_body .= $tmp_body; 
        return strlen($tmp_body) ;
    }
}
