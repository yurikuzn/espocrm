<?php

namespace Espo\Tools\Export\Processors\Xlsx\CellValuePreparators;

use Espo\Tools\Export\Processors\Xlsx\CellValuePreparator;

class General implements CellValuePreparator
{
    /**
     * @inheritDoc
     */
    public function prepare(string $name, array $data): string|bool|int|float|null
    {
        $value = $data[$name] ?? null;

        if ($value === null) {
            return null;
        }

        if (
            !is_string($value) &&
            !is_int($value) &&
            !is_float($value) &&
            !is_bool($value)
        ) {
            return null;
        }

        return $value;
    }
}
