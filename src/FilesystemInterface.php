<?php


namespace CoverCMS\Filesystem;


/**
 * Interface FilesystemInterface
 * @package CoverCMS\Filesystem
 */
interface FilesystemInterface
{
    public function mkdir($path, $bucket = null);

    public function remove($paths, $bucket = null, array $options = []);

    public function move($fromPath, $toPath, $fromBucket = null, $toBucket = null, array $options = []);

    public function rename($oldName, $newName, $bucket = null, array $options = null);

    public function uploadFile($path, $content, $bucket = null, $options = null);

    public function initiateMultipartUpload($path, $bucket = null, $options = null);

    public function uploadPart($path, $content, $partNum, $uploadId = null, $bucket = null, array $options = null);

    public function mergeMultipartUpload($path, array $uploadParts = [], $uploadId = null, $bucket = null);

    public function multipartUploadFromFile($path, $file, $bucket = null, array $options = null);

    public function downloadFile($path, $local = null, $bucket = null, array $options = null);

    public function copyFile($fromPath, $toPath, $fromBucket = null, $toBucket = null, array $options = null);

    public function fileExist($path, $bucket = null, $options = null);

    public function getFileMeta($path, $bucket = null, $options = null);

    public function listFile($prefix = '', $start = '', $size = 100, $bucket = null);
}