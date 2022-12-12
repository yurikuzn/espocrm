<?php

namespace Espo\Tools\Export\Processors\Xlsx\CellValuePreparators;

use Espo\Tools\Export\Processors\Xlsx\CellValuePreparator;

class Integer implements CellValuePreparator
{
    public function prepare(string $name, array $data): int
    {
        /** @var int */
        return $data[$name] ?? 0;
    }
}
