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

namespace Dvelum\Orm;

use Dvelum\Orm\Distributed\Key\GeneratorInterface;
use Dvelum\Orm\Distributed\Key\Reserved;
use Dvelum\Orm\Distributed\RouterInterface;
use Dvelum\Orm\Record\Config as RecordConfig;
use Dvelum\Utils;
use Dvelum\Config;
use Dvelum\Config\Storage\StorageInterface;

class Distributed
{
    /**
     * @var Distributed|false $instance
     */
    static protected $instance = false;

    protected $config;

    protected $shards = [];

    /**
     * @var Model $shardModel
     */
    protected Model $shardModel;

    /**
     * @var GeneratorInterface[] $keyGenerators
     */
    protected array $keyGenerators;

    /**
     * Weight map for fast shard random selection
     * @var array<int|string,string>
     */
    protected array $weightMap;

    protected RouterInterface $router;
    protected StorageInterface $configStorage;
    protected Orm $orm;


    /**
     * @param Config\ConfigInterface<int|string,mixed> $config
     * @param RouterInterface $router
     * @param StorageInterface $configStorage
     * @throws \Exception
     */
    public function __construct(
        Config\ConfigInterface $config,
        RouterInterface $router,
        StorageInterface $configStorage,
        Orm $orm
    ) {
        $this->orm = $orm;
        $this->router = $router;
        $this->config = $config;
        $this->configStorage = $configStorage;

        foreach ($this->config->get('sharding_types') as $type => $info) {
            $adapterClass = $info['adapter'];
            if (isset($info['adapterOptions'])) {
                $options = $info['adapterOptions'];
            } else {
                $options = [];
            }
            $this->keyGenerators[$type] = new $adapterClass($this->orm, $this->config, $options);
        }

        $this->shards = Utils::rekey(
            'id',
            $this->configStorage->get(
                $this->config->get('shards'),
                false,
                false
            )->__toArray()
        );

        $this->weightMap = [];
        foreach ($this->shards as $index => $data) {
            $this->weightMap = array_merge(array_fill(0, $data['weight'], (string)$index), $this->weightMap);
        }
    }

    /**
     * Get object shard id
     * @param RecordConfig $config
     * @param mixed $distributedKey
     * @return mixed
     * @throws \Exception
     */
    public function findObjectShard(RecordConfig $config, $distributedKey)
    {
        return $this->keyGenerators[$config->getShardingType()]->findObjectShard($config->getName(), $distributedKey);
    }

    /**
     * Find object shards, return [shard_id=>[key1,key2,key3], shard_id2=>[...]]
     * @param RecordConfig $config
     * @param array<int|string> $distributedKeys
     * @return array<string,array<int|string>>
     * @throws \Exception
     */
    public function findObjectsShards(RecordConfig $config, array $distributedKeys): array
    {
        return $this->keyGenerators[$config->getShardingType()]->findObjectsShards(
            $config->getName(),
            $distributedKeys
        );
    }


    /**
     * Reserve object id, add to routing table
     * @param RecordInterface $record
     * @return Reserved|null
     */
    public function reserveIndex(RecordInterface $record): ?Reserved
    {
        $keyGen = $this->keyGenerators[$record->getConfig()->getShardingType()];

        $shard = $keyGen->detectShard($record);

        if (empty($shard) && $this->router->hasRoutes($record->getName())) {
            $shard = $this->router->findShard($record);
        }

        if (empty($shard)) {
            $shard = $this->randomShard();
        }

        return $keyGen->reserveIndex($record, $shard);
    }

    /**
     * Delete reserved index
     * @param RecordInterface $record
     * @param mixed $indexId
     * @return bool
     */
    public function deleteIndex(RecordInterface $record, $indexId): bool
    {
        return $this->keyGenerators[$record->getConfig()->getShardingType()]->deleteIndex($record, $indexId);
    }

    /**
     * Get shard info by id
     * @param mixed $id
     * @return array|bool
     */
    public function getShardInfo($id)
    {
        if (isset($this->shards[$id])) {
            return $this->shards[$id];
        }

        return false;
    }

    /**
     * Get shards info
     * @return array<string,array<string,mixed>>
     */
    public function getShards(): array
    {
        return $this->shards;
    }

    /**
     * Get object field with shard id
     * @return string
     * @throws \Exception
     */
    public function getShardField(): string
    {
        return $this->config->get('shard_field');
    }

    /**
     * Get bucket field for object
     * @return string
     * @throws \Exception
     */
    public function getBucketField(): string
    {
        return $this->config->get('bucket_field');
    }

    /**
     * Get random shard from list using weight
     */
    public function randomShard(): string
    {
        return $this->weightMap[array_rand($this->weightMap)];
    }

    /**
     * Get random shard except shards in $execpt
     * @param array $except
     * @return null|string
     */
    public function randomShardExcept(array $except): ?string
    {
        $shards = $this->shards;

        foreach ($except as $shard) {
            unset($shards[$shard]);
        }
        if (empty($shards)) {
            return null;
        }

        $weightMap = [];
        foreach ($shards as $index => $data) {
            $weightMap = array_merge(array_fill(0, $data['weight'], (string)$index), $weightMap);
        }
        return (string)$weightMap[array_rand($weightMap)];
    }

    /**
     * Get key generator for distributed ORM object
     * @param string $objectName
     * @return GeneratorInterface
     * @throws Exception
     */
    public function getKeyGenerator(string $objectName): GeneratorInterface
    {
        $config = Record\Config::factory($objectName);
        $key = $config->getShardingType();
        if (!isset($this->keyGenerators[$key])) {
            throw new Exception('Undefined key generator for ' . $objectName);
        }
        return $this->keyGenerators[$key];
    }
}