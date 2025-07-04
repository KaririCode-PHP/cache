<?php

declare(strict_types=1);

namespace KaririCode\Cache\Util;

final class Dotenv
{
    private array $variables = [];
    private string $path;

    public function __construct(string $path)
    {
        $this->path = $path;
        $this->load();
    }

    private function load(): void
    {
        if (!file_exists($this->path)) {
            throw new \RuntimeException(sprintf('The .env file was not found at path: %s', $this->path));
        }

        $lines = file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $this->processLine($line);
        }
    }

    private function processLine(string $line): void
    {
        $trimmedLine = trim($line);

        if ($this->isComment($trimmedLine)) {
            return;
        }

        [$name, $value] = explode('=', $trimmedLine, 2) + [null, null];

        if (!isset($name, $value)) {
            return;
        }

        $name = $this->validateKey($name);
        $value = $this->parseValue($value);

        $this->setEnvironmentVariable($name, $value);
    }

    private function isComment(string $line): bool
    {
        return 0 === strpos($line, '#');
    }

    private function validateKey(string $key): string
    {
        $key = trim($key);
        if ('' === $key) {
            throw new \InvalidArgumentException('Invalid environment variable key.');
        }

        return $key;
    }

    private function setEnvironmentVariable(string $name, $value): void
    {
        if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
            $this->variables[$name] = $value;
            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }

    public function get(string $key, $default = null)
    {
        return $this->variables[$key] ?? $_ENV[$key] ?? $_SERVER[$key] ?? $default;
    }

    public function getInt(string $key, int $default = 0): int
    {
        return (int) $this->get($key, $default);
    }

    public function getFloat(string $key, float $default = 0.0): float
    {
        return (float) $this->get($key, $default);
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);

        return null === $value ? $default : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function getArray(string $key, string $delimiter = ',', array $default = []): array
    {
        $value = $this->get($key);

        return null === $value ? $default : array_map('trim', explode($delimiter, $value));
    }

    public function require(string $key)
    {
        $value = $this->get($key);
        if (null === $value) {
            throw new \RuntimeException(sprintf('The required environment variable "%s" is not defined.', $key));
        }

        return $value;
    }

    public function has(string $key): bool
    {
        return isset($this->variables[$key]) || isset($_ENV[$key]) || isset($_SERVER[$key]);
    }

    private function parseValue(string $value)
    {
        $value = trim($value);
        $lower = strtolower($value);

        return match ($lower) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            default => $this->stripQuotes($value)
        };
    }

    private function stripQuotes(string $value): string
    {
        if (preg_match('/\A([\'"])(.*)\1\z/', $value, $matches)) {
            return $matches[2];
        }

        return $value;
    }
}
