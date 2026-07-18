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

use Espo\Core\Utils\Cache\CacheItem;
use Espo\Core\Utils\Cache\Exceptions\InvalidArgument;
use Espo\Core\Utils\Cache\Exceptions\PersistenceError;
use Espo\Core\Utils\Cache\Exceptions\ReadError;
use Espo\Core\Utils\Cache\FileCacheItemPool;
use InvalidArgumentException;
use stdClass;

class DataCache
{
    public function __construct(
        private FileCacheItemPool $fileCacheItemPool,
    ) {}

    /**
     * Whether is cached.
     */
    public function has(string $key): bool
    {
        return $this->fileCacheItemPool->hasItem($key);
    }

    /**
     * Get a stored value. Returns null if not hit.
     *
     * @return array<int|string, mixed>|stdClass|null
     * @throws ReadError
     */
    public function get(string $key): array|stdClass|null
    {
        $item = $this->fileCacheItemPool->getItem($key);

        if (!$item->isHit()) {
            return null;
        }

        $value = $item->get();

        if (!is_array($value) && !$value instanceof stdClass) {
            throw new ReadError("Bad cache data by key '$key'.");
        }

        /** @var array<int|string, mixed>|stdClass */
        return $value;
    }

    /**
     * Try to get a stored value. Returns null if the item does not exist in cache.
     *
     * @return array<int|string, mixed>|stdClass|null
     * @since 9.3.0
     */
    public function tryGet(string $key)
    {
        if (!$this->has($key)) {
            return null;
        }

        try {
            return $this->get($key);
        } catch (ReadError) {
            return null;
        }
    }

    /**
     * Store in cache.
     *
     * @param array<int|string, mixed>|stdClass $data
     * @throws PersistenceError
     */
    public function store(string $key, $data): void
    {
        /** @phpstan-var mixed $data */

        if (!$this->checkDataIsValid($data)) {
            throw new InvalidArgumentException("Bad cache data type.");
        }

        $item = new CacheItem(
            key: $key,
            value: $data,
        );

        $result = $this->fileCacheItemPool->save($item);

        if ($result === false) {
            throw new PersistenceError("Could not store '$key'.");
        }
    }

    /**
     * Removes in cache.
     *
     * @throws InvalidArgument
     */
    public function clear(string $key): void
    {
        $this->fileCacheItemPool->deleteItem($key);
    }

    /**
     * @param mixed $data
     * @return bool
     */
    private function checkDataIsValid($data)
    {
        $isInvalid =
            !is_array($data) &&
            !$data instanceof stdClass;

        return !$isInvalid;
    }
}
