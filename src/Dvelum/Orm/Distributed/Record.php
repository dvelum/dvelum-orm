<?php

/**
 *  DVelum project https://github.com/dvelum/dvelum
 *  Copyright (C) 2011-2021  Kirill Yegorov
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

namespace Dvelum\Orm\Distributed;

use Dvelum\Orm;
use Dvelum\Orm\Record\Config;

class Record extends Orm\Record
{
    protected ?string $shard = null;
    /**
     * @var Orm\Distributed\Record\Store $store
     */
    protected Orm\Distributed\Record\Store $store;
    private Orm\Distributed $distributed;

    /**
     * @param Orm\Distributed $distributed
     * @param Config $config
     * @param false $id
     * @param string|null $shard
     * @throws Orm\Exception
     */
    public function __construct(Orm\Distributed $distributed, Orm\Orm $orm, Config $config, $id = false, ?string $shard = null)
    {
        if ($config->getShardingType() === Config::SHARDING_TYPE_KEY_NO_INDEX && $shard === null && !empty($id)) {
            throw new Orm\Exception(
                'Sharded object with type of Config::SHARDING_TYPE_KEY_NO_INDEX requires shard to be defined at constructor'
            );
        }
        parent::__construct($orm, $config, $id);

        $this->shard = $shard;
        $this->distributed = $distributed;
    }

    public function loadData(): void
    {
        $model = $this->orm->model($this->getName());
        $store = $model->getStore();
        /**
         * @todo need refactoring
         */
        $store->setShard((string)$this->shard);
        parent::loadData();
    }

    public function getShard(): string
    {
        return $this->get($this->distributed->getShardField());
    }
}