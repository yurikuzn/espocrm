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

namespace Espo\Tools\Pdf;

use LogicException;
use ZipArchive;

class Zipper
{
    private ?string $filePath = null;
    /** @var array{string, string}[] */
    private array $itemList = [];

    public function __construct() {}

    public function add(Contents $contents, string $name): void
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'espo-pdf-zip-item');

        $fp = fopen($tempPath, 'w');
        fwrite($fp, $contents->getString());
        fclose($fp);

        $this->itemList[] = [$tempPath, $name . '.pdf'];
    }

    public function archive(): void
    {
        $this->filePath = tempnam(sys_get_temp_dir(), 'espo-pdf-zip');

        $archive = new ZipArchive();
        $archive->open($this->filePath, ZipArchive::CREATE);

        foreach ($this->itemList as $item) {
            $archive->addFile($item[0], $item[1]);
        }

        $archive->close();
    }

    public function getFilePath(): string
    {
        if (!$this->filePath) {
            throw new LogicException();
        }

        return $this->filePath;
    }
}
