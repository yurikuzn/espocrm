<?php
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM - Open Source CRM application.
 * Copyright (C) 2014-2023 Yurii Kuznietsov, Taras Machyshyn, Oleksii Avramenko
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

namespace Espo\Core\Utils\Database\Schema\ColumnOptionsPreparators;

use Espo\Core\Utils\Database\Helper;
use Espo\Core\Utils\Database\Schema\ColumnOptions;
use Espo\Core\Utils\Database\Schema\ColumnOptionsPreparator;
use Espo\Core\Utils\Database\Schema\PlatformOptions;
use Espo\ORM\Defs\AttributeDefs;
use Espo\ORM\Entity;

class MysqlColumnOptionsPreparator implements ColumnOptionsPreparator
{
    private const PARAM_DB_TYPE = 'dbType';
    private const PARAM_DEFAULT = 'default';
    private const PARAM_NOT_NULL = 'notNull';
    private const PARAM_AUTOINCREMENT = 'autoincrement';
    private const PARAM_PRECISION = 'precision';
    private const PARAM_SCALE = 'scale';
    private const PARAM_BINARY = 'binary';

    private ?int $maxIndexLength = null;

    private const MB4_INDEX_LENGTH_LIMIT = 3072;

    public function __construct(
        // Pass in a binding container.
        private Helper $helper
    ) {}

    public function prepare(AttributeDefs $defs): ColumnOptions
    {
        $columnType = $defs->getParam(self::PARAM_DB_TYPE) ?? $defs->getType();

        $options = ColumnOptions::create(strtolower($columnType));

        $type = $defs->getType();
        $length = $defs->getLength();
        $default = $defs->getParam(self::PARAM_DEFAULT);
        $notNull = $defs->getParam(self::PARAM_NOT_NULL);
        $autoincrement = $defs->getParam(self::PARAM_AUTOINCREMENT);
        $precision = $defs->getParam(self::PARAM_PRECISION);
        $scale = $defs->getParam(self::PARAM_SCALE);
        $binary = $defs->getParam(self::PARAM_BINARY);

        if ($length !== null) {
            $options = $options->withLength($length);
        }

        if ($default !== null) {
            $options = $options->withDefault($default);
        }

        if ($notNull !== null) {
            $options = $options->withNotNull($notNull);
        }

        if ($autoincrement !== null) {
            $options = $options->withAutoincrement($autoincrement);
        }

        if ($precision !== null) {
            $options = $options->withPrecision($precision);
        }

        if ($scale !== null) {
            $options = $options->withScale($scale);
        }

        $mb3 = false;

        switch ($type) {
            case Entity::ID:
            case Entity::FOREIGN_ID:
            case Entity::FOREIGN_TYPE:
                $mb3 = $this->getMaxIndexLength() < self::MB4_INDEX_LENGTH_LIMIT;

                break;

            case Entity::TEXT:
            case Entity::JSON_ARRAY:
                $options = $options->withDefault(null);

                break;

            case Entity::BOOL:
                $default = intval($default ?? false);

                $options = $options->withDefault($default);

                break;
        }

        if ($type !== Entity::ID && $autoincrement) {
            $options = $options
                ->withNotNull()
                ->withUnsigned();
        }

        $collation = $binary ?
            'utf8mb4_bin' :
            'utf8mb4_unicode_ci';

        if ($mb3) {
            $collation = $binary ?
                'utf8_bin' :
                'utf8_unicode_ci';
        }

        $platformOptions = PlatformOptions::create()->withCollation($collation);

        return $options->withPlatformOptions($platformOptions);
    }

    private function getMaxIndexLength(): int
    {
        if (!isset($this->maxIndexLength)) {
            $this->maxIndexLength = $this->helper->getMaxIndexLength();
        }

        return $this->maxIndexLength;
    }
}
