<?php

namespace Espo\Tools\Export\Processors\Xlsx;

use Espo\Core\Field\Currency;
use Espo\Core\Field\Date;
use Espo\Core\Field\DateTime;

interface CellValuePreparator
{
    /**
     * @param string $name A field name.
     * @param array<string, mixed> $data An attribute-value map.
     */
    public function prepare(string $name, array $data): string|bool|int|float|Date|DateTime|Currency|null;
}
