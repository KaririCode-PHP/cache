<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract\Driver;

use KaririCode\Cache\Contract\Behavioral\AtomicOperable;
use KaririCode\Cache\Contract\Behavioral\CompareAndSwapAware;
use KaririCode\Cache\Contract\Behavioral\Maintainable;
use KaririCode\Cache\Contract\TaggableCacheDriver;
use KaririCode\Cache\Contract\TopicAwareCacheDriver;

interface LayeredDriver extends
    TaggableCacheDriver,
    TopicAwareCacheDriver,
    AtomicOperable,
    CompareAndSwapAware,
    Maintainable
{
}
