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

namespace Espo\Core\Utils\Database\Schema\ColumnPreparators;

use Espo\Core\Utils\Database\Helper;
use Espo\Core\Utils\Database\Schema\Column;
use Espo\Core\Utils\Database\Schema\ColumnPreparator;
use Espo\Core\Utils\Database\Schema\PlatformOptions;
use Espo\Core\Utils\Util;
use Espo\ORM\Defs\AttributeDefs;
use Espo\ORM\Entity;

class MysqlColumnPreparator implements ColumnPreparator
{
    private const PARAM_DB_TYPE = 'dbType';
    private const PARAM_DEFAULT = 'default';
    private const PARAM_NOT_NULL = 'notNull';
    private const PARAM_AUTOINCREMENT = 'autoincrement';
    private const PARAM_PRECISION = 'precision';
    private const PARAM_SCALE = 'scale';
    private const PARAM_BINARY = 'binary';

    public const TYPE_MYSQL = 'MySQL';
    public const TYPE_MARIADB = 'MariaDB';

    private const MB4_INDEX_LENGTH_LIMIT = 3072;
    private const DEFAULT_INDEX_LIMIT = 1000;

    private ?int $maxIndexLength = null;

    public function __construct(
        private Helper $helper
    ) {}

    public function prepare(AttributeDefs $defs): Column
    {
        $columnType = $defs->getParam(self::PARAM_DB_TYPE) ?? $defs->getType();
        $columnName = Util::toUnderScore($defs->getName());

        $options = Column::create($columnName, strtolower($columnType));

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
            $this->maxIndexLength = $this->detectMaxIndexLength();
        }

        return $this->maxIndexLength;
    }

    /**
     * Get maximum index length.
     */
    private function detectMaxIndexLength(): int
    {
        $databaseType = $this->helper->getType();

        $tableEngine = $this->getTableEngine();

        if (!$tableEngine) {
            return self::DEFAULT_INDEX_LIMIT;
        }

        switch ($tableEngine) {
            case 'InnoDB':
                $version = $this->helper->getVersion();

                switch ($databaseType) {
                    case self::TYPE_MARIADB:
                        if (version_compare($version, '10.2.2') >= 0) {
                            return 3072; // InnoDB, MariaDB 10.2.2+
                        }

                        break;

                    case self::TYPE_MYSQL:
                        return 3072;
                }

                return 767; // InnoDB
        }

        return 1000; // MyISAM
    }

    /**
     * Get a table or default engine.
     */
    private function getTableEngine(): ?string
    {
        $databaseType = $this->helper->getType();

        if (!in_array($databaseType, [self::TYPE_MYSQL, self::TYPE_MARIADB])) {
            return null;
        }

        $query = "SHOW TABLE STATUS WHERE Engine = 'MyISAM'";

        $vars = [];

        $pdo = $this->helper->getPDO();

        $sth = $pdo->prepare($query);
        $sth->execute($vars);

        $result = $sth->fetchColumn();

        if (!empty($result)) {
            return 'MyISAM';
        }

        return 'InnoDB';
    }
}
