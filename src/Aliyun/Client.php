<?php


namespace CoverCMS\Filesystem\Aliyun;


use CoverCMS\Filesystem\FilesystemInterface;
use OSS\Core\OssException;
use OSS\Core\OssUtil;

/**
 * Class Client
 * @package CoverCMS\Filesystem\Aliyun
 */
class Client implements FilesystemInterface
{
    /**
     * @var OssClient
     */
    private $ossClient;

    /**
     * Client constructor.
     * @param string $accessKeyId
     * @param string $accessKeySecret
     * @param string $endpoint
     * @param bool $isCName
     * @param null $securityToken
     * @param null $requestProxy
     * @throws OssException
     */
    public function __construct($accessKeyId, $accessKeySecret, $endpoint, $isCName = false, $securityToken = NULL, $requestProxy = NULL)
    {
        $this->ossClient = new OssClient($accessKeyId, $accessKeySecret, $endpoint, $isCName, $securityToken, $requestProxy);
    }

    /**
     * 创建文件夹
     *
     * @param string $path
     * @param null $bucket
     * @return void
     */
    public function mkdir($path, $bucket = null)
    {
    }

    /**
     * 移除文件或文件夹
     *
     * @param array|string $paths
     * @param string $bucket
     * @param array $options
     * @throws OssException
     */
    public function remove($paths, $bucket = null, array $options = [])
    {
        if ($paths instanceof \Traversable) {
            $paths = iterator_to_array($paths, false);
        } elseif (!\is_array($paths)) {
            $paths = [$paths];
        }

        foreach ($paths as $path) {
            if ($this->ossClient->doesObjectExist($bucket, $path)) {
                $this->ossClient->deleteObject($bucket, $path);
                continue;
            }

            list($prefix, $path) = explode('/', $path, 2);

            if ($prefix) {
                if (substr($prefix, 0, -1) != '/') {
                    $prefix .= '/';
                }
            }

            list($files, $prefixList) = $this->listFileList($bucket, '', $prefix);

            if ($files) $this->ossClient->deleteObjects($bucket, $files);

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
     * @throws OssException
     */
    public function move($fromPath, $toPath, $fromBucket = null, $toBucket = null, array $options = [])
    {
        $this->copyFile($fromPath, $toPath, $fromBucket, $toBucket);
        $this->remove($fromPath, $fromBucket);
    }

    /**
     * 重命名文件或文件夹
     *
     * @param $oldName
     * @param $newName
     * @param null $bucket
     * @param array|null $options
     * @throws OssException
     */
    public function rename($oldName, $newName, $bucket = null, array $options = null)
    {
        $oldName = (array)$oldName;

        foreach ($oldName as $item) {
            if ($this->ossClient->doesObjectExist($bucket, $item)) {
                $this->move($item, $newName, $bucket, $bucket);
            } else {
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

                foreach ($files as $file) {
                    $this->move($file, $newName . substr($file, 0, $start), $bucket, $bucket);
                }

                if ($prefixList) $this->rename($prefixList, $newName, $bucket, $options);
            }
        }
    }

    /**
     * 列出所有文件
     *
     * @param $bucket
     * @param string $start
     * @param string $prefix
     * @return array
     * @throws OssException
     */
    private function listFileList($bucket, $start = '', $prefix = '')
    {
        static $result = [];

        $prefixList = [];

        $objectInfo = $this->ossClient->listObjects($bucket, [
            'delimiter' => '/',
            'marker' => $start,
            'max-keys' => 100,
            'prefix' => $prefix
        ]);

        foreach ($objectInfo->getObjectList() as $item) {
            $result[] = $item->getKey();
        }

        if (!$prefixList && $objectInfo->getPrefixList()) {
            foreach ($objectInfo->getPrefixList() as $prefixInfo) {
                $prefixList[] = $prefixInfo->getPrefix();
            }
        }

        if (strtolower($objectInfo->getIsTruncated()) != 'false') {
            $this->listFileList($bucket, $objectInfo->getNextMarker(), $prefix);
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
     * @return null
     * @throws OssException
     */
    public function uploadFile($path, $content, $bucket = null, $options = null)
    {
        if (file_exists($content)) {
            return $this->ossClient->uploadFile($bucket, $path, $content, $options);
        }

        return $this->ossClient->putObject($bucket, $path, $content, $options);
    }

    /**
     * 初始化分片上传
     *
     * @param $path
     * @param null $bucket
     * @param null $options
     * @return string
     * @throws OssException
     */
    public function initiateMultipartUpload($path, $bucket = null, $options = null)
    {
        return $this->ossClient->initiateMultipartUpload($bucket, $path, $options);
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
     * @return string
     * @throws OssException
     */
    public function uploadPart($path, $content, $partNum, $uploadId = null, $bucket = null, array $options = null)
    {
        $ossClient = $this->ossClient;
        $options = is_array($options) ? $options : [];
        $options = array_merge([
            $ossClient::OSS_CONTENT => $content,
            $ossClient::OSS_PART_NUM => $partNum,
        ], $options);

        return $ossClient->uploadPart($bucket, $path, $uploadId, $options);
    }

    /**
     * 组合分片文件
     *
     * @param $path
     * @param array $uploadParts
     * @param null $uploadId
     * @param null $bucket
     * @param int $size
     * @param string $mimeType
     * @return null
     * @throws OssException
     */
    public function mergeMultipartUpload($path, array $uploadParts = [], $uploadId = null, $bucket = null, $size = 0, $mimeType = '')
    {
        return $this->ossClient->completeMultipartUpload($bucket, $path, $uploadId, $uploadParts);
    }

    /**
     * 分片上传本地文件
     *
     * @param $path
     * @param $file
     * @param null $bucket
     * @param array|null $options
     * @return mixed
     * @throws OssException
     */
    public function multipartUploadFromFile($path, $file, $bucket = null, array $options = null)
    {
        $uploadId = $this->ossClient->initiateMultipartUpload($bucket, $path, $options);

        $uploadFileSize = filesize($file);
        $pieces = $this->ossClient->generateMultiuploadParts($uploadFileSize);
        $responseUploadPart = [];
        $uploadPosition = 0;
        $isCheckMd5 = true;
        $ossClient = $this->ossClient;

        foreach ($pieces as $i => $piece) {
            $fromPos = $uploadPosition + (integer)$piece[$ossClient::OSS_SEEK_TO];
            $toPos = (integer)$piece[$ossClient::OSS_LENGTH] + $fromPos - 1;
            $upOptions = array(
                $ossClient::OSS_FILE_UPLOAD => $file,
                $ossClient::OSS_PART_NUM => ($i + 1),
                $ossClient::OSS_SEEK_TO => $fromPos,
                $ossClient::OSS_LENGTH => $toPos - $fromPos + 1,
                $ossClient::OSS_CHECK_MD5 => $isCheckMd5,
            );
            // MD5校验。
            if ($isCheckMd5) {
                $contentMd5 = OssUtil::getMd5SumForFile($file, $fromPos, $toPos);
                $upOptions[$ossClient::OSS_CONTENT_MD5] = $contentMd5;
            }

            $responseUploadPart[] = $ossClient->uploadPart($bucket, $path, $uploadId, $upOptions);
        }

        // $uploadParts是由每个分片的ETag和分片号（PartNumber）组成的数组。
        $uploadParts = [];
        foreach ($responseUploadPart as $i => $eTag) {
            $uploadParts[] = array(
                'PartNumber' => ($i + 1),
                'ETag' => $eTag,
            );
        }

        return $this->mergeMultipartUpload($path, $uploadParts, $uploadId, $bucket);
    }

    /**
     * 下载文件
     *
     * @param $path
     * @param null $local
     * @param null $bucket
     * @param array|null $options
     * @return string
     */
    public function downloadFile($path, $local = null, $bucket = null, array $options = null)
    {
        if (!is_null($local)) {
            if (!isset($options[OssClient::OSS_FILE_DOWNLOAD])) {
                $options[OssClient::OSS_FILE_DOWNLOAD] = $local;
            }
        }

        return $this->ossClient->getObject($bucket, $path, $options);
    }

    /**
     * 复制文件
     *
     * @param $fromPath
     * @param $toPath
     * @param null $fromBucket
     * @param null $toBucket
     * @param array|null $options
     * @return mixed|null
     * @throws OssException
     */
    public function copyFile($fromPath, $toPath, $fromBucket = null, $toBucket = null, array $options = null)
    {
        $object = $this->getFileMeta($fromPath, $fromBucket);
        $size = $object['content-length'];
        if ($size > 1024 * 1024 * 1024) {
            $chunkSize = 10 * 1024 * 1024;
            $parts = intval($size / $chunkSize);
            if ($size % $chunkSize) ++$parts;

            return $this->copyMultipartFile($parts, $fromBucket, $fromPath, $toBucket, $toPath, $options);
        }

        return $this->ossClient->copyObject($fromBucket, $fromPath, $toBucket, $toPath, $options);
    }

    /**
     * 超大文件复制
     *
     * @param $parts
     * @param $fromBucket
     * @param $fromPath
     * @param $toBucket
     * @param $toPath
     * @param null $options
     * @return mixed
     * @throws OssException
     */
    private function copyMultipartFile($parts, $fromBucket, $fromPath, $toBucket, $toPath, $options = null)
    {
        // 初始化分片。
        $upload_id = $this->ossClient->initiateMultipartUpload($toBucket, $toPath);

        $upload_parts = [];

        for ($i = 1; $i <= $parts; $i++) {
            // 逐个分片拷贝。
            $eTag = $this->ossClient->uploadPartCopy($fromBucket, $fromPath, $toBucket, $toPath, $i, $upload_id);
            $upload_parts[] = array(
                'PartNumber' => $i,
                'ETag' => $eTag,
            );
        }

        return $this->ossClient->completeMultipartUpload($toBucket, $toPath, $upload_id, $upload_parts, $options);
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
        return $this->ossClient->doesObjectExist($bucket, $path, $options);
    }

    /**
     * 获取文件元信息
     *
     * @param $path
     * @param null $bucket
     * @param null $options
     * @return array
     */
    public function getFileMeta($path, $bucket = null, $options = null)
    {
        return $this->ossClient->getObjectMeta($bucket, $path, $options);
    }

    /**
     * 列举文件
     *
     * @param string $prefix
     * @param string $start
     * @param int $size
     * @param null $bucket
     * @return \OSS\Model\ObjectListInfo
     * @throws OssException
     */
    public function listFile($prefix = '', $start = '', $size = 100, $bucket = null)
    {
        $options = [
            'delimiter' => '/',
            'marker' => $start,
            'max-keys' => $size,
            'prefix' => $prefix
        ];

        $listObjectInfo = $this->ossClient->listObjects($bucket, $options);

        return $listObjectInfo;
    }

    public static function getName()
    {
        return 'aliyun';
    }
}