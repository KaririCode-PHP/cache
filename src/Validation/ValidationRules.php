<?php

declare(strict_types=1);

namespace KaririCode\Cache\Validation;

use KaririCode\Contract\ImmutableValue;

/**
 * Optimized ValidationRules with type safety and utility methods.
 *
 * This class provides a type-safe, immutable container for validation rules
 * with utility methods for rule manipulation and validation.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
final class ValidationRules implements ImmutableValue
{
    /** @var array<string, mixed> */
    private readonly array $rules;

    /**
     * @param array<string, mixed> $rules
     */
    public function __construct(array $rules)
    {
        $this->rules = $this->validateAndNormalizeRules($rules);
    }

    /**
     * Create ValidationRules with default cache key validation rules.
     */
    public static function forCacheKeys(): self
    {
        return new self([
            'max_key_length' => 250,
            'valid_pattern' => '/^[a-zA-Z0-9_.:-]+$/',
            'reserved_keys' => ['', 'null', 'true', 'false'],
            'cache_results' => true,
        ]);
    }

    /**
     * Create ValidationRules with relaxed validation for development.
     */
    public static function relaxed(): self
    {
        return new self([
            'max_key_length' => 500,
            'valid_pattern' => '/^[a-zA-Z0-9_.:\/-]+$/',
            'reserved_keys' => [''],
            'cache_results' => true,
        ]);
    }

    /**
     * Create ValidationRules with strict validation for production.
     */
    public static function strict(): self
    {
        return new self([
            'max_key_length' => 100,
            'valid_pattern' => '/^[a-zA-Z0-9_.-]+$/',
            'reserved_keys' => ['', 'null', 'true', 'false', 'undefined', 'NaN'],
            'cache_results' => true,
        ]);
    }

    public function getRule(string $name): mixed
    {
        return $this->rules[$name] ?? null;
    }

    public function hasRule(string $name): bool
    {
        return isset($this->rules[$name]);
    }

    /**
     * Get all rules as array.
     *
     * @return array<string, mixed>
     */
    public function getAllRules(): array
    {
        return $this->rules;
    }

    /**
     * Create new instance with additional rule.
     */
    public function withRule(string $name, mixed $value): self
    {
        $newRules = $this->rules;
        $newRules[$name] = $value;

        return new self($newRules);
    }

    /**
     * Create new instance without specified rule.
     */
    public function withoutRule(string $name): self
    {
        $newRules = $this->rules;
        unset($newRules[$name]);

        return new self($newRules);
    }

    /**
     * Create new instance with multiple rules.
     *
     * @param array<string, mixed> $rules
     */
    public function withRules(array $rules): self
    {
        $newRules = array_merge($this->rules, $rules);

        return new self($newRules);
    }

    /**
     * Get max key length rule.
     */
    public function getMaxKeyLength(): int
    {
        return ($this->rules['max_key_length'] ?? 250) * 1;
    }

    /**
     * Get valid pattern rule.
     */
    public function getValidPattern(): string
    {
        $pattern = $this->rules['valid_pattern'] ?? '/^[a-zA-Z0-9_.:-]+$/';

        // Por segurança, se o valor não for string usa o default
        return is_string($pattern) ? $pattern : '/^[a-zA-Z0-9_.:-]+$/';
    }

    /**
     * Get reserved keys rule.
     *
     * @return array<string>
     */
    public function getReservedKeys(): array
    {
        $keys = $this->rules['reserved_keys'] ?? ['', 'null', 'true', 'false'];

        return is_array($keys) ? $keys : [];
    }

    /**
     * Get cache results rule.
     */
    public function getCacheResults(): bool
    {
        return (bool) ($this->rules['cache_results'] ?? true);
    }

    /**
     * Check if rules are valid for cache key validation.
     */
    public function isValidForCacheKeys(): bool
    {
        $requiredRules = ['max_key_length', 'valid_pattern', 'reserved_keys'];

        foreach ($requiredRules as $rule) {
            if (!$this->hasRule($rule)) {
                return false;
            }
        }

        // Validate max_key_length
        $maxLength = $this->getMaxKeyLength();
        if ($maxLength <= 0 || $maxLength > 1000) {
            return false;
        }

        // Validate valid_pattern
        $pattern = $this->getValidPattern();
        if (false === @preg_match($pattern, 'test')) {
            return false;
        }

        // Validate reserved_keys
        $reservedKeys = $this->getReservedKeys();
        if (!is_array($reservedKeys)) {
            return false;
        }

        return true;
    }

    /**
     * Get rules formatted for JSON serialization.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->rules;
    }

    /**
     * Convert rules to JSON string.
     */
    public function toJson(): string
    {
        return json_encode($this->rules, JSON_THROW_ON_ERROR);
    }

    /**
     * Create ValidationRules from JSON string.
     */
    public static function fromJson(string $json): self
    {
        $rules = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($rules)) {
            throw new \InvalidArgumentException('JSON must decode to an array');
        }

        return new self($rules);
    }

    /**
     * Get rule count.
     */
    public function count(): int
    {
        return count($this->rules);
    }

    /**
     * Check if rules are empty.
     */
    public function isEmpty(): bool
    {
        return empty($this->rules);
    }

    /**
     * Get rule names.
     *
     * @return array<string>
     */
    public function getRuleNames(): array
    {
        return array_keys($this->rules);
    }

    /**
     * Merge with another ValidationRules instance.
     */
    public function merge(ValidationRules $other): self
    {
        $mergedRules = array_merge($this->rules, $other->getAllRules());

        return new self($mergedRules);
    }

    /**
     * Check if this instance has the same rules as another.
     */
    public function equals(ValidationRules $other): bool
    {
        return $this->rules === $other->getAllRules();
    }

    /**
     * Validate and normalize rules during construction.
     *
     * @param array<string, mixed> $rules
     *
     * @return array<string, mixed>
     */
    private function validateAndNormalizeRules(array $rules): array
    {
        $normalized = [];

        foreach ($rules as $name => $value) {
            if (!is_string($name) || '' === $name) {
                throw new \InvalidArgumentException('Rule names must be non-empty strings');
            }

            $normalized[$name] = $this->normalizeRuleValue($name, $value);
        }

        return $normalized;
    }

    /**
     * Normalize rule value based on rule name.
     */
    private function normalizeRuleValue(string $name, mixed $value): mixed
    {
        return match ($name) {
            'max_key_length' => $this->normalizeMaxKeyLength($value),
            'valid_pattern' => $this->normalizeValidPattern($value),
            'reserved_keys' => $this->normalizeReservedKeys($value),
            'cache_results' => $this->normalizeCacheResults($value),
            default => $value,
        };
    }

    /**
     * Normalize max key length value.
     */
    private function normalizeMaxKeyLength(mixed $value): int
    {
        $intValue = is_numeric($value) ? (int) $value : 250;

        if ($intValue <= 0 || $intValue > 1000) {
            throw new \InvalidArgumentException('max_key_length must be between 1 and 1000');
        }

        return $intValue;
    }

    /**
     * Normalize valid pattern value.
     */
    private function normalizeValidPattern(mixed $value): string
    {
        $pattern = is_string($value) ? $value : '/^[a-zA-Z0-9_.:-]+$/';

        if (false === @preg_match($pattern, 'test')) {
            throw new \InvalidArgumentException('valid_pattern must be a valid regular expression');
        }

        return $pattern;
    }

    /**
     * Normalize reserved keys value.
     *
     * @return array<string>
     */
    private function normalizeReservedKeys(mixed $value): array
    {
        if (!is_array($value)) {
            return ['', 'null', 'true', 'false'];
        }

        // Ensure all values are strings
        return array_map('strval', $value);
    }

    /**
     * Normalize cache results value.
     */
    private function normalizeCacheResults(mixed $value): bool
    {
        return (bool) $value;
    }
}
