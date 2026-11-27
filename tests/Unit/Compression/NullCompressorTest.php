<?php

declare(strict_types=1);

namespace KaririCode\Cache\Tests\Unit\Compression;

use KaririCode\Cache\Compression\NullCompressor;
use PHPUnit\Framework\TestCase;

final class NullCompressorTest extends TestCase
{
    public function testPassThrough(): void
    {
        $null = new NullCompressor();

        self::assertSame('data', $null->compress('data'));
        self::assertSame('data', $null->decompress('data'));
        self::assertTrue($null->isAvailable());
    }
}
