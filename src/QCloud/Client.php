<?php


namespace CoverCMS\Filesystem\QCloud;

use Qcloud\Cos\Client as CosClient;
use CoverCMS\Filesystem\FilesystemInterface;

/**
 * Class Client
 * @package CoverCMS\Filesystem\QCloud
 */
class Client implements FilesystemInterface
{
    private $config;

    /**
     * @var CosClient
     */
    private $client;

    /**
     * Client constructor.
     * @param array $config
     */
    public function __construct(array $config)
    {
        $this->config = $config;

        $this->client = new CosClient($config);
    }

    /**
     * 创建文件夹
     *
     * @param string $path
     * @param string $bucket
     */
    public function mkdir($path, $bucket = null)
    {
    }

    /**
     * 移除文件或文件夹
     *
     * @param $paths
     * @param string $bucket
     * @param array $options
     * @return void
     */
    public function remove($paths, $bucket = null, array $options = [])
    {
        if ($paths instanceof \Traversable) {
            $paths = iterator_to_array($paths, false);
        } elseif (!\is_array($paths)) {
            $paths = [$paths];
        }

        foreach ($paths as $path) {
            if ($this->client->doesObjectExist($bucket, $path)) {
                $this->client->deleteObject([
                    'Bucket' => $bucket,
                    'Key' => $path
                ]);
                continue;
            }

            list($prefix, $path) = explode('/', $path, 2);

            if ($prefix) {
                if (substr($prefix, 0, -1) != '/') {
                    $prefix .= '/';
                }
            }

            list($files, $prefixList) = $this->listFileList($bucket, '', $prefix);

            if ($files) $this->client->deleteObjects([
                'Bucket' => $bucket,
                'Objects' => $files
            ]);

            if ($prefixList) $this->remove($prefixList, $bucket);
        }
    }

    /**
     * 移动文件
     *
     * @param string $fromPath
     * @param string $toPath
     * @param null $fromBucket
     * @param null $toBucket
     * @param array $options
     * @throws \Exception
     */
    public function move($fromPath, $toPath, $fromBucket = null, $toBucket = null, array $options = [])
    {
        $this->copyFile($fromPath, $toPath, $fromBucket, $toBucket, array_merge([
            'MetadataDirective' => 'Replaced'
        ], $options));

        $this->remove($fromPath, $fromBucket);
    }

    /**
     * 重命名文件或文件夹
     *
     * @param string $oldName
     * @param string $newName
     * @param string $bucket
     * @param array|null $options
     * @throws \Exception
     */
    public function rename($oldName, $newName, $bucket = null, array $options = null)
    {
        $oldName = (array)$oldName;

        foreach ($oldName as $item) {
            if ($this->client->doesObjectExist($bucket, $item)) {
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

                foreach (array_column($files, 'Key') as $file) {
                    $this->move($file, $newName . substr($file, 0, $start), $bucket, $bucket);
                }

                if ($prefixList) $this->rename($prefixList, $newName, $bucket, $options);
            }
        }
    }

    /**
     * 列出所有文件
     *
     * @param string $bucket
     * @param string $start
     * @param string $prefix
     * @return array
     */
    private function listFileList($bucket, $start = '', $prefix = '')
    {
        static $result = [];

        $prefixList = [];

        $objectInfo = $this->client->listObjects([
            'Bucket' => $bucket,
            'Marker' => $start,
            'MaxKeys' => 1000,
            'Prefix' => $prefix
        ]);

        foreach ($objectInfo['Contents'] as $rt) {
            $result[] = [
                'Key' => $rt['Key']
            ];
        }

        if (!$prefixList && $objectInfo['CommonPrefixes']) {
            foreach ($objectInfo['CommonPrefixes'] as $prefixInfo) {
                $prefixList[] = $prefixInfo['Prefix'];
            }
        }

        if ($objectInfo['IsTruncated']) {
            $this->listFileList($bucket, $objectInfo['NextMarker'], $prefix);
        }

        return [$result, $prefixList];
    }

    /**
     * 上传单个文件
     *
     * @param string $path
     * @param string $content
     * @param string $bucket
     * @param null|array $options
     * @return mixed
     */
    public function uploadFile($path, $content, $bucket = null, $options = null)
    {
        if (!is_array($options)) $options = [];
        return $this->client->putObject(array_merge([
            'Bucket' => $bucket, //格式：BucketName-APPID
            'Key' => $path,
            'Body' => $content,
            /*
            'ACL' => 'string',
            'CacheControl' => 'string',
            'ContentDisposition' => 'string',
            'ContentEncoding' => 'string',
            'ContentLanguage' => 'string',
            'ContentLength' => integer,
            'ContentType' => 'string',
            'Expires' => 'string',
            'GrantFullControl' => 'string',
            'GrantRead' => 'string',
            'GrantWrite' => 'string',
            'Metadata' => array(
            'string' => 'string',
            ),
            'ContentMD5' => 'string',
            'ServerSideEncryption' => 'string',
            'StorageClass' => 'string'
            */
        ], $options));
    }

    /**
     * 初始化分片上传
     *
     * @param string $path
     * @param string $bucket
     * @param null|array $options
     * @return mixed
     */
    public function initiateMultipartUpload($path, $bucket = null, $options = null)
    {
        if (!is_array($options)) $options = [];

        try {
            $result = $this->client->createMultipartUpload(array_merge([
                'Bucket' => $bucket, //格式：BucketName-APPID
                'Key' => $path,
                /*
                'CacheControl' => 'string',
                'ContentDisposition' => 'string',
                'ContentEncoding' => 'string',
                'ContentLanguage' => 'string',
                'ContentLength' => integer,
                'ContentType' => 'string',
                'Expires' => 'string',
                'Metadata' => array(
                    'string' => 'string',
                ),
                'StorageClass' => 'string'
                */
            ], $options))->toArray();
        } catch (\Exception $exception) {
        }
        $result = is_array($result) ? $result : [];
        $uploadId = $result['UploadId'] ?? '';
        if (empty($uploadId)) {
            throw new \Exception('Failed to get ID');
        }
//        throw new \InvalidArgumentException($uploadId);

        return $uploadId;
    }

    /**
     * 上传单个分片
     *
     * @param string $path
     * @param string $content
     * @param int $partNum
     * @param string $uploadId
     * @param string $bucket
     * @param array|null $options
     * @return mixed
     */
    public function uploadPart($path, $content, $partNum, $uploadId = null, $bucket = null, array $options = null)
    {
        if (!is_array($options)) $options = [];
        try {
            $result = $this->client->uploadPart(array_merge([
                'Bucket' => $bucket, //格式：BucketName-APPID
                'Key' => $path,
                'Body' => $content,
                'UploadId' => $uploadId, //UploadId 为对象分块上传的 ID，在分块上传初始化的返回参数里获得
                'PartNumber' => $partNum, //PartNumber 为分块的序列号，COS 会根据携带序列号合并分块
                /*
                'ContentMD5' => 'string',
                'ContentLength' => integer,
                */
            ], $options))->toArray();
        } catch (\Exception $exception) {
        }

        if (!isset($result) || !is_array($result) || !isset($result['ETag'])) {
            throw new \Exception('Failed to upload data block');
        }

        return $result;
    }

    /**
     * 组合分片文件
     *
     * @param string $path
     * @param array $uploadParts
     * @param string $uploadId
     * @param string $bucket
     * @param int $size
     * @param string $mimeType
     * @return mixed
     */
    public function mergeMultipartUpload($path, array $uploadParts = [], $uploadId = null, $bucket = null, $size = 0, $mimeType = '')
    {
        try {
            $result = $this->client->completeMultipartUpload([
                'Bucket' => $bucket, //格式：BucketName-APPID
                'Key' => $path,
                'UploadId' => $uploadId,
                'Parts' => $uploadParts,
            ])->toArray();
        } catch (\Exception $exception) {
        }
        if (!isset($result) || !is_array($result)) {
            throw new \Exception('Failed to upload merge data');
        }

        return $result ?? [];
    }

    /**
     * 分片上传本地文件
     *
     * @param string $path
     * @param string $file
     * @param string $bucket
     * @param array|null $options
     * @return mixed
     */
    public function multipartUploadFromFile($path, $file, $bucket = null, array $options = null)
    {
        if (!is_array($options)) $options = [];
        $file = fopen($file, 'rb');
        return $this->client->Upload($bucket, $path, $file, $options);
    }

    /**
     * 下载文件
     *
     * @param string $path
     * @param null|string $local
     * @param string $bucket
     * @param array|null $options
     * @return mixed
     */
    public function downloadFile($path, $local = null, $bucket = null, array $options = null)
    {
        if (!is_array($options)) $options = [];
        $config = array_merge([
            'Bucket' => $bucket,
            'Key' => $path,
            'SaveAs' => $local
        ], $options);

        if (!$config['SaveAs']) unset($config['SaveAs']);

        return $this->client->getObject($config);
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
     * @throws \Exception
     */
    public function copyFile($fromPath, $toPath, $fromBucket = null, $toBucket = null, array $options = null)
    {
        if (!is_array($options)) $options = [];
        $region = $this->config['region'];

        $copySource = $fromBucket . '.cos.' . $region . '.myqcloud.com/' . $fromPath;

        if ($fromBucket == $toBucket) {
            $options['MetadataDirective'] = 'Replaced';
        }

        return $this->client->copy($toBucket, $toPath, $copySource, $options);
    }

    /**
     * 判断文件是否存在
     *
     * @param string $path
     * @param string $bucket
     * @param null|array $options
     * @return bool
     */
    public function fileExist($path, $bucket = null, $options = null)
    {
        if (!is_array($options)) $options = [];
        return $this->client->doesObjectExist($bucket, $path, $options);
    }

    /**
     * 获取文件元信息
     *
     * @param string $path
     * @param string $bucket
     * @param null|array $options
     * @return mixed
     */
    public function getFileMeta($path, $bucket = null, $options = null)
    {
        if (!is_array($options)) $options = [];
        return $this->client->headObject(array_merge(array(
            'Bucket' => $bucket, //格式：BucketName-APPID
            'Key' => $path,
        ), $options));
    }

    /**
     * 列举文件
     *
     * @param string $prefix
     * @param string $start
     * @param int $size
     * @param string $bucket
     * @return mixed
     */
    public function listFile($prefix = '', $start = '', $size = 100, $bucket = null)
    {
        return $this->client->listObjects([
            'Bucket' => $bucket, //格式：BucketName-APPID
            'Delimiter' => '/',
            'Marker' => $start,
            'Prefix' => $prefix,
            'MaxKeys' => $size,
        ]);
    }

    public static function getName()
    {
        return 'qcloud';
    }
}