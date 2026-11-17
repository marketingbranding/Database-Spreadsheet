<?php

namespace App\Services\Import;

class FixtureSheetReader implements SheetReader
{
    public function __construct(private readonly string $path)
    {
    }

    public function read(array $tabs): array
    {
        if (! is_file($this->path)) {
            throw new \RuntimeException("Fixture file not found: {$this->path}");
        }

        $payload = json_decode((string) file_get_contents($this->path), true, flags: JSON_THROW_ON_ERROR);
        $available = $payload['tabs'] ?? $payload;
        $result = [];

        foreach ($tabs as $tab) {
            $key = $this->resolveKey($available, $tab);
            if ($key === null) {
                throw new \RuntimeException("Fixture is missing tab [{$tab}].");
            }
            $result[$tab] = array_map(fn ($row) => array_values((array) $row), $available[$key]);
        }

        return $result;
    }

    public function sourceLabel(): string
    {
        return 'fixture:' . basename($this->path);
    }

    private function resolveKey(array $available, string $tab): ?string
    {
        if (array_key_exists($tab, $available)) {
            return $tab;
        }

        $normalized = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $tab) ?? $tab);
        foreach (array_keys($available) as $key) {
            if (strtolower(preg_replace('/[^a-z0-9]+/i', '_', (string) $key) ?? (string) $key) === $normalized) {
                return (string) $key;
            }
        }

        return null;
    }
}
