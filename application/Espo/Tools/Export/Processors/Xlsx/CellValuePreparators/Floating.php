<?php

namespace Espo\Tools\Export\Processors\Xlsx\CellValuePreparators;

use Espo\Tools\Export\Processors\Xlsx\CellValuePreparator;

class Floating implements CellValuePreparator
{
    public function prepare(string $name, array $data): float
    {
        return $data[$name] ?? 0;
    }
}
