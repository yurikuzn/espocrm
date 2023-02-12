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

namespace Espo\Core\Utils\Database\Dbal\Platforms;

use Doctrine\DBAL\Platforms\PostgreSQL100Platform;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Table;

class PostgresqlPlatform extends PostgreSQL100Platform
{
    public function getCreateIndexSQL(Index $index, $table)
    {
        if (!$index->hasFlag('fulltext')) {
            return parent::getCreateIndexSQL($index, $table);
        }

        if ($table instanceof Table) {
            $table = $table->getQuotedName($this);
        }

        $name = $index->getQuotedName($this);
        $columns = $index->getColumns();

        if (count($columns) === 0) {
            throw new \InvalidArgumentException(sprintf(
                'Incomplete or invalid index definition %s on table %s',
                $name,
                $table,
            ));
        }

        $columnsPart = implode(" || ' ' || ", $index->getQuotedColumns($this));
        $partialPart = $this->getPartialIndexSQL($index);

        $query = "CREATE INDEX {$name} ON {$table} USING GIN (TO_TSVECTOR('english', {$columnsPart})) {$partialPart}";

        return $query;
    }
}