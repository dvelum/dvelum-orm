<?php

/**
 *  DVelum project https://github.com/dvelum/dvelum
 *  Copyright (C) 2011-2018  Kirill Yegorov
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */
declare(strict_types=1);

namespace Dvelum\Orm\Distributed\Key;


class Reserved
{
    protected ?string $shard = null;
    protected ?int $id = null;
    protected ?int $bucket = null;

    public function getBucket(): ?int
    {
        return $this->bucket;
    }

    public function setBucket(int $bucket): void
    {
        $this->bucket = $bucket;
    }

    public function getShard(): ?string
    {
        return $this->shard;
    }

    public function setShard(string $shard): void
    {
        $this->shard = $shard;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id): void
    {
        $this->id = $id;
    }
}