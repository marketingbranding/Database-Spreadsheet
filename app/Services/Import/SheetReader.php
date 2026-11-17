<?php

namespace App\Services\Import;

interface SheetReader
{
    /**
     * @param  array<int, string>  $tabs
     * @return array<string, array<int, array<int, mixed>>>
     */
    public function read(array $tabs): array;

    public function sourceLabel(): string;
}
