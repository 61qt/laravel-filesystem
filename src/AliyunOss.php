<?php

namespace QT\Filesystem;

use OSS\OssClient;
use OSS\Core\OssException;
use InvalidArgumentException;
use Illuminate\Contracts\Filesystem\Cloud;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Filesystem\FileNotFoundException;

class AliyunOss implements Cloud
{
    /**
     * Inherit bucket visibility settings.
     *
     * @var string
     */
    const VISIBILITY_DEFAULT = 'default';

    /**
     * The public read visibility setting.
     *
     * @var string
     */
    const VISIBILITY_PUBLIC_READ = 'public-read';

    /**
     * The public visibility setting.
     *
     * @var string
     */
    const VISIBILITY_PUBLIC_READ_WRITE = 'public-read-write';

    /**
     * @var OssClient $client
     */
    protected $client;

    /**
     * @var string
     */
    protected $bucket;

    /**
     * @var array
     */
    protected $config;

    /**
     * @var array
     */
    protected $errors = [];

    /**
     * @param OssClient $client
     * @param string $bucket
     * @param array $config
     */
    public function __construct(OssClient $client, string $bucket, array $config = [])
    {
        if (empty($config[OssClient::OSS_MAX_KEYS])) {
            $config[OssClient::OSS_MAX_KEYS] = OssClient::OSS_MAX_KEYS_VALUE;
        }

        if (empty($config['access_timeout'])) {
            $config['access_timeout'] = 60;
        }

        $this->config = $config;
        $this->bucket = $bucket;

        $this->setClient($client);
    }

    /**
     * @return OssClient
     */
    public function getClient(): OssClient
    {
        return $this->client;
    }

    /**
     * @param OssClient $client
     */
    public function setClient(OssClient $client)
    {
        return $this->client = $client;
    }

    /**
     * Set Aliyun Oss bucket.
     *
     * @return string
     */
    public function setBucket(string $bucket)
    {
        return $this->bucket = $bucket;
    }

    /**
     * Get the Aliyun Oss bucket.
     *
     * @return string
     */
    public function getBucket()
    {
        return $this->bucket;
    }

    /**
     * Get the URL for the file at the given path.
     *
     * @param  string  $path
     * @return string
     */
    public function url($path)
    {
        try {
            return $this->getClient()->signUrl(
                $this->bucket, 
                $path, 
                $this->config['access_timeout']
            );
        } catch (OssException $e) {
            $this->errors[] = $e;

            return false;
        }
    }

    /**
     * Determine if a file exists.
     *
     * @param  string  $path
     * @return bool
     */
    public function exists($path)
    {
        try {
            return $this->getClient()->doesObjectExist($this->bucket, $path);
        } catch (OssException $e) {
            $this->errors[] = $e;

            return false;
        }
    }

    /**
     * Get the contents of a file.
     *
     * @param  string  $path
     * @return string
     *
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function get($path)
    {
        try {
            return $this->getClient()->getObject($this->bucket, $path);
        } catch (OssException $e) {
            throw new FileNotFoundException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Get a resource to read the file.
     *
     * @param  string  $path
     * @return resource|null The path resource or null on failure.
     *
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function readStream($path)
    {
        try {
            $stream = fopen('php://temp', 'w+');

            $this->getClient()->getObject($this->bucket, $path, [
                OssClient::OSS_FILE_DOWNLOAD => $stream,
            ]);

            rewind($stream);

            return $stream;
        } catch (OssException $e) {
            throw new FileNotFoundException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Write the contents of a file.
     *
     * @param  string  $path
     * @param  string|resource  $contents
     * @param  mixed  $options
     * @return bool
     */
    public function put($path, $contents, $options = [])
    {
        try {
            $this->getClient()->putObject($this->bucket, $path, $contents, $options);

            return true;
        } catch (OssException $e) {
            $this->errors[] = $e;

            return false;
        }
    }

    /**
     * Write a new file using a stream.
     *
     * @param  string  $path
     * @param  resource  $resource
     * @param  array  $options
     * @return bool
     *
     * @throws \InvalidArgumentException If $resource is not a file handle.
     * @throws \Illuminate\Contracts\Filesystem\FileExistsException
     */
    public function writeStream($path, $resource, array $options = [])
    {
        if (!is_resource($resource)) {
            throw new InvalidArgumentException('resource is not a file handle');
        }

        try {
            $this->getClient()->uploadStream($this->bucket, $path, $resource, $options);

            return true;
        } catch (OssException $e) {
            $this->errors[] = $e;

            return false;
        }
    }

    /**
     * Get the visibility for the given path.
     *
     * @param  string  $path
     * @return string
     */
    public function getVisibility($path)
    {
        try {
            $visibility = $this->getClient()->getObjectAcl($this->bucket, $path);

            if ($visibility === self::VISIBILITY_PUBLIC_READ_WRITE) {
                $visibility = Filesystem::VISIBILITY_PUBLIC;
            }

            return $visibility;
        } catch (OssException $e) {
            $this->errors[] = $e;

            return self::VISIBILITY_DEFAULT;
        }
    }

    /**
     * Set the visibility for the given path.
     *
     * @param  string  $path
     * @param  string  $visibility
     * @return bool
     */
    public function setVisibility($path, $visibility)
    {
        if ($visibility === Filesystem::VISIBILITY_PUBLIC) {
            $visibility = self::VISIBILITY_PUBLIC_READ_WRITE;
        }

        try {
            $this->getClient()->putObjectAcl($this->bucket, $path, $visibility);

            return true;
        } catch (OssException $e) {
            $this->errors[] = $e;

            return false;
        }
    }

    /**
     * Prepend to a file.
     *
     * @param  string  $path
     * @param  string  $data
     * @return bool
     */
    public function prepend($path, $data)
    {
        if ($this->exists($path)) {
            return $this->put($path, $data . $this->get($path));
        }

        return $this->put($path, $data);
    }

    /**
     * Append to a file.
     *
     * @param  string  $path
     * @param  string  $data
     * @return bool
     */
    public function append($path, $data)
    {
        try {
            $this->getClient()->appendObject(
                $this->bucket, $path, $data, $this->size($path)
            );

            return true;
        } catch (OssException $e) {
            $this->errors[] = $e;

            return false;
        }
    }

    /**
     * Delete the file at a given path.
     *
     * @param  string|array  $paths
     * @return bool
     */
    public function delete($paths)
    {
        $paths = is_array($paths) ? $paths : func_get_args();

        $success = true;

        foreach ($paths as $path) {
            try {
                $this->getClient()->deleteObject($this->bucket, $path);
            } catch (OssException $e) {
                $this->errors[] = $e;

                $success = false;
            }
        }

        return $success;
    }

    /**
     * Copy a file to a new location.
     *
     * @param  string  $from
     * @param  string  $to
     * @return bool
     */
    public function copy($from, $to)
    {
        try {
            $this->getClient()->copyObject(
                $this->bucket, $from, $this->bucket, $to
            );

            return true;
        } catch (OssException $e) {
            $this->errors[] = $e;

            return false;
        }
    }

    /**
     * Move a file to a new location.
     *
     * @param  string  $from
     * @param  string  $to
     * @return bool
     */
    public function move($from, $to)
    {
        if (!$this->copy($from, $to)) {
            return false;
        }

        return $this->delete($from);
    }

    /**
     * Get the file size of a given file.
     *
     * @param  string  $path
     * @return int
     */
    public function size($path)
    {
        $meta = $this->getMetadata($path);

        return !empty($meta['content-length']) ? $meta['content-length'] : 0;
    }

    /**
     * Get the file's last modification time.
     *
     * @param  string  $path
     * @return int
     */
    public function lastModified($path)
    {
        $meta = $this->getMetadata($path);

        return !empty($meta['last-modified']) ? strtotime($meta['last-modified']) : 0;
    }

    /**
     * Get an array of all files in a directory.
     *
     * @param  string|null  $directory
     * @param  bool  $recursive
     * @return array
     */
    public function files($directory = null, $recursive = false)
    {
        $paths = [];
        foreach ($this->cursor($directory, $recursive) as $path) {
            $paths[] = $path;
        }

        return $paths;
    }

    /**
     * Get all of the files from the given directory (recursive).
     *
     * @param  string|null  $directory
     * @return array
     */
    public function allFiles($directory = null)
    {
        return $this->files($directory, true);
    }

    /**
     * Get all of the directories within a given directory.
     *
     * @param  string|null  $directory
     * @param  bool  $recursive
     * @return array
     */
    public function directories($directory = null, $recursive = false)
    {
        if ($directory === null) {
            $directory = '';
        }

        if ($directory !== '' && mb_substr($directory, -1) !== '/') {
            $directory = "{$directory}/";
        }

        $result = $this->listContents($directory);

        $directories = [];
        foreach ($result->getPrefixList() as $prefix) {
            $directories[] = $prefix->getPrefix();

            if ($recursive) {
                $directories = array_merge(
                    $directories,
                    $this->directories($prefix->getPrefix(), true)
                );
            }
        }

        return $directories;
    }

    /**
     * Get all (recursive) of the directories within a given directory.
     *
     * @param  string|null  $directory
     * @return array
     */
    public function allDirectories($directory = null)
    {
        return $this->directories($directory, true);
    }

    /**
     * Create a directory.
     *
     * @param  string  $path
     * @return bool
     */
    public function makeDirectory($path)
    {
        try {
            $this->getClient()->createObjectDir($this->bucket, $path);

            return true;
        } catch (OssException $e) {
            $this->errors[] = $e;

            return false;
        }
    }

    /**
     * Recursively delete a directory.
     *
     * @param  string  $directory
     * @return bool
     */
    public function deleteDirectory($directory)
    {
        try {
            $paths = [];
            foreach ($this->cursor($directory, true) as $path) {
                $paths[] = $path;
            }
            // 删除文件
            $this->getClient()->deleteObjects($this->bucket, $paths);

            return true;
        } catch (OssException $e) {
            $this->errors[] = $e;

            return false;
        }
    }

    /**
     * 获取资源的元信息,但不返回文件内容
     *
     * @param $path
     * @return array
     */
    public function getMetadata($path)
    {
        try {
            return $this->getClient()->getObjectMeta($this->bucket, $path);
        } catch (OssException $e) {
            $this->errors[] = $e;

            return [];
        }
    }

    /**
     * 流式获取目录下的数据(包含目录)
     *
     * @param  string|null $prefix
     * @param  string $marker
     * @return \Generator
     */
    public function cursor($prefix = null, bool $recursive = false)
    {
        if ($prefix === null) {
            $prefix = '';
        }

        if ($prefix !== '' && mb_substr($prefix, -1) !== '/') {
            $prefix = "{$prefix}/";
        }

        $marker = '';
        while (true) {
            $result = $this->listContents($prefix, $marker);

            foreach ($result->getObjectList() as $file) {
                yield $file->getKey();
            }

            if ($recursive) {
                foreach ($result->getPrefixList() as $obj) {
                    foreach ($this->cursor($obj->getPrefix(), true) as $file) {
                        yield $file;
                    }
                }
            }

            $marker = $result->getNextMarker();

            if ($marker === '') {
                break;
            }
        }
    }

    /**
     * 获取指定数量的文件
     *
     * @param  string $directory
     * @param  string $marker
     * @return \OSS\Model\ObjectListInfo
     */
    protected function listContents(string $directory, string $marker = '')
    {
        return $this->getClient()->listObjects($this->bucket, [
            OssClient::OSS_PREFIX   => $directory,
            OssClient::OSS_MARKER   => $marker,
            OssClient::OSS_MAX_KEYS => $this->config[OssClient::OSS_MAX_KEYS],
        ]);
    }

    /**
     * 获取oss请求时catch的错误
     *
     * @return array
     */
    public function errors()
    {
        return $this->errors;
    }

    /**
     * 调用oss client
     *
     * @param $path
     * @return array
     */
    public function __call($name, $arguments)
    {
        return $this->getClient()->{$name}(...$arguments);
    }
}
