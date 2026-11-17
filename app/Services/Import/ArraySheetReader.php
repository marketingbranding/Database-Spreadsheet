<?php

namespace App\Services\Import;

/**
 * In-memory reader used by tests and local verification.
 */
class ArraySheetReader implements SheetReader
{
    /**
     * @param  array<string, array<int, array<int, mixed>>>  $tabs
     */
    public function __construct(private readonly array $tabs, private readonly string $label = 'array')
    {
    }

    public function read(array $tabs): array
    {
        $result = [];
        foreach ($tabs as $tab) {
            if (! array_key_exists($tab, $this->tabs)) {
                throw new \RuntimeException("Array source is missing tab [{$tab}].");
            }
            $result[$tab] = $this->tabs[$tab];
        }

        return $result;
    }

    public function sourceLabel(): string
    {
        return $this->label;
    }
}
