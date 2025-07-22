<?php

declare(strict_types=1);

namespace KaririCode\Cache\Driver\Redis\Component;

/**
 * Manages Lua scripts for Redis operations.
 * Single Responsibility: Lua script management and execution.
 */
final class RedisLuaScriptManager
{
    private array $scriptHashes = [];

    public function __construct(private \Redis $redis)
    {
        $this->loadScripts();
    }

    public function executeIncrementWithTtl(string $key, int $step, int $initialValue, int $ttl): int
    {
        return $this->redis->evalSha(
            $this->scriptHashes['incrementWithTtl'],
            [$key],
            [$step, $initialValue, $ttl]
        );
    }

    public function executeCompareAndSwap(string $key, string $expected, string $value, int $ttl): int
    {
        return $this->redis->evalSha(
            $this->scriptHashes['compareAndSwap'],
            [$key],
            [$expected, $value, $ttl]
        );
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
