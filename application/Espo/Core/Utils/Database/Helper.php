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

namespace Espo\Core\Utils\Database;

use Doctrine\DBAL\Connection as DbalConnection;

use Espo\Core\ORM\PDO\PDOFactoryFactory;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Database\DBAL\ConnectionFactoryFactory as DBALConnectionFactoryFactory;
use Espo\ORM\DatabaseParams;

use PDO;
use RuntimeException;

class Helper
{
    public const TYPE_MYSQL = 'MySQL';
    public const TYPE_MARIADB = 'MariaDB';
    public const TYPE_POSTGRESQL = 'PostgreSQL';

    private const DEFAULT_PLATFORM = 'Mysql';

    private ?DbalConnection $dbalConnection = null;
    private ?PDO $pdoConnection = null;

    public function __construct(
        private Config $config,
        private PDOFactoryFactory $pdoFactoryFactory,
        private DBALConnectionFactoryFactory $dbalConnectionFactoryFactory
    ) {}

    public function getDbalConnection(): DbalConnection
    {
        if (!isset($this->dbalConnection)) {
            $this->dbalConnection = $this->createDbalConnection();
        }

        return $this->dbalConnection;
    }

    public function getPdoConnection(): PDO
    {
        if (!isset($this->pdoConnection)) {
            $this->pdoConnection = $this->createPdoConnection();
        }

        return $this->pdoConnection;
    }

    public function setPdoConnection(PDO $pdoConnection): void
    {
        $this->pdoConnection = $pdoConnection;
    }

    public function createDbalConnection(): DbalConnection
    {
        /** @var ?array<string, mixed> $params */
        $params = $this->config->get('database');

        if (empty($params)) {
            throw new RuntimeException('Database params cannot be empty for DBAL connection.');
        }

        $databaseParams = $this->createDatabaseParams($params);

        $platform = $databaseParams->getPlatform() ?? self::DEFAULT_PLATFORM;

        return $this->dbalConnectionFactoryFactory
            ->create($platform, $this->getPdoConnection())
            ->create($databaseParams);
    }

    /**
     * Create PDO connection.
     *
     * @param array<string, mixed> $params
     */
    public function createPdoConnection(array $params = [], bool $skipDatabaseName = false): PDO
    {
        $params = array_merge(
            $this->config->get('database') ?? [],
            $params
        );

        if ($skipDatabaseName && isset($params['dbname'])) {
            unset($params['dbname']);
        }

        $databaseParams = $this->createDatabaseParams($params);

        $platform = $databaseParams->getPlatform();

        $pdoFactory = $this->pdoFactoryFactory->create($platform ?? '');

        return $pdoFactory->create($databaseParams);
    }

    /**
     * @param array<string, mixed> $params
     * @throws RuntimeException
     */
    private function createDatabaseParams(array $params): DatabaseParams
    {
        $databaseParams = DatabaseParams::create()
            ->withHost($params['host'] ?? null)
            ->withPort(isset($params['port']) ? (int) $params['port'] : null)
            ->withName($params['dbname'] ?? null)
            ->withUsername($params['user'] ?? null)
            ->withPassword($params['password'] ?? null)
            ->withCharset($params['charset'] ?? 'utf8')
            ->withPlatform($params['platform'] ?? null)
            ->withSslCa($params['sslCA'] ?? null)
            ->withSslCert($params['sslCert'] ?? null)
            ->withSslKey($params['sslKey'] ?? null)
            ->withSslCaPath($params['sslCAPath'] ?? null)
            ->withSslCipher($params['sslCipher'] ?? null)
            ->withSslVerifyDisabled($params['sslVerifyDisabled'] ?? false);

        if (!$databaseParams->getPlatform()) {
            $databaseParams = $databaseParams->withPlatform(self::DEFAULT_PLATFORM);
        }

        return $databaseParams;
    }

    /**
     * Get a database type (MySQL, MariaDB, PostgreSQL).
     *
     * @todo Refactor.
     */
    public function getDatabaseType(): string
    {
        $version = $this->getFullDatabaseVersion() ?? '';

        if (preg_match('/mariadb/i', $version)) {
            return self::TYPE_MARIADB;
        }

        if (preg_match('/postgresql/i', $version)) {
            return self::TYPE_POSTGRESQL;
        }

        return self::TYPE_MYSQL;
    }

    private function getFullDatabaseVersion(): ?string
    {
        $connection = $this->getPdoConnection();

        $sth = $connection->prepare("select version()");

        $sth->execute();

        /** @var string|null|false $result */
        $result = $sth->fetchColumn();

        if ($result === false || $result === null) {
            return null;
        }

        return $result;
    }

    /**
     * Get a database version.
     *
     * @todo Add PostgreSQL support.
     */
    public function getDatabaseVersion(): ?string
    {
        $fullVersion = $this->getFullDatabaseVersion() ?? '';

        if (preg_match('/[0-9]+\.[0-9]+\.[0-9]+/', $fullVersion, $match)) {
            return $match[0];
        }

        return null;
    }

    /**
     * @todo Refactor.
     */
    public function getDatabaseParam(string $name): ?string
    {
        $databaseType = $this->getDatabaseType();

        if ($databaseType === self::TYPE_POSTGRESQL) {
            // @todo Implement.
            return null;
        }

        $sql = "SHOW VARIABLES LIKE :param";;

        $sth = $this->getPdoConnection()->prepare($sql);
        $sth->execute([':param' => $name]);

        $row = $sth->fetch(PDO::FETCH_NUM);

        $index = 1;

        $value = $row[$index] ?: null;

        if ($value === null) {
            return null;
        }

        return (string) $value;
    }

    /**
     * @todo Refactor.
     */
    public function getDatabaseServerVersion(): string
    {
        $databaseType = $this->getDatabaseType();

        $param = $databaseType === self::TYPE_POSTGRESQL ?
            'server_version' :
            'version';

        return (string) $this->getDatabaseParam($param);
    }
}
