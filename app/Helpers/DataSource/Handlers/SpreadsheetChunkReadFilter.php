<?php

namespace App\Helpers\DataHandlers;

use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

class SpreadsheetChunkReadFilter implements IReadFilter
{
    public function __construct(
        private int $startRow,
        private int $endRow
    ) {}

    public function readCell($columnAddress, $row, $worksheetName = ''): bool
    {
        return $row >= $this->startRow && $row <= $this->endRow;
    }
}
