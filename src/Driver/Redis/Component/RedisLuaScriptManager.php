<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Redis\Component;

use KaririCode\Cache\Driver\Redis\Validation\Trait\RedisResultValidatorTrait;

final class RedisLuaScriptManager
{
    use RedisResultValidatorTrait;

    private array $scriptHashes = [];

    public function __construct(private \Redis $redis)
    {
        $this->loadScripts();
    }

    public function executeIncrementWithTtl(string $key, int $step, int $initialValue, int $ttl): int
    {
        $args = [$step, $initialValue, $ttl];
        $result = $this->redis->evalSha(
            $this->scriptHashes['incrementWithTtl'],
            array_merge([$key], $args),
            1
        );

        return $this->ensureNumericResult($result, 'evalSha:incrementWithTtl', $key);
    }

    public function executeCompareAndSwap(string $key, string $expected, string $value, int $ttl): int
    {
        $args = [$expected, $value, $ttl];
        $result = $this->redis->evalSha(
            $this->scriptHashes['compareAndSwap'],
            array_merge([$key], $args),
            1
        );

        return $this->ensureNumericResult($result, 'evalSha:compareAndSwap', $key);
    }

    private function loadScripts(): void
    {
        $scripts = [
            'incrementWithTtl' => '
                local current = redis.call("GET", KEYS[1])
                if current == false then
                    current = tonumber(ARGV[2])
                else
                    current = tonumber(current)
                end
                local newValue = current + tonumber(ARGV[1])
                redis.call("SET", KEYS[1], newValue, "EX", tonumber(ARGV[3]))
                return newValue
            ',
            'compareAndSwap' => '
                local current = redis.call("GET", KEYS[1])
                local expected = ARGV[1]
                
                if (current == false and expected == "N;") or (current == expected) then
                    if tonumber(ARGV[3]) > 0 then
                        redis.call("SET", KEYS[1], ARGV[2], "EX", tonumber(ARGV[3]))
                    else
                        redis.call("SET", KEYS[1], ARGV[2])
                    end
                    return 1
                else
                    return 0
                end
            ',
        ];

        foreach ($scripts as $name => $script) {
            $this->scriptHashes[$name] = $this->redis->script('load', $script);
        }
    }
}
