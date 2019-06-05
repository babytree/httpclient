<?php
namespace Babytree\HttpClient;

use Babytree\HttpClient\Psr\Response;

function getVersion() {
    return RequestClient::VSERSION;
} 

/**
 * parseResponseMessage 
 * response解析 
 * @param mixed $message 
 * @param mixed $response 
 * @param mixed $is_finish 
 * @access public
 * @return void
 */
function parseResponseMessage($message, $response, &$is_finish = false) {
    if (!$response->getHeaders()) {
        $first_line = strstr($message, Response::EOL, true);
        list($version, $status, $reason) = explode(' ', $first_line);
        $response->withStatus($status, $reason);
        $response->withProtocolVersion(parseProtocolVersion($version));

        $message = substr($message, strlen($first_line . Response::EOL));

        list($header, $body) = explode(Response::EOL . Response::EOL, $message, 2);

        $header_array = explode(Response::EOL, $header);

        foreach ($header_array as $_row) {
            list($key, $value) = explode(': ', $_row);
            $response->withHeader($key, $value);
        } 
        

        if (!($response->hasHeader('Transfer-Encoding') || $response->hasHeader('Content-Length'))) {
            $response->withHeader('Content-Length', 0); 
        }
        $message = $body;
    }
    return parseResponseBody($message, $response, $is_finish);
}

/**
 * parseResponseBody 
 * response body解析 
 * @param mixed $body 
 * @param mixed $response 
 * @param mixed $is_finish 
 * @access public
 * @return void
 */
function parseResponseBody($body, $response, &$is_finish = false) {
    $response->buffer .= $body;
    //解析trunk
    if ($response->hasHeader('Transfer-Encoding') && in_array('chunked', $response->getHeader('Transfer-Encoding'))) {
        do {
            if ($response->trunk_length == 0) {
                $_len = strstr($response->buffer, Response::EOL, true);
                if ($_len === false) {
                    return false;
                }
                $length = hexdec($_len);
                if ($length == 0) {
                    decodeResponseBody($response);
                    $is_finish = true;
                    return true;
                }
                $response->trunk_length = $length;
                $response->buffer = substr($response->buffer, strlen($_len . Response::EOL));
            } else {
                //数据量不足，需要等待数据
                if (strlen($response->buffer) < $response->trunk_length) {
                    return true;
                }
                $response->tmp_body .= substr($response->buffer, 0, $response->trunk_length);
                $response->buffer = substr($response->buffer, $response->trunk_length + strlen(Response::EOL));
                $response->trunk_length = 0;
            }
        } while (true);
        return true;
    } else {
        if (strlen($response->buffer) < $response->getHeader('Content-Length')[0]) {
            return true;
        } else {
            $response->tmp_body = $response->buffer;
            decodeResponseBody($response);
            $is_finish = true;
            return true;
        }
    }
}

function decodeResponseBody($response) {
    $header_value = $response->getHeader('Content-Encoding');
    if (is_array($header_value) && $header_value) {
        $header_value = $header_value[0];
    }

    switch ($header_value) {
        case 'gzip':
            $response->tmp_body = gzdecode($response->tmp_body);
            break;
        case 'deflate':
            $response->tmp_body = gzinflate($response->tmp_body);
            break;
        case 'compress':
            $response->tmp_body = gzinflate(substr($response->tmp_body, 2, -4));
            break;
    }
    $response->withStringBody($response->tmp_body);
    $response->tmp_body = $response->buffer = $response->trunk_length = null;
    return true;
}

/**
 * getHttpVersion 
 *  
 * @param mixed $protocol_version 
 * @access public
 * @return void
 */
function getHttpVersion($protocol_version) {
    return sprintf('HTTP/%s', $protocol_version);
}

/**
 * parseProtocolVersion 
 * 
 * @param mixed $http_version 
 * @access public
 * @return void
 */
function parseProtocolVersion($http_version) {
    return substr($http_version, strpos($http_version, '/') + 1);
}

function getRequestUniq($url, $options, $mode) {
    $now_time = microtime(time());
    return date('YmdHis') . ($now_time * 1000000 % 1000000). md5($now_time . rand(100000, 999999) . $url . json_encode($options)) . $mode;
}

function debug_resource($value = null) {
    if (is_resource($value)) {
        return $value;
    } elseif (defined('STDOUT')) {
        return STDOUT;
    }

    return fopen('php://output', 'w');
}

/**
 * set_stream_timeout
 * 设置stream过期时间 
 * @param mixed $socket 
 * @param mixed $timeout 
 * @access public
 * @return void
 */
function set_stream_timeout($socket, $timeout) {
    $sec = (int) $timeout;
    $usec = ((int)($timeout * 1000000)) % 1000000;
    return stream_set_timeout($socket, $sec, $usec);
}

/**
 * Determines the mimetype of a file by looking at its extension.
 *
 * @param $filename
 *
 * @return null|string
 */
function mimetype_from_filename($filename)
{
    return mimetype_from_extension(pathinfo($filename, PATHINFO_EXTENSION));
}

/**
 * Maps a file extensions to a mimetype.
 *
 * @param $extension string The file extension.
 *
 * @return string|null
 * @link http://svn.apache.org/repos/asf/httpd/httpd/branches/1.3.x/conf/mime.types
 */
function mimetype_from_extension($extension)
{
    static $mimetypes = [
        '3gp' => 'video/3gpp',
        '7z' => 'application/x-7z-compressed',
        'aac' => 'audio/x-aac',
        'ai' => 'application/postscript',
        'aif' => 'audio/x-aiff',
        'asc' => 'text/plain',
        'asf' => 'video/x-ms-asf',
        'atom' => 'application/atom+xml',
        'avi' => 'video/x-msvideo',
        'bmp' => 'image/bmp',
        'bz2' => 'application/x-bzip2',
        'cer' => 'application/pkix-cert',
        'crl' => 'application/pkix-crl',
        'crt' => 'application/x-x509-ca-cert',
        'css' => 'text/css',
        'csv' => 'text/csv',
        'cu' => 'application/cu-seeme',
        'deb' => 'application/x-debian-package',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'dvi' => 'application/x-dvi',
        'eot' => 'application/vnd.ms-fontobject',
        'eps' => 'application/postscript',
        'epub' => 'application/epub+zip',
        'etx' => 'text/x-setext',
        'flac' => 'audio/flac',
        'flv' => 'video/x-flv',
        'gif' => 'image/gif',
        'gz' => 'application/gzip',
        'htm' => 'text/html',
        'html' => 'text/html',
        'ico' => 'image/x-icon',
        'ics' => 'text/calendar',
        'ini' => 'text/plain',
        'iso' => 'application/x-iso9660-image',
        'jar' => 'application/java-archive',
        'jpe' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'jpg' => 'image/jpeg',
        'js' => 'text/javascript',
        'json' => 'application/json',
        'latex' => 'application/x-latex',
        'log' => 'text/plain',
        'm4a' => 'audio/mp4',
        'm4v' => 'video/mp4',
        'mid' => 'audio/midi',
        'midi' => 'audio/midi',
        'mov' => 'video/quicktime',
        'mkv' => 'video/x-matroska',
        'mp3' => 'audio/mpeg',
        'mp4' => 'video/mp4',
        'mp4a' => 'audio/mp4',
        'mp4v' => 'video/mp4',
        'mpe' => 'video/mpeg',
        'mpeg' => 'video/mpeg',
        'mpg' => 'video/mpeg',
        'mpg4' => 'video/mp4',
        'oga' => 'audio/ogg',
        'ogg' => 'audio/ogg',
        'ogv' => 'video/ogg',
        'ogx' => 'application/ogg',
        'pbm' => 'image/x-portable-bitmap',
        'pdf' => 'application/pdf',
        'pgm' => 'image/x-portable-graymap',
        'png' => 'image/png',
        'pnm' => 'image/x-portable-anymap',
        'ppm' => 'image/x-portable-pixmap',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'ps' => 'application/postscript',
        'qt' => 'video/quicktime',
        'rar' => 'application/x-rar-compressed',
        'ras' => 'image/x-cmu-raster',
        'rss' => 'application/rss+xml',
        'rtf' => 'application/rtf',
        'sgm' => 'text/sgml',
        'sgml' => 'text/sgml',
        'svg' => 'image/svg+xml',
        'swf' => 'application/x-shockwave-flash',
        'tar' => 'application/x-tar',
        'tif' => 'image/tiff',
        'tiff' => 'image/tiff',
        'torrent' => 'application/x-bittorrent',
        'ttf' => 'application/x-font-ttf',
        'txt' => 'text/plain',
        'wav' => 'audio/x-wav',
        'webm' => 'video/webm',
        'wma' => 'audio/x-ms-wma',
        'wmv' => 'video/x-ms-wmv',
        'woff' => 'application/x-font-woff',
        'wsdl' => 'application/wsdl+xml',
        'xbm' => 'image/x-xbitmap',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'xml' => 'application/xml',
        'xpm' => 'image/x-xpixmap',
        'xwd' => 'image/x-xwindowdump',
        'yaml' => 'text/yaml',
        'yml' => 'text/yaml',
        'zip' => 'application/zip',
    ];

    $extension = strtolower($extension);

    return isset($mimetypes[$extension])
        ? $mimetypes[$extension]
        : null;
}
