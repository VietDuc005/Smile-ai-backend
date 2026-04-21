<?php

declare(strict_types=1);

namespace App\Services;

final class EnvService
{
    private bool $loaded = false;

    private string $path;

    private array $values = [];

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env';
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);

        if ($value !== false) {
            return $value;
        }

        $this->load();

        return $this->values[$key] ?? $default;
    }

    public function int(string $key, int $default = 0): int
    {
        return (int) $this->get($key, $default);
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;

        if (!is_file($this->path)) {
            return;
        }

        $lines = file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if (!is_array($lines)) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = explode('=', $line, 2);

            if (count($parts) !== 2) {
                continue;
            }

            [$key, $value] = $parts;
            $this->values[trim($key)] = $this->normalizeValue(trim($value));
        }
    }

    private function normalizeValue(string $value): string
    {
        $length = strlen($value);

        if ($length >= 2) {
            $first = $value[0];
            $last = $value[$length - 1];

            if (($first === '"' && $last === '"') || ($first === '\'' && $last === '\'')) {
                return substr($value, 1, -1);
            }
        }

        return $value;
    }
}
