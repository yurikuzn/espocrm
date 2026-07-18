<?php
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM – Open Source CRM application.
 * Copyright (C) 2014-2026 EspoCRM, Inc.
 * Website: https://www.espocrm.com
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU Affero General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU Affero General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

namespace Espo\Core\Utils;

use Espo\Core\ORM\EntityManager;
use Espo\Core\Mail\FiltersMatcher;
use Espo\Core\Utils\Config\SystemConfig;
use Espo\Core\Utils\User\UserStateProvider;
use Espo\Entities\Email;
use Espo\Entities\EmailFilter;
use Espo\Entities\User;
use Espo\ORM\Query\Part\Expression;
use Espo\ORM\Query\Part\Order;
use LogicException;
use stdClass;

/**
 * Looks for any matching Email Filter for a given email and user.
 */
class EmailFilterManager
{
    /** @var array<string, EmailFilter[]> */
    private array $data = [];
    private bool $useCache;

    /** @var array<string, int> */
    private array $cacheVersionMap = [];

    private const string CACHE_KEY = 'emailFilters';

    public function __construct(
        private EntityManager $entityManager,
        private FiltersMatcher $filtersMatcher,
        private DataCache $dataCache,
        SystemConfig $systemConfig,
        private UserStateProvider $userStateProvider,
    ) {
        $this->useCache = $systemConfig->useCache();
    }

    public function getMatchingFilter(Email $email, string $userId): ?EmailFilter
    {
        $filters = $this->get($userId);

        return $this->filtersMatcher->findMatch($email, $filters);
    }

    /**
     * @return EmailFilter[]
     */
    private function get(string $userId): array
    {
        if (array_key_exists($userId, $this->data) && $this->cacheIsRelevant($userId)) {
            return $this->data[$userId];
        }

        unset($this->data[$userId]);

        $cacheKey = $this->composeCacheKey($userId);

        if ($this->useCache && $this->dataCache->has($cacheKey)) {
            $cached = $this->loadFromCache($cacheKey);

            if ($cached !== null) {
                $this->data[$userId] = $cached;

                $this->setCacheVersionNumber($userId);

                return $this->data[$userId];
            }
        }

        $this->data[$userId] = $this->fetch($userId);

        if ($this->useCache) {
            $this->storeToCache($userId);
        }

        $this->setCacheVersionNumber($userId);

        return $this->data[$userId];
    }

    private function composeCacheKey(string $userId): string
    {
        return self::CACHE_KEY . '/' . $userId;
    }

    /**
     * @return EmailFilter[]
     */
    private function fetch(string $userId): array
    {
        $collection = $this->entityManager
            ->getRDBRepository(EmailFilter::ENTITY_TYPE)
            ->where([
                'parentId' => $userId,
                'parentType' => User::ENTITY_TYPE,
            ])
            ->order(
                Order::createByPositionInList(
                    Expression::column('action'),
                    [
                        EmailFilter::ACTION_SKIP,
                        EmailFilter::ACTION_MOVE_TO_FOLDER,
                        EmailFilter::ACTION_NONE,
                    ]
                )
            )
            ->find();

        return iterator_to_array($collection);
    }

    /**
     * @return ?EmailFilter[]
     */
    private function loadFromCache(string $cacheKey): ?array
    {
        /** @var stdClass[] $dataList */
        $dataList = $this->dataCache->get($cacheKey);

        if ($dataList === null) {
            return null;
        }

        /** @var EmailFilter[] $list */
        $list = [];

        foreach ($dataList as $item) {
            $entity = $this->entityManager->getNewEntity(EmailFilter::ENTITY_TYPE);

            $entity->set($item);
            $entity->setAsNotNew();

            $list[] = $entity;
        }

        return $list;
    }

    private function storeToCache(string $userId): void
    {
        if (!array_key_exists($userId, $this->data)) {
            throw new LogicException();
        }

        $dataList = [];

        foreach ($this->data[$userId] as $entity) {
            $dataList[] = $entity->getValueMap();
        }

        $cacheKey = $this->composeCacheKey($userId);

        $this->dataCache->store($cacheKey, $dataList);
    }

    private function setCacheVersionNumber(string $userId): void
    {
        $this->cacheVersionMap[$userId] = $this->userStateProvider->getEmailFiltersVersionNumber($userId);
    }

    private function cacheIsRelevant(string $userId): bool
    {
        $versionNumber = $this->cacheVersionMap[$userId] ?? null;

        if ($versionNumber === null) {
            return false;
        }

        return $versionNumber === $this->userStateProvider->getEmailFiltersVersionNumber($userId);
    }
}
