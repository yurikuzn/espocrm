<?php
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM - Open Source CRM application.
 * Copyright (C) 2014-2022 Yurii Kuznietsov, Taras Machyshyn, Oleksii Avramenko
 * Website: https://www.espocrm.com
 *
 * EspoCRM is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * EspoCRM is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with EspoCRM. If not, see http://www.gnu.org/licenses/.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

namespace Espo\Tools\Export\Processors\Xlsx\CellValuePreparators;

use Espo\Core\Utils\Language;
use Espo\Tools\Export\Processors\Xlsx\CellValuePreparator;

class Duration implements CellValuePreparator
{
    public function __construct(private Language $language)
    {}

    public function prepare(string $name, array $data): ?string
    {
        $value = $data[$name] ?? null;

        if (!$value) {
            return null;
        }

        $seconds = intval($value);

        $days = intval(floor($seconds / 86400));
        $seconds = $seconds - $days * 86400;
        $hours = intval(floor($seconds / 3600));
        $seconds = $seconds - $hours * 3600;
        $minutes = intval(floor($seconds / 60));

        $value = '';

        if ($days) {
            $value .= $days . $this->language->translateLabel('d', 'durationUnits');

            if ($minutes || $hours) {
                $value .= ' ';
            }
        }

        if ($hours) {
            $value .= $hours . $this->language->translateLabel('h', 'durationUnits');

            if ($minutes) {
                $value .= ' ';
            }
        }

        if ($minutes) {
            $value .= $minutes . $this->language->translateLabel('m', 'durationUnits');
        }

        return $value;
    }
}
