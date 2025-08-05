<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

use KaririCode\Cache\Contract\Behavioral\Taggable;
use KaririCode\Cache\ValueObject\Tag;

/**
 * Extends the base CacheDriver to include support for tag-based operations.
 * Drivers implementing this interface can natively handle the association of keys
 * with tags, allowing for efficient invalidation and retrieval.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
interface TaggableCacheDriver extends CacheDriver, Taggable
{
}
