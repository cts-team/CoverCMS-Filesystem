<?php


namespace CoverCMS\Filesystem\Qiniu;


use Exception;
use Qiniu\Config;
use Qiniu\Http\Error;
use function Qiniu\base64_urlSafeEncode;
use function Qiniu\crc32_data;
use function Qiniu\explodeUpToken;

/**
 * Class ResumeUploader
 * @package CoverCMS\Filesystem\Qiniu
 */
class ResumeUploader
{
    private $upToken;
    private $key;
    private $inputStream;
    private $size;
    private $params;
    private $mime;
    private $contexts;
    private $host;
    private $currentUrl;
    private $config;

    /**
     * 上传二进制流到七牛
     *
     * @param string $upToken 上传凭证
     * @param string $key 上传文件名
     * @param string $inputStream 上传二进制流
     * @param string $size 上传流的大小
     * @param string $params 自定义变量
     * @param string $mime 上传数据的mimeType
     *
     * @link http://developer.qiniu.com/docs/v6/api/overview/up/response/vars.html#xvar
     */
    public function __construct(
        $upToken,
        $key,
        $inputStream,
        $size,
        $params,
        $mime,
        $config
    )
    {

        $this->upToken = $upToken;
        $this->key = $key;
        $this->inputStream = $inputStream;
        $this->size = $size;
        $this->params = $params;
        $this->mime = $mime;
        $this->contexts = array();
        $this->config = $config;

        list($accessKey, $bucket, $err) = explodeUpToken($upToken);
        if ($err != null) {
            return array(null, $err);
        }

        $upHost = $config->getUpHost($accessKey, $bucket);
        if ($err != null) {
            throw new Exception($err->message(), 1);
        }
        $this->host = $upHost;
    }

    /**
     * 上传操作
     */
    public function upload($fname)
    {
        $uploaded = 0;
        while ($uploaded < $this->size) {
            $blockSize = $this->blockSize($uploaded);
            $data = fread($this->inputStream, $blockSize);
            if ($data === false) {
                throw new Exception("file read failed", 1);
            }
            $crc = crc32_data($data);
            $response = $this->makeBlock($data, $blockSize);
            $ret = null;
            if ($response->ok() && $response->json() != null) {
                $ret = $response->json();
            }
            if ($response->statusCode < 0) {
                list($accessKey, $bucket, $err) = explodeUpToken($this->upToken);
                if ($err != null) {
                    return array(null, $err);
                }

                $upHostBackup = $this->config->getUpBackupHost($accessKey, $bucket);
                $this->host = $upHostBackup;
            }
            if ($response->needRetry() || !isset($ret['crc32']) || $crc != $ret['crc32']) {
                $response = $this->makeBlock($data, $blockSize);
                $ret = $response->json();
            }

            if (!$response->ok() || !isset($ret['crc32']) || $crc != $ret['crc32']) {
                return array(null, new Error($this->currentUrl, $response));
            }
            array_push($this->contexts, $ret['ctx']);
            $uploaded += $blockSize;
        }
        return $this->makeFile($fname);
    }

    /**
     * 创建块
     */
    public function makeBlock($block, $blockSize)
    {
        $url = $this->host . '/mkblk/' . $blockSize;
        return $this->post($url, $block);
    }

    private function fileUrl($fname)
    {
        $url = $this->host . '/mkfile/' . $this->size;
        $url .= '/mimeType/' . base64_urlSafeEncode($this->mime);
        if ($this->key != null) {
            $url .= '/key/' . base64_urlSafeEncode($this->key);
        }
        $url .= '/fname/' . base64_urlSafeEncode($fname);
        if (!empty($this->params)) {
            foreach ($this->params as $key => $value) {
                $val = base64_urlSafeEncode($value);
                $url .= "/$key/$val";
            }
        }
        return $url;
    }

    /**
     * 创建文件
     */
    public function makeFile($fname)
    {
        $url = $this->fileUrl($fname);
        $body = implode(',', $this->contexts);
        $response = $this->post($url, $body);
        if ($response->needRetry()) {
            $response = $this->post($url, $body);
        }
        if (!$response->ok()) {
            return array(null, new Error($this->currentUrl, $response));
        }
        return array($response->json(), null);
    }

    private function post($url, $data)
    {
        $this->currentUrl = $url;
        $headers = array('Authorization' => 'UpToken ' . $this->upToken);
        return \Qiniu\Http\Client::post($url, $data, $headers);
    }

    private function blockSize($uploaded)
    {
        if ($this->size < $uploaded + Config::BLOCK_SIZE) {
            return $this->size - $uploaded;
        }
        return Config::BLOCK_SIZE;
    }
}