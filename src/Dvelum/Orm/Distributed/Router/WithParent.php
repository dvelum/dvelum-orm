<?php

/**
 *  DVelum project https://github.com/dvelum/dvelum
 *  Copyright (C) 2011-2017  Kirill Yegorov
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

namespace Dvelum\Orm\Distributed\Router;

use Dvelum\Config\ConfigInterface;
use Dvelum\Orm\Distributed;
use Dvelum\Orm\Orm;
use Dvelum\Orm\RecordInterface;

class WithParent implements RouteInterface
{
    /**
     * @var ConfigInterface<int|string,mixed>
     */
    protected $config;

    protected Orm $orm;

    /**
     * @param Orm $orm
     * @param ConfigInterface<int|string,mixed> $config
     */
    public function __construct(Orm $orm, ConfigInterface $config)
    {
        $this->config = $config;
        $this->orm = $orm;
    }

    /**
     * Find shard for object
     * @param RecordInterface $record
     * @return null|string
     */
    public function getShard(RecordInterface $record): ?string
    {
        $parentObject = $this->config->get('parent');
        $parentField = $this->config->get('parent_field');
        $parentId = $record->get($parentField);
        $objectShard = '';

        if (!empty($parentId)) {
            $objectShard = $this->orm->distributed()->findObjectShard($this->orm->config($parentObject), $parentId);
        }

        if (empty($objectShard)) {
            $objectShard = null;
        }

        return $objectShard;
    }
}