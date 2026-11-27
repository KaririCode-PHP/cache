<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Integration\Adapter;

use KaririCode\Cache\Adapter\FilesystemAdapter;
use KaririCode\Cache\Configuration\FilesystemOptions;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Cache\Tests\Fixtures\FrozenClock;
use PHPUnit\Framework\TestCase;

/**
 * File lifecycle under a frozen clock: validity up to the deadline, expired
 * files removed on read and by GC, flush limited to the adapter's own files,
 * and the configured permissions.
 */
final class FilesystemLifecycleTest extends TestCase
{
    private string $directory;

    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/kc-fs-life-' . getmypid() . '-' . bin2hex(random_bytes(3));
        $this->clock = new FrozenClock();
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            if ($item instanceof \SplFileInfo) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
        }
        rmdir($this->directory);
    }

    private function adapter(FilesystemOptions $options = new FilesystemOptions(gcProbability: 0)): FilesystemAdapter
    {
        return new FilesystemAdapter($this->directory, $options, clock: $this->clock);
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS)) as $item) {
            if ($item instanceof \SplFileInfo && $item->isFile()) {
                $files[] = substr($item->getPathname(), strlen($this->directory) + 1);
            }
        }
        sort($files);

        return $files;
    }

    /**
     * @return list<string>
     */
    private function topLevelDirectories(): array
    {
        $directories = array_values(array_filter(scandir($this->directory) ?: [], fn(string $name): bool => !in_array($name, ['.', '..'], true) && is_dir("{$this->directory}/{$name}")));
        sort($directories);

        return $directories;
    }

    public function testAnEntryIsValidAtItsDeadlineAndItsFileIsRemovedWhenReadAfterIt(): void
    {
        $adapter = $this->adapter();

        self::assertTrue($adapter->write('k', 'v', 60));
        $this->clock->advance(60);
        self::assertSame('v', $adapter->read('k'));

        $this->clock->advance(0.5);
        self::assertNull($adapter->read('k'));
        self::assertSame([], $this->files());
    }

    public function testAZeroTtlLeavesNoFile(): void
    {
        $adapter = $this->adapter();
        $adapter->write('k', 'v');

        self::assertTrue($adapter->write('k', 'v', 0));
        self::assertSame([], $this->files());
    }

    public function testSampledGarbageCollectionRemovesOnlyExpiredEntries(): void
    {
        $adapter = $this->adapter(new FilesystemOptions(gcProbability: 1, gcDivisor: 1));
        $adapter->write('short', 1, 10);
        $adapter->write('long', 2, 100);

        $this->clock->advance(10);
        $adapter->write('first', 3);
        self::assertCount(3, $this->files(), 'an entry at its deadline is kept');

        $this->clock->advance(1);
        $adapter->write('second', 4);
        self::assertCount(3, $this->files(), 'the expired entry is collected, the new one written');
        self::assertSame(1, $adapter->stats()->deletes);
    }

    public function testWithoutSamplingNothingIsCollectedOnWrite(): void
    {
        $adapter = $this->adapter(new FilesystemOptions(gcProbability: 0));
        $adapter->write('short', 1, 10);

        $this->clock->advance(11);
        $adapter->write('trigger', 2);

        self::assertCount(2, $this->files());
    }

    public function testFlushRemovesItsEntriesAndShardsButNothingElse(): void
    {
        $adapter = $this->adapter();
        $adapter->writeMultiple(['a' => 1, 'b' => 2, 'c' => 3]);
        file_put_contents("{$this->directory}/foreign.txt", 'keep');
        mkdir("{$this->directory}/keep-dir");

        self::assertTrue($adapter->flush());

        self::assertSame(['foreign.txt'], $this->files());
        self::assertSame(['keep-dir'], $this->topLevelDirectories());
        self::assertSame(3, $adapter->stats()->deletes);
    }

    public function testConfiguredFilePermissionsAreApplied(): void
    {
        $adapter = $this->adapter(new FilesystemOptions(filePermissions: 0o600, gcProbability: 0));
        $adapter->write('k', 'v');

        self::assertSame(0o600, fileperms("{$this->directory}/{$this->files()[0]}") & 0o777);
    }

    public function testConstructorErrorsNameTheViolatedConstraint(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'kc-not-a-dir');

        try {
            foreach (['' => 'must not be empty', $file => 'writable directory'] as $directory => $constraint) {
                try {
                    new FilesystemAdapter((string) $directory);
                    self::fail("'{$directory}' must be rejected");
                } catch (ConfigurationException $e) {
                    self::assertStringContainsString($constraint, $e->getMessage());
                }
            }
        } finally {
            unlink($file);
        }
    }
}
