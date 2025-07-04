<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver;

use KaririCode\Cache\Exception\CacheException;

class FileDriver extends AbstractDriver
{
    private string $directory;

    public function __construct(
        string $directory
    ) {
        parent::__construct($transactionManager);
        $this->directory = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR;
        if (!is_dir($this->directory) && !mkdir($this->directory, 0777, true)) {
            throw new CacheException("Unable to create cache directory: {$this->directory}");
        }
    }

    public function fetch(string $key): mixed
    {
        $filename = $this->getFilename($key);
        if (!file_exists($filename)) {
            return null;
        }

        $data = unserialize(file_get_contents($filename));
        if (false === $data) {
            return null;
        }

        if (isset($data['expiration']) && $data['expiration'] < time()) {
            $this->delete($key);

            return null;
        }

        return $data['value'];
    }

    public function contains(string $key): bool
    {
        return null !== $this->fetch($key);
    }

    protected function doSave(
        string $key,
        mixed $value,
        ?\DateTimeInterface $expiration = null
    ): bool {
        $filename = $this->getFilename($key);
        $data = [
            'value' => $value,
            'expiration' => $expiration ? $expiration->getTimestamp() : null,
        ];

        return false !== file_put_contents($filename, serialize($data));
    }

    protected function doDelete(string $key): bool
    {
        $filename = $this->getFilename($key);
        if (file_exists($filename)) {
            return unlink($filename);
        }

        return true;
    }

    public function clear(): bool
    {
        $files = glob($this->directory . '*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        return true;
    }

    private function getFilename(string $key): string
    {
        return $this->directory . md5($key);
    }
}
