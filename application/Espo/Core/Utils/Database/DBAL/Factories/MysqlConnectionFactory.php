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

namespace Espo\Core\Utils\Database\DBAL\Factories;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDO\MySQL\Driver as PDOMySQLDriver;
use Doctrine\DBAL\Exception as DBALException;

use Espo\Core\Utils\Database\DBAL\ConnectionFactory;
use Espo\ORM\DatabaseParams;
use Espo\ORM\PDO\Options as PdoOptions;

use PDO;

class MysqlConnectionFactory implements ConnectionFactory
{
    public function __construct(
        private PDO $pdo
    ) {}

    /**
     * @throws DBALException
     */
    public function create(DatabaseParams $databaseParams): Connection
    {
        $driver = new PDOMySQLDriver();

        $version = $this->getFullDatabaseVersion() ?? '';
        $platform = $driver->createDatabasePlatformForVersion($version);

        $params = [
            'platform' => $platform,
            'host' => $databaseParams->getHost(),
            'port' => $databaseParams->getPort(),
            'dbname' => $databaseParams->getName(),
            'charset' => $databaseParams->getCharset(),
            'user' => $databaseParams->getUsername(),
            'password' => $databaseParams->getPassword(),
            'driverOptions' => PdoOptions::getOptionsFromDatabaseParams($databaseParams),
        ];

        return new Connection($params, $driver);
    }

    private function getFullDatabaseVersion(): ?string
    {
        $sth = $this->pdo->prepare("select version()");

        $sth->execute();

        /** @var string|null|false $result */
        $result = $sth->fetchColumn();

        if ($result === false || $result === null) {
            return null;
        }

        return $result;
    }
}
