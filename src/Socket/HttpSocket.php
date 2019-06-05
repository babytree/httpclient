<?php
namespace Babytree\HttpClient\Socket;

use Babytree\HttpClient\Psr\Response;
use Babytree\HttpClient\Psr\Request;
use Exception;

class HttpSocket {

    const PROC_CONNECT = 'connect';
    const PROC_WRITE   = 'write';
    const PROC_READ    = 'read';
    const PROC_WAIT    = 'wait';

    private $hostname = null;
    private $port     = null;

    private $start_connnect_time = null;

    private $end_read_time       = null;

    private $timeout  = 3;

    private $surplus_timeout = 3;

    private $debug = false;

    private $debug_stream = null;

    private $debug_info = [];

    private $use_ssl = false;

    private $use_proxy = false;

    private $context = [];

    private $request = null;

    private $response = null;

    private $socket  = null;

    private $proc_time_stat = [];
    
    public function __construct() {
    
    }

    public function setTimeOut($timeout) {
        $this->timeout         = $timeout;
        $this->surplus_timeout = $this->timeout;
    }

    public function setProxy($proxy) {
        $this->use_proxy = true;
        list ($ip, $port) = explode(':', $proxy);
        $this->hostname = $ip;
        $this->port     = $port;
    }

    public function setDebug($debug_stream = null) {
        $this->debug = true;
        $this->debug_stream = \Babytree\HttpClient\debug_resource($debug_stream);
    }

    public function useSsl() {
        $this->use_ssl = true;

        $this->context['ssl'] = [
            'allow_self_signed' => 0,
            'verify_peer'       => 0,
            'verify_peer_name'  => 0
        ];
        $this->setContext('ssl', 'allow_self_signed', 0);
        $this->setContext('ssl', 'verify_peer', 0);
        $this->setContext('ssl', 'verify_peer_name', 0);
    } 

    public function setContext($wrapper, $option, $value) {
        $this->context[$wrapper][$option] = $value;
    }

    public function setRequest($request) {
        $this->request = $request;
    }

    public function connect() {
        $this->hostname = $this->hostname ?? $this->request->getUri()->getHost();
        $this->port     = $this->port     ?? $this->request->getUri()->getPort(true);
        $this->recordProcTime(self::PROC_CONNECT); 
        $this->debug(self::PROC_CONNECT, sprintf('%s:%d', $this->hostname, $this->port));
        $this->socket = @fsockopen($this->hostname, $this->port, $errno, $errstr, $this->timeout);

        if ($this->socket === false) {
            $this->recordProcTime(self::PROC_CONNECT); 
            $this->debug(self::PROC_CONNECT, 'failed');
            $error_message = sprintf('fsockopen(): unable to connect to %s:%d(%d: %s), %s', $this->hostname, $this->port, $errno, $errstr, $this->getStatTimeRecord());
            throw new Exception($error_message, $errno);
        }

        $this->debug(self::PROC_CONNECT, 'success');

        //https代理需要先建立 client->proxy->server的链接
        if ($this->use_proxy && $this->use_ssl) {
            $message = [
                sprintf('%s %s:%s %s', 'CONNECT', $this->request->getUri()->getHost(), $this->request->getUri()->getPort(true), \Babytree\HttpClient\getHttpVersion($this->request->getProtocolVersion())),
                sprintf('%s: %s', 'Host', implode('; ', $this->request->getHeader('Host'))),
                sprintf('%s: %s', 'User-Agent', implode('; ', $this->request->getHeader('User-Agent'))),
                sprintf('%s: %s', 'Proxy-Connection', 'Keep-Alive'),
                ''
            ];

            $message = implode(Request::EOL, $message);
            fwrite($this->socket, $message);
            $this->debug(self::PROC_WRITE, $message);

            $message = fread($this->socket, 10000);
            $this->debug(self::PROC_READ, $message);
            $response = new Response();
            \Babytree\HttpClient\parseResponseMessage($message, $response, $is_finish);
            if ($response->getStatusCode() != 200) {
                $error_message = sprintf('https connect proxy to server error: proxy:%s:%d, get message: (%s)', $this->hostname, $this->port, trim($message));
                throw new Exception($error_message, 0);
            }
        }

        $this->recordProcTime(self::PROC_CONNECT); 
        //设置环境上下文
        $this->setStreamContextOption();
        return true;
    }

    /**
     * write
     * 
     * @param string $message 
     * @access public
     * @return void
     */
    public function write($message = '') {
        $new_request = clone $this->request;
        if (!$this->use_ssl && $this->use_proxy) {
            $new_request->withHeader('Proxy-Connection', $new_request->getHeader('Connection'));
            $new_request->withoutHeader('Connection');
        }

        $message = (string) $new_request;

        $this->setStreamTimeOut(self::PROC_READ);
        $this->recordProcTime(self::PROC_WRITE); 
        $write_ret = fwrite($this->socket, $message);
        $this->recordProcTime(self::PROC_WRITE); 
        $this->debug(self::PROC_WRITE, $message);
        $this->recordProcTime(self::PROC_WAIT);
        return $write_ret;
    }

    /**
     * read
     * 读 
     * @access public
     * @return void
     */
    public function read() {
        $this->recordProcTime(self::PROC_WAIT);
        $this->setStreamTimeOut(self::PROC_READ);
        $is_timeout = false;
        $response = new Response();
        $this->recordProcTime(self::PROC_READ); 
        $read_no = 1;
        $buffer = '';
        do {
            $message = fread($this->socket, 10000);
            $meta = stream_get_meta_data($this->socket);
            if ($meta['timed_out']) {
                $is_timeout = true;
                break;
            }
            if ($read_no == 1) {
                $buffer .= $message;
                if (false == strpos($buffer, Response::EOL . Response::EOL)) {
                    continue;
                }
                $message = $buffer;
                $buffer = '';
            }
            $read_no++;
            \Babytree\HttpClient\parseResponseMessage($message, $response, $is_finish);
        } while (!$is_finish);
        $this->recordProcTime(self::PROC_READ); 

        $this->close();

        if ($is_timeout) {
            $error_message = __FUNCTION__ . 'timeout ' . $this->getStatTimeRecord(); 
            $e = new Exception($error_message, 0);
            $this->debug(self::PROC_READ, $e);
            throw $e;
        }

        $this->debug(self::PROC_READ, $response);

        return $response;
    }

    public function close() {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
    }

    public function __destruct () {
        $this->close(); 
        $this->saveDebugInfo();
    }

    /**
     * getSocket
     * 
     * @access public
     * @return void
     */
    public function getSocket() {
        return $this->socket;
    }

    /**
     * debug
     * debug信息 
     * @param mixed $message 
     * @param mixed $log 
     * @access protected
     * @return void
     */
    protected function debug($proc, $log) {
        if (!$this->debug) {
            return true;
        } 

        switch ($proc) {
            case self::PROC_WRITE:
                array_push($this->debug_info, date('Y-m-d H:i:s') . "\tsend message:");
                array_push($this->debug_info, $log);
                break;
            case self::PROC_READ:
                array_push($this->debug_info, date('Y-m-d H:i:s') . "\trecv message:");
                array_push($this->debug_info, $log);
                break;
            default:
                array_push($this->debug_info, $proc . ' ' . $log);
                break;
        }
    }

    /**
     * recordProcTime
     * 记录链接、读、写时间 
     * @param mixed $proc 
     * @access protected
     * @return void
     */
    protected function recordProcTime($proc) {
        static $record = [];

        $now_time_line = microtime(true);
        $this->debug('*****record ' . $proc . ' time', $now_time_line);
        if (!isset($record[$proc])) {
            $record[$proc] = $now_time_line;
            if ($proc == self::PROC_CONNECT) {
                $this->start_connnect_time = $now_time_line;
                $this->debug('*****record start time', $this->start_connnect_time);
            }
            return true;
        }

        if ($proc == self::PROC_READ) {
            $this->end_read_time = $now_time_line;
            $this->debug('*****record end time', $this->end_read_time);
        }

        $run_setime = round($now_time_line - $record[$proc], 6);

        unset($record[$proc]);

        $this->proc_time_stat[$proc] += $run_setime;

        $this->surplus_timeout -= $run_setime;
    }

    /**
     * setStreamTimeOut
     * 设置读写超时时间 
     * @param mixed $proc 
     * @access protected
     * @return void
     */
    protected function setStreamTimeOut($proc) {
        $surplus_timeout = $this->getSurplusTimeOut();
        $sec = (int) $surplus_timeout;
        $usec = ((int)($surplus_timeout * 1000000)) % 1000000;
        return stream_set_timeout($this->socket, $sec, $usec);
    }

    /**
     * getSurplusTimeOut
     * 剩余超时时间 
     * @access public
     * @return void
     */
    public function getSurplusTimeOut($force_return_non_negative = true, $force_throw_timeout = false) {
        $surplus = $this->timeout - (microtime(true) - $this->start_connnect_time); 
        if ($surplus <= 0) {
            //强制抛超时异常
            if ($force_throw_timeout) {
                $error_message = __FUNCTION__ . ' timeout ' . $this->getStatTimeRecord(); 
                $e = new Exception($error_message, 0);
                throw $e;
            }

            //强制返回非负数
            if ($force_return_non_negative) {
                $surplus = 0.1;
            }
        }

        return $surplus;
    }

    /**
     * setStreamContextOption
     * 
     * @access protected
     * @return void
     */
    protected function setStreamContextOption() {
        foreach ($this->context as $wrapper => $options) {
            if (!$options || !is_array($options)) {
                continue;
            }

            foreach ($options as $option => $value) {
                stream_context_set_option($this->socket, $wrapper, $option, $value);
            }
        }

        if ($this->use_ssl) {
            stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_ANY_CLIENT);
        }

        return true;
    }

    /**
     * getStatTimeRecord
     * 时间统计 
     * @access protected
     * @return void
     */
    protected function getStatTimeRecord() {
        $log = [];
        $all_time = 0;
        foreach ($this->proc_time_stat as $proc => $time) {
            $log[] = $proc .': ' . $time;
            $all_time += $time;
        }
        if (is_null($this->end_read_time)) {
            $this->end_read_time = microtime(true);
        }
        $log[] = 'all_time: ' . round($this->end_read_time - $this->start_connnect_time, 6);
        $log[] = 'set_timeout: ' . $this->timeout;
        $log[] = 'Uri: ' . (string)$this->request->getUri();

        return implode(', ', $log);
    }

    protected function saveDebugInfo() {
        if (!$this->debug) {
            return true;
        }

        array_unshift($this->debug_info, date('YmdHis') . "\tsocket run time stat\t" . $this->getStatTimeRecord());
        array_unshift($this->debug_info,'=======================================');
        $log = implode(PHP_EOL, $this->debug_info);
        @fwrite($this->debug_stream, $log . PHP_EOL);
        return true;
    }
}
