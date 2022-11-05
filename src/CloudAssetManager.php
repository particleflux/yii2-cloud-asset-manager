<?php

namespace white43\CloudAssetManager;

use Closure;
use League\Flysystem\DirectoryListing;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToWriteFile;
use yii\base\InvalidConfigException;
use yii\caching\Cache;
use yii\di\Instance;
use yii\helpers\FileHelper;

class CloudAssetManager extends BaseAssetManager
{
    protected const CACHE_META_KEY = 'cloud-assets-meta-%s';

    /**
     * @var mixed
     */
    public $adapter;

    /**
     * @var mixed
     */
    public $filesystem;

    /**
     * Initializes the component.
     * @throws \yii\base\InvalidConfigException
     */
    public function init(): void
    {
        parent::init();

        if (empty($this->basePath)) {
            throw new InvalidConfigException('Relative path at the destination must be defined.');
        }

        if ($this->filesystem instanceof Closure) {
            $closure = $this->filesystem;
            $this->filesystem = $closure();
        } elseif (empty($this->filesystem) && $this->adapter instanceof Closure) {
            $closure = $this->adapter;
            $this->adapter = $closure();
        }

        if (is_object($this->filesystem) || is_array($this->filesystem) || is_string($this->filesystem)) {
            $this->filesystem = Instance::ensure($this->filesystem, Filesystem::class);
        } elseif (is_object($this->adapter) || is_array($this->adapter) || is_string($this->adapter)) {
            $this->adapter = Instance::ensure($this->adapter, FilesystemAdapter::class);
            $this->filesystem = new Filesystem($this->adapter);
        } else {
            throw new InvalidConfigException(
                'Filesystem or adapter property must be of type object, array, string or closure. ' .
                'Closure must return object, array or string'
            );
        }
    }

    /**
     * @param string $src
     * @param array $options
     * @return string[]
     * @throws \League\Flysystem\FilesystemException
     * @throws InvalidConfigException
     */
    protected function publishDirectory($src, $options)
    {
        if (!$this->filesystem instanceof Filesystem) {
            throw new \Exception(); // TODO
        }

        $dir = $this->hash($src);
        $dstDir = $this->basePath . DIRECTORY_SEPARATOR . $dir;

        $cache = Instance::ensure($this->cache, Cache::class);

        $finalKey = $this->getMetaKey($dir . '-completed');
        $completed = $cache->get($finalKey);

        if (!empty($options['forceCopy']) || ($this->forceCopy && !isset($options['forceCopy'])) || !$completed) {
            $currentLength = strlen($src);
            $directories = FileHelper::findDirectories($src, array_merge($this->filterFilesOptions, $options));
            $files = FileHelper::findFiles($src, array_merge($this->filterFilesOptions, $options));

            $meta = $this->getMetaFromRemoteData($this->filesystem->listContents($dstDir, true), $dir);

            if (isset($options['beforeCopy'])) {
                $beforeCopy = $options['beforeCopy'];
            } elseif ($this->beforeCopy !== null) {
                $beforeCopy = $this->beforeCopy;
            }
            if (isset($options['afterCopy'])) {
                $afterCopy = $options['afterCopy'];
            } elseif ($this->afterCopy !== null) {
                $afterCopy = $this->afterCopy;
            }

            foreach ($directories as $directory) {
                $dstDirectory = substr($directory, $currentLength);

                if (isset($beforeCopy) && !$beforeCopy($directory, $dstDir . $dstDirectory)) {
                    continue;
                }

                $key = $this->getMetaKey($dir . $dstDirectory);
                $dirMeta = isset($meta[$key]) ? $meta[$key] : null;

                if (!isset($dirMeta)) {
                    $this->filesystem->createDirectory($dstDir . $dstDirectory);
                }
            }

            foreach ($files as $file) {
                $dstFile = substr($file, $currentLength);
                $dstBaseFile = basename($dstFile);

                if (isset($beforeCopy) && !$beforeCopy($file, $dstDir . $dstFile)) {
                    continue;
                }

                $key = $this->getMetaKey(dirname($dir . $dstFile));
                $dirMeta = isset($meta[$key]) ? $meta[$key] : null;

                try {
                    if (!isset($dirMeta[$dstBaseFile])) {
                        $this->filesystem->writeStream($dstDir . $dstFile, fopen($file, 'r'));

                        if (isset($afterCopy)) {
                            $afterCopy($file, $dstDir . $dstFile);
                        }
                    }
                } catch (UnableToWriteFile $e) {
                    // Do nothing
                }
            }

            $cache->set($finalKey, true);
        }

        return [$dstDir, $this->baseUrl . '/' . $dir];
    }

    /**
     * @param DirectoryListing $data
     * @psalm-param DirectoryListing<StorageAttributes> $data
     * @param string $hash
     * @return array
     * @psalm-return array<string, array<string, 1>>
     */
    private function getMetaFromRemoteData(DirectoryListing $data, string $hash): array
    {
        $meta = [];
        $key = $this->getMetaKey($hash);
        $meta[$key] = [];

        $common_prefix_length = strlen($this->basePath) + 1;

        foreach ($data as $item) {
            if ($item->type() === StorageAttributes::TYPE_DIRECTORY) {
                $path_without_prefix = substr($item->path(), $common_prefix_length);

                $key = $this->getMetaKey($path_without_prefix);
                $meta[$key] = [];
            } elseif ($item->type() === StorageAttributes::TYPE_FILE) {
                $path_without_prefix = substr($item->path(), $common_prefix_length);
                $basedir_without_prefix = dirname($path_without_prefix);

                $key = $this->getMetaKey($basedir_without_prefix);
                $filename = basename($item->path());
                $meta[$key][$filename] = 1;
            }
        }

        return $meta;
    }

    /**
     * @param string $dir
     * @return string
     */
    private function getMetaKey($dir)
    {
        return rtrim(sprintf(self::CACHE_META_KEY, $dir), '-/');
    }
}
