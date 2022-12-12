<?php

namespace Espo\Tools\Export\Processors\Xlsx\CellValuePreparators;

use Espo\Tools\Export\Processors\Xlsx\CellValuePreparator;

class PersonName implements CellValuePreparator
{
    public function prepare(string $name, array $data): ?string
    {
        $name = $data[$name] ?? null;

        $arr = [];

        $firstName = $data['first' . ucfirst($name)];
        $lastName = $data['last' . ucfirst($name)];

        if ($firstName) {
            $arr[] = $firstName;
        }

        if ($lastName) {
            $arr[] = $lastName;
        }

        return implode(' ', $arr) ?: null;
    }
}
