<?php


namespace CoverCMS\Filesystem\Qiniu;


use CoverCMS\Filesystem\FilesystemInterface;
use Qiniu\Auth;
use Qiniu\Config;
use Qiniu\Http\Error;
use Qiniu\Storage\BucketManager;
use Qiniu\Storage\UploadManager;
use function Qiniu\base64_urlSafeEncode;
use function Qiniu\crc32_data;

/**
 * Class Client
 * @package CoverCMS\Filesystem\Qiniu
 */
class Client implements FilesystemInterface
{
    private $accessKey;

    private $auth;

    private $uploadManger;

    private $bucketManager;


    private $upToken;
    private $size;
    private $params;
    private $mime;
    private $currentUrl;
    private $config;

    public function __construct($accessKey, $secretKey)
    {
        $this->auth = new Auth($accessKey, $secretKey);
        $this->uploadManger = new UploadManager();
        $this->bucketManager = new BucketManager($this->auth);
        $this->config = new Config();
    }

    /**
     * 创建文件夹
     *
     * @param $path
     * @param null $bucket
     */
    public function mkdir($path, $bucket = null)
    {
    }

    /**
     * 移除文件或文件夹
     *
     * @param $paths
     * @param null $bucket
     * @param array $options
     * @throws \Exception
     */
    public function remove($paths, $bucket = null, array $options = [])
    {
        if ($paths instanceof \Traversable) {
            $paths = iterator_to_array($paths, false);
        } elseif (!\is_array($paths)) {
            $paths = [$paths];
        }

        foreach ($paths as $path) {
            list($fileInfo, $err) = $this->bucketManager->stat($bucket, $path);

            if (!$err) {
                $this->bucketManager->delete($bucket, $path);
                continue;
            }

            list($prefix, $path) = explode('/', $path, 2);

            if ($prefix) {
                if (substr($prefix, 0, -1) != '/') {
                    $prefix .= '/';
                }
            }

            list($files, $prefixList) = $this->listFileList($bucket, '', $prefix);

            if ($files) {
                $pages = intval(count($files) / 1000);
                if (count($files) % 1000) {
                    ++$pages;
                }

                for ($i = 1; $i <= $pages; $i++) {
                    $item = array_slice($files, ($i - 1) * 1000, 1000);
                    $ops = $this->bucketManager->buildBatchDelete($bucket, $item);
                    $this->bucketManager->batch($ops);
                }
            }

            if ($prefixList) $this->remove($prefixList, $bucket);
        }
    }

    /**
     * 移动文件
     *
     * @param $fromPath
     * @param $toPath
     * @param null $fromBucket
     * @param null $toBucket
     * @param array $options
     * @throws \Exception
     */
    public function move($fromPath, $toPath, $fromBucket = null, $toBucket = null, array $options = [])
    {
        $this->bucketManager->move($fromBucket, $fromPath, $toBucket, $toPath, true);
    }

    /**
     * 重命名文件或文件夹
     *
     * @param $oldName
     * @param $newName
     * @param null $bucket
     * @param array|null $options
     * @throws \Exception
     */
    public function rename($oldName, $newName, $bucket = null, array $options = null)
    {
        $oldName = (array)$oldName;

        foreach ($oldName as $item) {
            list($fileInfo, $err) = $this->bucketManager->stat($bucket, $item);

            if (!$err) {
                $this->bucketManager->rename($bucket, $item, $newName);
                continue;
            }

            if (substr($item, 0, -1) != '/') {
                $item .= '/';
            }

            list($files, $prefixList) = $this->listFileList($bucket, '', $item);

            $start = strlen($item);

            if ($newName != '' && $newName != '.') {
                if (substr($newName, 0, -1) != '/') {
                    $newName .= '/';
                }
            }

            if ($files) {
                $pages = intval(count($files) / 1000);
                if (count($files) % 1000) {
                    ++$pages;
                }

                for ($i = 1; $i <= $pages; $i++) {
                    $item = array_slice($files, ($i - 1) * 1000, 1000);
                    $keyPairs = [];
                    foreach ($item as $v) {
                        $keyPairs[$v] = $newName . substr($v, 0, $start);
                    }
                    $ops = $this->bucketManager->buildBatchRename($bucket, $keyPairs, true);
                    $this->bucketManager->batch($ops);
                }
            }

            if ($prefixList) $this->rename($prefixList, $newName, $bucket, $options);
        }
    }

    /**
     * 列出所有文件
     *
     * @param $bucket
     * @param string $start
     * @param string $prefix
     * @return array
     * @throws \Exception
     */
    private function listFileList($bucket, $start = '', $prefix = '')
    {
        static $result = [];

        static $prefixList = [];

        list($ret, $err) = $this->bucketManager->listFiles($bucket, $prefix, $start, 1000, '/');

        if ($err !== null) {
            throw new \Exception(sprintf('List file error: "%s"', $err->message()));
        }

        foreach ($ret['items'] as $rt) {
            $result[] = $rt['key'];
        }

        if (isset($ret['commonPrefixes'])) {
            $prefixList = array_merge($prefixList, $ret['commonPrefixes']);
        }

        if (isset($ret['marker'])) {
            $this->listFileList($bucket, $ret['marker'], $prefix);
        }

        return [$result, $prefixList];
    }

    /**
     * 上传单个文件
     *
     * @param $path
     * @param $content
     * @param null $bucket
     * @param null $options
     * @return array
     * @throws \Exception
     */
    public function uploadFile($path, $content, $bucket = null, $options = null)
    {
        $token = $this->auth->uploadToken($bucket);
        if (file_exists($content)) {
            $this->uploadManger->putFile($token, $path, $content, $options);
        }

        return $this->uploadManger->put($bucket, $path, $content, $options);
    }

    /**
     * 初始化分片上传
     *
     * @param $path
     * @param null $bucket
     * @param null $options
     */
    public function initiateMultipartUpload($path, $bucket = null, $options = null)
    {
    }

    /**
     * 上传单个分片
     *
     * @param $path
     * @param $content
     * @param $partNum
     * @param null $uploadId
     * @param null $bucket
     * @param array|null $options
     * @return array|mixed|null
     */
    public function uploadPart($path, $content, $partNum, $uploadId = null, $bucket = null, array $options = null)
    {
        $blockSize = strlen($content);
        $crc = crc32_data($content);
        $response = $this->makeBlock($bucket, $content, $blockSize);
        $ret = null;
        if ($response->ok() && $response->json() != null) {
            $ret = $response->json();
        }

        if ($response->needRetry() || !isset($ret['crc32']) || $crc != $ret['crc32']) {
            $response = $this->makeBlock($bucket, $content, $blockSize);
            $ret = $response->json();
        }

        if (!$response->ok() || !isset($ret['crc32']) || $crc != $ret['crc32']) {
            return array(null, new Error($this->currentUrl, $response));
        }

        return $ret;
    }

    /**
     * 组合分片文件
     *
     * @param $path
     * @param array $uploadParts
     * @param null $uploadId
     * @param null $bucket
     * @return array
     */
    public function mergeMultipartUpload($path, array $uploadParts = [], $uploadId = null, $bucket = null)
    {
        $url = $this->fileUrl($bucket, $path);
        $body = implode(',', $uploadParts);
        $response = $this->post($url, $body);
        if ($response->needRetry()) {
            $response = $this->post($url, $body);
        }
        if (!$response->ok()) {
            return array(null, new Error($this->currentUrl, $response));
        }
        return array($response->json(), null);
    }

    /**
     * 分片上传本地文件
     *
     * @param $path
     * @param $file
     * @param null $bucket
     * @param array|null $options
     * @return array
     * @throws \Exception
     */
    public function multipartUploadFromFile($path, $file, $bucket = null, array $options = null)
    {
        $token = $this->auth->uploadToken($bucket);
        $uploader = new ResumeUploader($token, $path, fopen($file, 'rb'), filesize($file), null, 'application/octet-stream', null);
        return $uploader->upload($path);
    }

    /**
     * 下载文件
     *
     * @param $path
     * @param null $local
     * @param null $bucket
     * @param array|null $options
     */
    public function downloadFile($path, $local = null, $bucket = null, array $options = null)
    {
        return $path;
    }

    /**
     * 复制文件
     *
     * @param $fromPath
     * @param $toPath
     * @param null $fromBucket
     * @param null $toBucket
     * @param array|null $options
     * @return mixed
     */
    public function copyFile($fromPath, $toPath, $fromBucket = null, $toBucket = null, array $options = null)
    {
        return $this->bucketManager->copy($fromBucket, $fromPath, $toBucket, $toPath, true);
    }

    /**
     * 判断文件是否存在
     *
     * @param $path
     * @param null $bucket
     * @param null $options
     * @return bool
     */
    public function fileExist($path, $bucket = null, $options = null)
    {
        list($fileInfo, $err) = $this->bucketManager->stat($bucket, $path);

        return $err === null ? true : false;
    }

    /**
     * 列举文件
     *
     * @param string $prefix
     * @param string $start
     * @param int $size
     * @param null $bucket
     * @return array
     */
    public function listFile($prefix = '', $start = '', $size = 100, $bucket = null)
    {
        list($ret, $err) = $this->bucketManager->listFiles($bucket, $prefix, $start, $size, '/');

        return $ret;
    }

    /**
     * 获取文件元信息
     *
     * @param $path
     * @param null $bucket
     * @param null $options
     * @return mixed
     */
    public function getFileMeta($path, $bucket = null, $options = null)
    {
        list($fileInfo, $err) = $this->bucketManager->stat($bucket, $path);

        return $fileInfo;
    }

    /**
     * 创建块
     *
     * @param $bucket
     * @param $block
     * @param $blockSize
     * @return \Qiniu\Http\Response
     */
    public function makeBlock($bucket, $block, $blockSize)
    {
        $upHost = $this->config->getUpHost($this->accessKey, $bucket);

        $url = $upHost . '/mkblk/' . $blockSize;
        return $this->post($url, $block);
    }

    private function fileUrl($bucket, $path)
    {
        $upHost = $this->config->getUpHost($this->accessKey, $bucket);

        $url = $upHost . '/mkfile/' . $this->size;
        $url .= '/mimeType/' . base64_urlSafeEncode($this->mime);

        $url .= '/fname/' . base64_urlSafeEncode($path);
        if (!empty($this->params)) {
            foreach ($this->params as $key => $value) {
                $val = base64_urlSafeEncode($value);
                $url .= "/$key/$val";
            }
        }

        return $url;
    }

    private function post($url, $data)
    {
        $this->currentUrl = $url;
        $headers = array('Authorization' => 'UpToken ' . $this->upToken);
        return \Qiniu\Http\Client::post($url, $data, $headers);
    }
}