<?php

namespace App\Exceptions;

use RuntimeException;

class LotAllocationConflict extends RuntimeException
{
    public function __construct(string $message = 'Kavling tidak tersedia. Periksa booking aktif atau konflik yang belum ditinjau.')
    {
        parent::__construct($message);
    }
}
