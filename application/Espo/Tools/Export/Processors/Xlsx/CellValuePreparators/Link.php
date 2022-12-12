<?php

namespace Espo\Tools\Export\Processors\Xlsx\CellValuePreparators;

use Espo\Tools\Export\Processors\Xlsx\CellValuePreparator;

class Link implements CellValuePreparator
{
    public function prepare(string $name, array $data): ?string
    {
        /** @var ?string */
        return $data[$name . 'Name'] ?? null;
    }
}
