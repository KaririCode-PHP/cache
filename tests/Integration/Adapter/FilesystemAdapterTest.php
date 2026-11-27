<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Integration\Adapter;

use KaririCode\Cache\Adapter\FilesystemAdapter;
use KaririCode\Cache\Compression\ZlibCompressor;
use KaririCode\Cache\Configuration\FilesystemOptions;
use KaririCode\Cache\Exception\ConfigurationException;
use KaririCode\Cache\Serialization\PayloadCodec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('security')]
final class FilesystemAdapterTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/kc-fs-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item instanceof \SplFileInfo) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
        }

        rmdir($this->directory);
    }

    public function testEntryWithoutTtlIsReadable(): void
    {
        $adapter = new FilesystemAdapter($this->directory);
        $adapter->write('forever', ['a' => 1]);

        self::assertSame(['a' => 1], $adapter->read('forever'));
        self::assertTrue($adapter->exists('forever'));
    }

    public function testEntryExpiresAndNonPositiveTtlDeletes(): void
    {
        $adapter = new FilesystemAdapter($this->directory);
        $adapter->write('short', 'v', 1);
        $adapter->write('gone', 'v');

        self::assertTrue($adapter->write('gone', 'x', 0));
        self::assertNull($adapter->read('gone'));

        usleep(1_100_000);
        self::assertNull($adapter->read('short'));
        self::assertFalse($adapter->exists('short'));
    }

    public function testRemoveIsIdempotent(): void
    {
        $adapter = new FilesystemAdapter($this->directory);

        self::assertTrue($adapter->remove('never-written'));
    }

    public function testFlushRemovesOnlyItsOwnFiles(): void
    {
        mkdir($this->directory . '/project', 0o777, true);
        file_put_contents($this->directory . '/keep.txt', 'x');
        file_put_contents($this->directory . '/project/app.php', '<?php');

        $adapter = new FilesystemAdapter($this->directory);
        $adapter->write('k1', 1);
        $adapter->write('k2', 2);

        self::assertTrue($adapter->flush());
        self::assertFalse($adapter->exists('k1'));
        self::assertFileExists($this->directory . '/keep.txt');
        self::assertFileExists($this->directory . '/project/app.php');
    }

    public function testCorruptOrForeignEntriesAreMisses(): void
    {
        $adapter = new FilesystemAdapter($this->directory, new FilesystemOptions(shardingLevels: 0));
        $adapter->write('bad', 'v');
        $adapter->write('real', 'v');

        file_put_contents($this->pathOf('bad'), "0\nbad\nnot-a-payload");
        file_put_contents($this->pathOf('real'), "0\nsomeone-else\n" . serialize('x'));

        self::assertNull($adapter->read('bad'));
        self::assertFileDoesNotExist($this->pathOf('bad'), 'corrupt entry is deleted');
        self::assertNull($adapter->read('real'), 'hash collision guard');
    }

    public function testGcReadsHeadersAndRemovesExpiredEntries(): void
    {
        $adapter = new FilesystemAdapter($this->directory, new FilesystemOptions(gcProbability: 0));
        $adapter->write('old', 'v', 1);
        $adapter->write('keep', 'v');
        usleep(1_100_000);

        self::assertSame(1, $adapter->gc());
        self::assertSame('v', $adapter->read('keep'));
    }

    public function testCompressionRoundTrip(): void
    {
        $adapter = new FilesystemAdapter($this->directory, codec: new PayloadCodec(compressor: new ZlibCompressor(threshold: 10)));
        $payload = str_repeat('compressible ', 500);
        $adapter->write('big', $payload);

        self::assertSame($payload, $adapter->read('big'));
        self::assertLessThan(strlen($payload), (int) filesize($this->pathOf('big', 2)));
    }

    public function testStatsCountOwnEntries(): void
    {
        $adapter = new FilesystemAdapter($this->directory);
        $adapter->write('a', 1);
        $adapter->write('b', 2);
        $adapter->read('a');
        $adapter->read('missing');

        $stats = $adapter->stats();
        self::assertSame(2, $stats->itemCount);
        self::assertSame(1, $stats->hits);
        self::assertSame(1, $stats->misses);
    }

    /**
     * @return iterable<string, array{array<string, int>}>
     */
    public static function invalidOptions(): iterable
    {
        yield 'gcDivisor 0' => [['gcDivisor' => 0]];
        yield 'negative gcProbability' => [['gcProbability' => -1]];
        yield 'sharding 9' => [['shardingLevels' => 9]];
        yield 'file mode' => [['filePermissions' => 0o17777]];
    }

    /**
     * @param array<string, int> $options
     */
    #[DataProvider('invalidOptions')]
    public function testInvalidOptionsFailFast(array $options): void
    {
        $this->expectException(ConfigurationException::class);

        new FilesystemAdapter($this->directory, new FilesystemOptions(...$options));
    }

    public function testEmptyDirectoryIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);

        new FilesystemAdapter('');
    }

    private function pathOf(string $key, int $levels = 0): string
    {
        $hash = hash('xxh3', $key);
        $path = $this->directory;

        for ($i = 0; $i < $levels; ++$i) {
            $path .= '/' . substr($hash, $i * 2, 2);
        }

        return $path . '/' . $hash . '.cache';
    }
}
