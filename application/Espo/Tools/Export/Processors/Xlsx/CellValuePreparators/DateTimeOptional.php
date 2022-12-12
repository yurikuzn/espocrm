<?php

namespace Espo\Tools\Export\Processors\Xlsx\CellValuePreparators;

use Espo\Core\Field\DateTime as DateTimeValue;
use Espo\Core\Field\Date as DateValue;
use Espo\Core\Utils\Config;
use Espo\Tools\Export\Processors\Xlsx\CellValuePreparator;

use DateTimeZone;

class DateTimeOptional implements CellValuePreparator
{
    private string $timezone;

    public function __construct(Config $config)
    {
        $this->timezone = $config->get('timeZone') ?? 'UTC';
    }

    public function prepare(string $name, array $data): DateTimeValue|DateValue|null
    {
        $dateValue = $data[$name . 'Date'] ?? null;

        if ($dateValue !== null) {
            return DateValue::fromString($dateValue);
        }

        $value = $data[$name] ?? null;

        if (!$value) {
            return null;
        }

        return DateTimeValue::fromString($value)
            ->withTimezone(
                new DateTimeZone($this->timezone)
            );
    }
}
