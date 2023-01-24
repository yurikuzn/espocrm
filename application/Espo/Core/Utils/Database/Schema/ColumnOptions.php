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

namespace Espo\Core\Utils\Database\Schema;

class ColumnOptions
{
    private bool $notNull = false;
    private ?int $length = null;
    private mixed $default = null;
    private ?bool $autoincrement = null;
    private ?int $precision = null;
    private ?int $scale = null;
    private ?bool $unsigned = null;
    private ?PlatformOptions $platformOptions = null;

    private function __construct(private string $type) {}

    public static function create(string $type): self
    {
        return new self($type);
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function isNotNull(): bool
    {
        return $this->notNull;
    }

    public function getLength(): ?int
    {
        return $this->length;
    }

    public function getDefault(): mixed
    {
        return $this->default;
    }

    public function getAutoincrement(): ?bool
    {
        return $this->autoincrement;
    }

    public function getUnsigned(): ?bool
    {
        return $this->unsigned;
    }

    public function getPrecision(): ?int
    {
        return $this->precision;
    }

    public function getScale(): ?int
    {
        return $this->scale;
    }

    public function getPlatformOptions(): ?PlatformOptions
    {
        return $this->platformOptions;
    }

    public function withNotNull(bool $notNull = true): self
    {
        $obj = clone $this;
        $obj->notNull = $notNull;

        return $obj;
    }

    public function withLength(?int $length): self
    {
        $obj = clone $this;
        $obj->length = $length;

        return $obj;
    }

    public function withDefault(mixed $default): self
    {
        $obj = clone $this;
        $obj->default = $default;

        return $obj;
    }

    public function withAutoincrement(?bool $autoincrement = true): self
    {
        $obj = clone $this;
        $obj->autoincrement = $autoincrement;

        return $obj;
    }

    public function withUnsigned(?bool $unsigned = true): self
    {
        $obj = clone $this;
        $obj->unsigned = $unsigned;

        return $obj;
    }

    public function withPrecision(?int $precision): self
    {
        $obj = clone $this;
        $obj->precision = $precision;

        return $obj;
    }

    public function withScale(?int $scale): self
    {
        $obj = clone $this;
        $obj->scale = $scale;

        return $obj;
    }

    public function withPlatformOptions(?PlatformOptions $platformOptions): self
    {
        $obj = clone $this;
        $obj->platformOptions = $platformOptions;

        return $obj;
    }
}
