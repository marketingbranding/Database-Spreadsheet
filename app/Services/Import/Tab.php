<?php

namespace App\Services\Import;

use App\Support\Normalizer;
use Carbon\CarbonImmutable;

class Tab
{
    /** @var array<int, array<int, mixed>> */
    public array $rows;

    /** @var array<int, string> */
    public array $headers = [];

    /** @var array<int, array<int, mixed>> */
    public array $data = [];

    public function __construct(public readonly string $name, array $rows)
    {
        $this->rows = array_values($rows);
        $this->headers = array_map(
            fn ($h) => Normalizer::header($h),
            array_values($this->rows[0] ?? [])
        );
        $this->data = array_slice($this->rows, 1);
    }

    public function count(): int
    {
        return count($this->data);
    }

    public function headerIndex(string|array $aliases, bool $required = false): ?int
    {
        $aliases = (array) $aliases;

        foreach ($aliases as $alias) {
            $target = Normalizer::header($alias);
            foreach ($this->headers as $index => $header) {
                if ($header === $target) {
                    return $index;
                }
            }
        }

        foreach ($aliases as $alias) {
            $target = Normalizer::header($alias);
            foreach ($this->headers as $index => $header) {
                if ($target !== '' && str_contains($header, $target)) {
                    return $index;
                }
            }
        }

        return null;
    }

    public function value(array $row, string|array $aliases): mixed
    {
        $index = $this->headerIndex($aliases);

        return $index === null ? null : ($row[$index] ?? null);
    }

    public function text(array $row, string|array $aliases): ?string
    {
        return Normalizer::text($this->value($row, $aliases));
    }

    public function date(array $row, string|array $aliases): ?CarbonImmutable
    {
        return Normalizer::date($this->value($row, $aliases));
    }

    public function number(array $row, string|array $aliases): ?float
    {
        return Normalizer::number($this->value($row, $aliases));
    }

    public function integer(array $row, string|array $aliases): ?int
    {
        return Normalizer::integer($this->value($row, $aliases));
    }

    public function flag(array $row, string|array $aliases): bool
    {
        return Normalizer::bool($this->value($row, $aliases));
    }

    /**
     * @return array<int, array{row: int, data: array<int, mixed>}>
     */
    public function records(): array
    {
        $records = [];
        foreach ($this->data as $offset => $row) {
            $nonEmpty = false;
            foreach ($row as $value) {
                if (Normalizer::text($value) !== null) {
                    $nonEmpty = true;
                    break;
                }
            }
            if (! $nonEmpty) {
                continue;
            }
            $records[] = ['row' => $offset + 2, 'data' => array_values($row)];
        }

        return $records;
    }
}
