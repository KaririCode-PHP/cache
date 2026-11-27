<?php

declare(strict_types=1);

namespace KaririCode\Cache\Adapter;

use KaririCode\Cache\Clock\SystemClock;
use KaririCode\Cache\Configuration\FilesystemOptions;
use KaririCode\Cache\Contract\CacheAdapter;
use KaririCode\Cache\Exception\BackendException;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Cache\Serialization\PayloadCodec;
use KaririCode\Cache\ValueObject\AdapterStats;
use Psr\Clock\ClockInterface;

/**
 * Filesystem adapter: one file per key, sharded into two-hex-character
 * directories, written atomically (temporary file + rename).
 *
 * File layout (since 4.1.0):
 *   line 1  expiry timestamp, "0" = never
 *   line 2  rawurlencode(key), checked on read against hash collisions
 *   rest    Compressor(Serializer(value))
 * exists() and gc() read the two header lines only; they never unserialize.
 * flush() and gc() touch only files and shard directories named by this
 * adapter, never other content of the directory. Modes, sharding and GC
 * come from FilesystemOptions. Files written by 4.0.x have no header and
 * are reported as misses.
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): same CacheAdapter port as Redis.
 * Spec V4: Entity (counters); options validated at construction.
 *
 * Preconditions: the directory exists or can be created, and is writable; it may hold other content.
 * Thread-safety: safe across processes through atomic rename; counters are per instance.
 *
 * Side effects: creates, renames and deletes its own files and shard directories; GC may run during a write.
 *
 * Example:
 *     $adapter = new FilesystemAdapter('/var/cache/app', new FilesystemOptions(gcDivisor: 10_000));
 *
 * References: ADR-010; SPEC-001 §7.
 *
 * @see https://www.php.net/manual/en/function.rename.php
 */
final class FilesystemAdapter implements CacheAdapter
{
    private const string EXTENSION = '.cache';
    private const string ENTRY_PATTERN = '/^[0-9a-f]{16}\.cache(\.[0-9a-f]{12}\.tmp)?$/';
    private const string SHARD_PATTERN = '/^[0-9a-f]{2}$/';

    private int $hits = 0;
    private int $misses = 0;
    private int $writes = 0;
    private int $deletes = 0;

    public function __construct(
        private readonly string $directory,
        private readonly FilesystemOptions $options = new FilesystemOptions(),
        private readonly PayloadCodec $codec = new PayloadCodec(),
        private readonly ClockInterface $clock = new SystemClock(),
    ) {
        if ('' === $directory) {
            throw ConfigurationException::invalidOption(option: 'directory', constraint: 'must not be empty');
        }

        if (!is_dir($directory)) {
            $mode = $options->directoryPermissions;
            self::quietly(static fn(): bool => mkdir($directory, $mode, true));
        }

        if (!is_dir($directory) || !is_writable($directory)) {
            throw ConfigurationException::invalidOption(option: 'directory', constraint: 'must be a writable directory');
        }
    }

    // ── CacheAdapter ─────────────────────────────────────────────────────

    #[\Override]
    public function read(string $key): mixed
    {
        $entry = $this->open($key, withPayload: true);

        if (null === $entry) {
            ++$this->misses;

            return null;
        }

        try {
            $value = $this->codec->decode($entry['payload']);
        } catch (CacheException) {
            $this->remove($key);
            ++$this->misses;

            return null;
        }

        ++$this->hits;

        return $value;
    }

    #[\Override]
    public function write(string $key, mixed $value, ?int $ttl = null): bool
    {
        if (null !== $ttl && $ttl <= 0) {
            return $this->remove($key);
        }

        $this->maybeCollectGarbage();

        $path = $this->pathFor($key);
        $this->ensureDirectory(dirname($path), $key);

        $expiresAt = null === $ttl ? '0' : sprintf('%.6F', $this->now() + (float) $ttl);
        $data = $expiresAt . "\n" . rawurlencode($key) . "\n" . $this->encode($value);
        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (false === self::quietly(static fn(): int|false => file_put_contents($temporary, $data, LOCK_EX))) {
            throw BackendException::writeFailed();
        }

        $permissions = $this->options->filePermissions;
        self::quietly(static fn(): bool => chmod($temporary, $permissions));

        if (!self::quietly(static fn(): bool => rename($temporary, $path))) {
            self::quietly(static fn(): bool => unlink($temporary));

            throw BackendException::writeFailed();
        }

        ++$this->writes;

        return true;
    }

    #[\Override]
    public function remove(string $key): bool
    {
        $path = $this->pathFor($key);

        if (self::quietly(static fn(): bool => unlink($path))) {
            ++$this->deletes;
        }

        return !is_file($path);
    }

    #[\Override]
    public function exists(string $key): bool
    {
        return null !== $this->open($key, withPayload: false);
    }

    #[\Override]
    public function flush(): bool
    {
        foreach ($this->entries(\RecursiveIteratorIterator::CHILD_FIRST, includeDirectories: true) as $file) {
            $path = $file->getPathname();

            if ($file->isDir()) {
                if (1 === preg_match(self::SHARD_PATTERN, $file->getFilename())) {
                    self::quietly(static fn(): bool => rmdir($path));
                }
            } elseif (self::quietly(static fn(): bool => unlink($path))) {
                ++$this->deletes;
            }
        }

        return true;
    }

    #[\Override]
    public function readMultiple(array $keys): array
    {
        $results = [];

        foreach ($keys as $key) {
            $results[$key] = $this->read($key);
        }

        return $results;
    }

    #[\Override]
    public function writeMultiple(array $values, ?int $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->write(key: (string) $key, value: $value, ttl: $ttl);
        }

        return true;
    }

    #[\Override]
    public function removeMultiple(array $keys): bool
    {
        $success = true;

        foreach ($keys as $key) {
            $success = $this->remove($key) && $success;
        }

        return $success;
    }

    #[\Override]
    public function stats(): AdapterStats
    {
        $count = 0;
        $bytes = 0;

        foreach ($this->entries(\RecursiveIteratorIterator::LEAVES_ONLY) as $file) {
            if (str_ends_with($file->getFilename(), self::EXTENSION)) {
                ++$count;
                $bytes += (int) $file->getSize();
            }
        }

        return new AdapterStats(
            hits: $this->hits,
            misses: $this->misses,
            writes: $this->writes,
            deletes: $this->deletes,
            itemCount: $count,
            memoryBytes: $bytes,
            adapterName: $this->name(),
        );
    }

    #[\Override]
    public function name(): string
    {
        return 'filesystem';
    }

    #[\Override]
    public function isAvailable(): bool
    {
        return is_dir($this->directory) && is_writable($this->directory);
    }

    /**
     * Delete expired and unreadable entries; returns how many were deleted.
     * Reads only the header line of each file.
     */
    public function gc(): int
    {
        $removed = 0;
        $now = $this->now();

        foreach ($this->entries(\RecursiveIteratorIterator::LEAVES_ONLY) as $file) {
            if (!str_ends_with($file->getFilename(), self::EXTENSION)) {
                continue;
            }

            $path = $file->getPathname();
            $expiry = $this->readExpiry($path);

            if ((null === $expiry || ($expiry > 0.0 && $now > $expiry))
                && self::quietly(static fn(): bool => unlink($path))) {
                ++$removed;
                ++$this->deletes;
            }
        }

        return $removed;
    }

    // ── Internal ─────────────────────────────────────────────────────────

    private function pathFor(string $key): string
    {
        $hash = hash('xxh3', $key);
        $path = $this->directory;

        for ($level = 0; $level < $this->options->shardingLevels; ++$level) {
            $path .= DIRECTORY_SEPARATOR . substr($hash, $level * 2, 2);
        }

        return $path . DIRECTORY_SEPARATOR . $hash . self::EXTENSION;
    }

    /**
     * Header check shared by read() and exists(). Expired, corrupt and
     * foreign (hash collision) entries are misses; the first two are deleted.
     *
     * @return array{payload: string}|null
     */
    private function open(string $key, bool $withPayload): ?array
    {
        $path = $this->pathFor($key);
        $handle = self::quietly(static fn() => fopen($path, 'rb'));

        if (!is_resource($handle)) {
            return null;
        }

        $stale = false;

        try {
            $header = self::readHeader($handle);

            if (null === $header || ($header['expiresAt'] > 0.0 && $this->now() > $header['expiresAt'])) {
                $stale = true;
            } elseif ($header['key'] === rawurlencode($key)) {
                $payload = $withPayload ? stream_get_contents($handle) : '';

                return false === $payload ? null : ['payload' => $payload];
            }
        } finally {
            fclose($handle);
        }

        // Expired or corrupt entries are deleted; a foreign entry (hash
        // collision) is left for its own key and reported as a miss.
        if ($stale) {
            $this->remove($key);
        }

        return null;
    }

    /**
     * The two header lines, or null when they are missing or malformed.
     *
     * @param resource $handle
     *
     * @return array{expiresAt: float, key: string}|null
     */
    private static function readHeader($handle): ?array
    {
        $expiryLine = fgets($handle);
        $keyLine = fgets($handle);

        if (false === $expiryLine || false === $keyLine || !is_numeric(rtrim($expiryLine, "\n"))) {
            return null;
        }

        return ['expiresAt' => (float) rtrim($expiryLine, "\n"), 'key' => rtrim($keyLine, "\n")];
    }

    private function readExpiry(string $path): ?float
    {
        $handle = self::quietly(static fn() => fopen($path, 'rb'));

        if (!is_resource($handle)) {
            return null;
        }

        $line = fgets($handle);
        fclose($handle);

        return false !== $line && is_numeric(rtrim($line, "\n")) ? (float) rtrim($line, "\n") : null;
    }

    private function ensureDirectory(string $directory, string $key): void
    {
        if (is_dir($directory)) {
            return;
        }

        $permissions = $this->options->directoryPermissions;
        // A concurrent writer may create it between the check and mkdir().
        self::quietly(static fn(): bool => mkdir($directory, $permissions, true));

        if (!is_dir($directory)) {
            throw BackendException::writeFailed();
        }
    }

    private function maybeCollectGarbage(): void
    {
        if ($this->options->gcProbability > 0 && random_int(1, $this->options->gcDivisor) <= $this->options->gcProbability) {
            $this->gc();
        }
    }

    /**
     * Files (and optionally shard directories) that this adapter owns.
     *
     * @param 0|1|2 $mode A RecursiveIteratorIterator mode constant.
     *
     * @return \Generator<int, \SplFileInfo>
     */
    private function entries(int $mode, bool $includeDirectories = false): \Generator
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            $mode,
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }

            $owned = $file->isDir()
                ? $includeDirectories && 1 === preg_match(self::SHARD_PATTERN, $file->getFilename())
                : 1 === preg_match(self::ENTRY_PATTERN, $file->getFilename());

            if ($owned) {
                yield $file;
            }
        }
    }

    private function encode(mixed $value): string
    {
        return $this->codec->encode($value);
    }

    /**
     * Run a filesystem call whose failure is handled by its return value,
     * without emitting the PHP warning of expected races (file removed or
     * directory created by another process).
     *
     * @template T
     *
     * @param \Closure(): T $operation
     *
     * @return T
     */
    private static function quietly(\Closure $operation): mixed
    {
        set_error_handler(static fn(): bool => true);

        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }

    private function now(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
