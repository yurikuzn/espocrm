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

namespace Espo\Core\Utils\Cache;

use Espo\Core\Utils\Cache\Exceptions\InvalidArgument;
use Espo\Core\Utils\File\Exceptions\FileError;
use Espo\Core\Utils\File\Manager as FileManager;
use InvalidArgumentException;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Supports only array and stdClass values.
 *
 * @since 10.1.0
 */
class FileCacheItemPool implements CacheItemPoolInterface
{
    private string $cacheDir = 'data/cache/application/';

    public function __construct(private FileManager $fileManager)
    {}

    /**
     * @throws InvalidArgument
     */
    public function getItem(string $key): CacheItem
    {
        $file = $this->getFile($key);

        try {
            $value = $this->fileManager->getPhpSafeContents($file);
        } catch (FileError) {
            return new CacheItem(
                key: $key,
                value: null,
                isHit: false,
            );
        }

        return new CacheItem(
            key: $key,
            value: $value,
            isHit: true,
        );
    }

    /**
     * @return iterable<CacheItem>
     */
    public function getItems(array $keys = []): iterable
    {
        $values = [];

        foreach ($keys as $key) {
            $values[] = $this->getItem($key);
        }

        return $values;
    }

    /**
     * @throws InvalidArgument
     */
    public function hasItem(string $key): bool
    {
        $file = $this->getFile($key);

        return $this->fileManager->isFile($file);
    }

    /**
     * @inheritDoc
     */
    public function clear(): bool
    {
        return $this->fileManager->removeInDir($this->cacheDir);
    }

    /**
     * @inheritDoc
     * @throws InvalidArgument
     */
    public function deleteItem(string $key): bool
    {
        $file = $this->getFile($key);

        return $this->fileManager->removeFile($file);
    }

    /**
     * @inheritDoc
     */
    public function deleteItems(array $keys): bool
    {
        $result = true;

        foreach ($keys as $key) {
            $result &= $this->deleteItem($key);
        }

        return (bool) $result;
    }

    /**
     * Persists a cache immediately.
     *
     * @todo Throw an error in DataCache if false.
     */
    public function save(CacheItemInterface $item): bool
    {
        $file = $this->getFile($item->getKey());

        $result = $this->fileManager->putPhpContents($file, $item->get(), true, true);

        if ($result === false) {
            return false;
        }

        return true;
    }

    /**
     * Sets a cache item to be persisted later.
     */
    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->save($item);
    }

    public function commit(): bool
    {
        return true;
    }

    private function getFile(string $key): string
    {
        if (
            $key === '' ||
            preg_match('/[^a-zA-Z0-9_\/\-]/i', $key) ||
            $key[0] === '/' ||
            str_ends_with($key, '/')
        ) {
            throw new InvalidArgumentException("Bad cache key.");
        }

        return $this->cacheDir . $key . '.php';
    }
}
