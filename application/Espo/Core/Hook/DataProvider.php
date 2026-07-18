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

namespace Espo\Core\Hook;

use Espo\Core\Utils\Config\SystemConfig;
use Espo\Core\Utils\DataCache;
use Espo\Core\Utils\File\Manager as FileManager;
use Espo\Core\Utils\Metadata;
use Espo\Core\Utils\Module\PathProvider;
use Espo\Core\Utils\Util;
use LogicException;

/**
 * @internal
 * @since 10.1.0
 *
 * To be re-used between requests.
 */
class DataProvider
{
    private const int DEFAULT_ORDER = 9;

    /** @var ?array<string, array<string, mixed>> */
    private $data = null;

    private string $cacheKey = 'hooks';

    /** @var string[] */
    private $ignoredMethodList = [
        '__construct',
        'getDependencyList',
        'inject',
    ];

    public function __construct(
        private SystemConfig $systemConfig,
        private FileManager $fileManager,
        private PathProvider $pathProvider,
        private DataCache $dataCache,
        private Metadata $metadata,
    ) {}

    /**
     * @return array<string, array<string, mixed>>
     */
    public function get(): array
    {
        if (!$this->data) {
            $this->load();
        }

        return $this->data ?? throw new LogicException();
    }

    private function load(): void
    {
        if ($this->systemConfig->useCache() && $this->dataCache->has($this->cacheKey)) {
            /** @var ?array<string, array<string, mixed>> $cachedData */
            $cachedData = $this->dataCache->get($this->cacheKey);

            if ($cachedData !== null) {
                $this->data = $cachedData;

                return;
            }
        }

        $data = $this->readHookData($this->pathProvider->getCustom() . 'Hooks');

        foreach ($this->metadata->getModuleList() as $moduleName) {
            $modulePath = $this->pathProvider->getModule($moduleName) . 'Hooks';

            $data = $this->readHookData($modulePath, $data);
        }

        $data = $this->readHookData($this->pathProvider->getCore() . 'Hooks', $data);

        $this->data = $this->sortHooks($data);

        if ($this->systemConfig->useCache()) {
            $this->dataCache->store($this->cacheKey, $this->data);
        }
    }

    /**
     * @param string $hookDir
     * @param array<string, array<string, mixed>> $hookData
     * @return array<string, array<string, mixed>>
     */
    private function readHookData(string $hookDir, array $hookData = []): array
    {
        if (!$this->fileManager->exists($hookDir)) {
            return $hookData;
        }

        /** @var array<string, string[]> $fileList */
        $fileList = $this->fileManager->getFileList($hookDir, 1, '\.php$', true);

        foreach ($fileList as $scopeName => $hookFiles) {
            $hookScopeDirPath = Util::concatPath($hookDir, $scopeName);
            $normalizedScopeName = Util::normalizeScopeName($scopeName);

            foreach ($hookFiles as $hookFile) {
                $hookFilePath = Util::concatPath($hookScopeDirPath, $hookFile);
                $className = Util::getClassName($hookFilePath);

                $classMethods = get_class_methods($className);

                $hookMethods = array_diff($classMethods, $this->ignoredMethodList);

                /** @var string[] $hookMethods */
                $hookMethods = array_filter($hookMethods, function ($item) {
                    if (str_starts_with($item, 'set')) {
                        return false;
                    }

                    return true;
                });

                foreach ($hookMethods as $hookType) {
                    $entityHookData = $hookData[$normalizedScopeName][$hookType] ?? [];

                    if ($this->hookExists($className, $entityHookData)) {
                        continue;
                    }

                    if ($this->hookClassIsSuppressed($className)) {
                        continue;
                    }

                    $hookData[$normalizedScopeName][$hookType][] = [
                        'className' => $className,
                        'order' => $className::$order ?? self::DEFAULT_ORDER,
                    ];
                }
            }
        }

        return $hookData;
    }

    /**
     * Check if hook exists in the list.
     *
     * @param class-string $className
     * @param array<string, mixed> $hookData
     */
    private function hookExists(string $className, array $hookData): bool
    {
        $class = preg_replace('/^.*\\\(.*)$/', '$1', $className);

        foreach ($hookData as $item) {
            if (preg_match('/\\\\'.$class.'$/', $item['className'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sort hooks by the order parameter.
     *
     * @param array<string, array<string, mixed>> $hooks
     * @return array<string, array<string, mixed>>
     */
    private function sortHooks(array $hooks): array
    {
        foreach ($hooks as &$scopeHooks) {
            foreach ($scopeHooks as &$hookList) {
                usort($hookList, [$this, 'cmpHooks']);
            }
        }

        return $hooks;
    }

    /**
     * @param class-string $className
     */
    private function hookClassIsSuppressed(string $className): bool
    {
        $suppressList = $this->metadata->get(['app', 'hook', 'suppressClassNameList']) ?? [];

        return in_array($className, $suppressList);
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private function cmpHooks($a, $b): int
    {
        if ($a['order'] == $b['order']) {
            return 0;
        }

        return ($a['order'] < $b['order']) ? -1 : 1;
    }
}
