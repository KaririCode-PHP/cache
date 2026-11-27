<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Compression;

use KaririCode\Cache\Compression\ZlibCompressor;
use KaririCode\Cache\Exception\BackendException;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Exception\ConfigurationException;
use PHPUnit\Framework\TestCase;

final class ZlibCompressorTest extends TestCase
{
    public function testSmallPayloadsAreStoredRaw(): void
    {
        $zlib = new ZlibCompressor(threshold: 100);

        self::assertSame("\x00tiny", $zlib->compress('tiny'));
        self::assertSame('tiny', $zlib->decompress("\x00tiny"));
    }

    public function testLargePayloadRoundTripsAndShrinks(): void
    {
        $zlib = new ZlibCompressor(threshold: 10, level: 6);
        $data = str_repeat('cache ', 1000);
        $compressed = $zlib->compress($data);

        self::assertSame("\x01", $compressed[0]);
        self::assertLessThan(strlen($data), strlen($compressed));
        self::assertSame($data, $zlib->decompress($compressed));
    }

    public function testIncompressiblePayloadStaysRaw(): void
    {
        $zlib = new ZlibCompressor(threshold: 1);
        $random = random_bytes(64);

        self::assertSame("\x00" . $random, $zlib->compress($random));
    }

    public function testUnprefixedLegacyPayloadIsReturnedUnchanged(): void
    {
        self::assertSame('s:1:"a";', (new ZlibCompressor())->decompress('s:1:"a";'));
        self::assertSame('', (new ZlibCompressor())->decompress(''));
    }

    public function testCorruptCompressedPayloadRaisesSemanticError(): void
    {
        $this->expectException(CacheException::class);
        $this->expectExceptionCode(BackendException::DECOMPRESSION_FAILED);

        (new ZlibCompressor())->decompress("\x01garbage");
    }

    public function testInvalidOptionsAreRejected(): void
    {
        foreach ([['threshold' => -1], ['level' => 10], ['level' => -2]] as $options) {
            try {
                new ZlibCompressor(...$options);
                self::fail('accepted ' . json_encode($options));
            } catch (ConfigurationException $e) {
                self::assertSame(ConfigurationException::INVALID_OPTION, $e->getCode());
            }
        }
    }
}
