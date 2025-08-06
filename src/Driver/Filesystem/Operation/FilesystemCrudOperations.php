<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Filesystem\Operation;

use KaririCode\Cache\Contract\CacheSerializer;
use KaririCode\Cache\Contract\Filesystem\DirectoryManager;
use KaririCode\Cache\Contract\Filesystem\FileManager;
use KaririCode\Cache\Contract\Provider\ExpirationProvider;
use KaririCode\Cache\Driver\Filesystem\Config\FilesystemConfig;
use KaririCode\Cache\Service\ValidationService;
use Psr\Log\LoggerInterface;

/**
 * Handles basic CRUD and batch operations for the FilesystemDriver.
 *
 * This class is solely responsible for the core logic of reading, writing, and
 * deleting cache files. It internally uses the ExpirationProvider to manage TTLs
 * and is configured via a dedicated DTO, adhering to SRP and the Strategy Pattern.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class FilesystemCrudOperations
{
    private const HASH_ALGORITHM = 'sha256';
    private const FILENAME_HASH_LENGTH = 16;
    private const MAX_KEY_LENGTH_IN_FILENAME = 50;

    private readonly ValidationService $validator;

    /**
     * @param FilesystemConfig $config the configuration DTO for the driver
     * @param DirectoryManager $directoryManager manages directory operations
     * @param FileManager $fileManager manages file I/O operations
     * @param CacheSerializer $serializer handles serialization and deserialization
     * @param ExpirationProvider $expirationProvider the strategy for TTL management
     * @param LoggerInterface $logger a PSR-3 logger instance
     */
    public function __construct(
        private readonly FilesystemConfig $config,
        private readonly DirectoryManager $directoryManager,
        private readonly FileManager $fileManager,
        private readonly CacheSerializer $serializer,
        private readonly ExpirationProvider $expirationProvider,
        private readonly LoggerInterface $logger
    ) {
        $this->validator = new ValidationService();
    }

    public function get(string $key): mixed
    {
        $this->validator->validateKey($key);
        $filename = $this->generateFilename($key);

        if (!$this->fileManager->exists($filename)) {
            return null;
        }

        $content = $this->fileManager->readSecurely($filename);
        if (null === $content) {
            return null;
        }

        try {
            $metadata = $this->serializer->unserialize($content);
            if (!is_array($metadata) || !isset($metadata['value'])) {
                $this->handleCorruptedFile($key, $filename, 'Invalid metadata structure');

                return null;
            }

            if ($this->isExpired($metadata)) {
                $this->fileManager->deleteFile($filename);

                return null;
            }

            return $metadata['value'];
        } catch (\Throwable $e) {
            $this->handleCorruptedFile($key, $filename, 'Deserialization failed', $e);

            return null;
        }
    }

    public function set(string $key, mixed $value): bool
    {
        $this->validator->validateKey($key);
        $this->validator->validateValue($value);

        $expiration = $this->expirationProvider->calculateExpiration(null, $key, 'set');
        $filename = $this->generateFilename($key);
        $metadata = $this->createMetadata($value, $expiration);

        try {
            $serializedData = $this->serializer->serialize($metadata);
            $success = $this->fileManager->writeSecurely($filename, $serializedData);

            if ($success && $this->shouldCleanup()) {
                $this->cleanupExpired();
            }

            return $success;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to store cache item.', ['key' => $key, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public function has(string $key): bool
    {
        $this->validator->validateKey($key);
        $filename = $this->generateFilename($key);

        if (!$this->fileManager->exists($filename)) {
            return false;
        }

        $content = $this->fileManager->readSecurely($filename);
        if (null === $content) {
            return false;
        }

        try {
            $metadata = $this->serializer->unserialize($content);
            if (!is_array($metadata)) {
                return false;
            }
            if ($this->isExpired($metadata)) {
                $this->fileManager->deleteFile($filename);

                return false;
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function delete(string $key): bool
    {
        $this->validator->validateKey($key);
        $filename = $this->generateFilename($key);

        return $this->fileManager->deleteFile($filename);
    }

    public function clear(): bool
    {
        return $this->directoryManager->clear();
    }

    public function getMultiple(iterable $keys): iterable
    {
        $results = [];
        foreach ($keys as $key) {
            $results[$key] = $this->get($key);
        }

        return $results;
    }

    public function setMultiple(iterable $values): bool
    {
        $expiration = $this->expirationProvider->calculateExpiration(null, null, 'setMultiple');
        $success = true;

        foreach ($values as $key => $value) {
            if (!$this->set($key, $value, $expiration)) { // Temporarily pass expiration
                $success = false;
            }
        }

        return $success;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $success = true;
        foreach ($keys as $key) {
            if (!$this->delete($key)) {
                $success = false;
            }
        }

        return $success;
    }

    public function cleanupExpired(): void
    {
        $files = $this->directoryManager->getCacheFiles();
        $deletedCount = 0;

        foreach ($files as $file) {
            try {
                $content = $this->fileManager->readSecurely($file);
                if (null === $content) {
                    continue;
                }

                $metadata = $this->serializer->unserialize($content);
                if (is_array($metadata) && $this->isExpired($metadata)) {
                    if ($this->fileManager->deleteFile($file)) {
                        ++$deletedCount;
                    }
                }
            } catch (\Throwable) {
                // Delete corrupted or unreadable files during cleanup
                if ($this->fileManager->deleteFile($file)) {
                    ++$deletedCount;
                }
            }
        }

        if ($deletedCount > 0) {
            $this->logger->info('Filesystem cache cleanup completed.', ['deleted_files' => $deletedCount]);
        }
    }

    private function generateFilename(string $key): string
    {
        $hash = hash(self::HASH_ALGORITHM, $key);
        $safeKey = preg_replace('/[^a-zA-Z0-9_-]/', '_', $key);
        $safeKey = substr($safeKey, 0, self::MAX_KEY_LENGTH_IN_FILENAME);

        $filename = $this->config->keyPrefix . $safeKey . '_' . substr($hash, 0, self::FILENAME_HASH_LENGTH) . '.cache';
        $directory = $this->directoryManager->createSubdirectoryPath($hash);

        return $directory . DIRECTORY_SEPARATOR . $filename;
    }

    /** @return array{value: mixed, expiration: int|null} */
    private function createMetadata(mixed $value, ?\DateTimeInterface $expiration): array
    {
        return [
            'value' => $value,
            'expiration' => $expiration?->getTimestamp(),
        ];
    }

    private function isExpired(array $metadata): bool
    {
        $expiration = $metadata['expiration'] ?? null;

        return null !== $expiration && $expiration < time();
    }

    private function shouldCleanup(): bool
    {
        return (mt_rand() / mt_getrandmax()) < $this->config->cleanupProbability;
    }

    private function handleCorruptedFile(string $key, string $filename, string $reason, ?\Throwable $e = null): void
    {
        $context = ['key' => $key, 'filename' => $filename, 'reason' => $reason];
        if ($e) {
            $context['error'] = $e->getMessage();
        }
        $this->logger->warning('Corrupted cache file detected. Deleting.', $context);
        $this->fileManager->deleteFile($filename);
    }
}
