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

namespace Espo\Core\ORM;

use Espo\ORM\Entity;
use Espo\ORM\Metadata;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\Repository;
use Espo\ORM\SqlExecutor;
use Espo\Core\Container;

class EntityManagerProxy
{
    private ?EntityManager $entityManager = null;

    public function __construct(private Container $container)
    {}

    private function getEntityManager(): EntityManager
    {
        if (!$this->entityManager) {
            /** @var EntityManager $entityManager */
            $entityManager = $this->container->get('entityManager');

            $this->entityManager = $entityManager;
        }

        return $this->entityManager;
    }

    public function getNewEntity(string $entityType): Entity
    {
        return $this->getEntityManager()->getNewEntity($entityType);
    }

    public function getEntityById(string $entityType, string $id): ?Entity
    {
        return $this->getEntityManager()->getEntityById($entityType, $id);
    }

    public function getEntity(string $entityType, ?string $id = null): ?Entity
    {
        return $this->getEntityManager()->getEntity($entityType, $id);
    }

    /**
     * @param array<mixed,string> $options
     * @return void
     */
    public function saveEntity(Entity $entity, array $options = [])
    {
        /** Return for backward compatibility. */
        /** @phpstan-ignore-next-line */
        return $this->getEntityManager()->saveEntity($entity, $options);
    }

    /**
     * @return Repository<Entity>
     */
    public function getRepository(string $entityType): Repository
    {
        return $this->getEntityManager()->getRepository($entityType);
    }

    /**
     * @return RDBRepository<Entity>
     */
    public function getRDBRepository(string $entityType): RDBRepository
    {
        return $this->getEntityManager()->getRDBRepository($entityType);
    }

    public function getMetadata(): Metadata
    {
        return $this->getEntityManager()->getMetadata();
    }

    public function getSqlExecutor(): SqlExecutor
    {
        return $this->getEntityManager()->getSqlExecutor();
    }

    /**
     * Get an RDB repository by an entity class name.
     *
     * @template T of Entity
     * @param class-string<T> $className An entity class name.
     * @return RDBRepository<T>
     */
    public function getRDBRepositoryByClass(string $className): RDBRepository
    {
        return $this->getEntityManager()->getRDBRepositoryByClass($className);
    }

    /**
     * Get a repository by an entity class name.
     *
     * @template T of Entity
     * @param class-string<T> $className An entity class name.
     * @return Repository<T>
     */
    public function getRepositoryByClass(string $className): Repository
    {
        return $this->getEntityManager()->getRepositoryByClass($className);
    }
}
