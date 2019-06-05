<?php
namespace Babytree\HttpClient\Handler;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Babytree\HttpClient\Psr\RequestOptions;
use Closure;

/**
 * Handler 
 * 处理器抽象类
 */
abstract class Handler {

    /**
     * startRequest 
     * 发起请求 
     * @param RequestInterface $request 
     * @param array $options 
     * @access public
     * @return ResponseInterface 
     */
    abstract public function startRequest(RequestInterface $request, array $options);

    /**
     * parseOptions 
     * 解析Options 
     * @param array $options 
     * @final
     * @access public
     * @return void
     */
    final public function parseOptions(array $options) {
        $methods = array_flip(get_class_methods(__CLASS__));
        
        $args = func_get_args();
        unset($args[0]);
        $options_method_config = call_user_func_array(array($this, 'getOptionsMethodConfig'), $args);

        $ret = true;
        foreach ($options as $option => $value) {
            $method = $options_method_config[$option];

            if (!$method) {
                continue;
            }

            if (is_string($method)) {
                if($methods[$method]) {
                    $this->$method();
                }
                continue;
            }

            if ($method instanceof Closure) {
                $ret = $method($value);
            }
        }

        return $ret;
    }

    /**
     * getOptionsMethodConfig 
     * option对应处理方法 
     * @access protected
     * @return void
     */
    protected function getOptionsMethodConfig() {
        return [
            RequestOptions::VERSION     => 'setHttpVersion',
            RequestOptions::TIMEOUT     => 'setRequestTimeOut',
            RequestOptions::VERIFY      => 'setVerify',
            RequestOptions::SSL_KEY     => 'setRequetSslKey',
            RequestOptions::QUERY       => 'setUriQuery',
            RequestOptions::PROXY       => 'setRequestProxy',
            RequestOptions::MULTIPART   => 'setMultiPart',
            RequestOptions::JSON        => 'setRequestBodyFormatJson',
            RequestOptions::HEADERS     => 'setRequestHeaders',
            RequestOptions::FORM_PARAMS => 'setRequestBodyFormParams',
            RequestOptions::COOKIES     => 'setRequestCookies',
            RequestOptions::DEBUG       => 'setRequestDebug',
            RequestOptions::PROGRESS    => 'setRequestProgress',
            RequestOptions::CERT        => 'setRequestCert',
            //RequestOptions::BODY        => 'setRequestBody',
            RequestOptions::AUTH        => 'setUriAuth',
        ]; 
    }
}
