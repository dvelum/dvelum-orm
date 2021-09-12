<?php

/*
 *
 * DVelum project https://github.com/dvelum/
 *
 * MIT License
 *
 *  Copyright (C) 2011-2021  Kirill Yegorov https://github.com/dvelum/dvelum-orm
 *
 *  Permission is hereby granted, free of charge, to any person obtaining a copy
 *  of this software and associated documentation files (the "Software"), to deal
 *  in the Software without restriction, including without limitation the rights
 *  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 *  copies of the Software, and to permit persons to whom the Software is
 *  furnished to do so, subject to the following conditions:
 *
 *  The above copyright notice and this permission notice shall be included in all
 *  copies or substantial portions of the Software.
 *
 *  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 *  IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 *  FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 *  AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 *  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 *  OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 *  SOFTWARE.
 *
 */

declare(strict_types=1);

namespace Dvelum\Orm\Distributed\Key\Strategy;

use Dvelum\Config\ConfigInterface;
use Dvelum\Orm\Distributed\Key\Reserved;
use Dvelum\Orm\Distributed\Key\Strategy\VirtualBucket\MapperInterface;
use Dvelum\Orm\Distributed\Model;
use Dvelum\Orm\Exception;
use Dvelum\Orm\Orm;
use Dvelum\Orm\Record\Config;
use Dvelum\Orm\RecordInterface;

class VirtualBucket extends UserKeyNoID
{
    /**
     * @var ConfigInterface<string,mixed> $config
     */
    protected ConfigInterface $config;
    protected string $shardField;
    /**
     * @var array<string,mixed>
     */
    protected array $options;
    protected string $bucketField;
    protected bool $exceptIndexPrimaryKey = false;
    protected Orm $orm;

    protected ?MapperInterface $numericMapper = null;
    protected ?MapperInterface $stringMapper = null;

    public function __construct(Orm $orm, ConfigInterface $config)
    {
        parent::__construct($orm, $config);
        $this->bucketField = $config->get('bucket_field');
    }

    /**
     * @return MapperInterface
     * @throws \Exception
     */
    public function getNumericMapper(): MapperInterface
    {
        if (empty($this->numericMapper)) {
            $numericAdapter = $this->config->get('keyToBucket')['number'];
            $this->numericMapper = new $numericAdapter();
        }
        return $this->numericMapper;
    }

    /**
     * @return MapperInterface
     * @throws \Exception
     */
    public function getStringMapper(): MapperInterface
    {
        if (empty($this->stringMapper)) {
            $numericAdapter = $this->config->get('keyToBucket')['string'];
            $this->stringMapper = new $numericAdapter();
        }
        return $this->stringMapper;
    }

    /**
     * Reserve
     * @param RecordInterface $object
     * @param array<int|string,mixed> $keyData
     * @return Reserved|null
     */
    public function reserveKey(RecordInterface $object, array $keyData): ?Reserved
    {
        $config = $object->getConfig();
        $keyField = $config->getBucketMapperKey();

        if (empty($keyField)) {
            return null;
        }

        $fieldObject = $config->getField($keyField);

        $bucket = null;

        if ($keyField === $config->getPrimaryKey()) {
            $value = $object->getInsertId();
        } else {
            $value = $object->get($keyField);
        }

        if ($fieldObject->isNumeric()) {
            $bucket = $this->getNumericMapper()->keyToBucket($value);
        } elseif ($fieldObject->isText(true)) {
            $bucket = $this->getStringMapper()->keyToBucket($value);
        }

        if (empty($bucket)) {
            return null;
        }
        /**
         * @var array<string,int> $keyData
         */
        $keyData[$this->bucketField] = $bucket->getId();

        unset($keyData[$config->getPrimaryKey()]);

        $result = parent::reserveKey($object, $keyData);

        if (!empty($result)) {
            $result->setBucket($bucket->getId());
        }
        return $result;
    }

    /**
     * Get object shard id
     * @param string $objectName
     * @param mixed $distributedKey
     * @return mixed
     */
    public function findObjectShard(string $objectName, $distributedKey)
    {
        $config = $this->orm->config($objectName);
        $keyField = $config->getBucketMapperKey();
        if ($keyField === null) {
            throw new Exception('Undefined key field in mapper for ' . $objectName);
        }

        $fieldObject = $config->getField($keyField);

        if ($fieldObject->isNumeric()) {
            $mapper = $this->getNumericMapper();
        } elseif ($fieldObject->isText(true)) {
            $mapper = $this->getStringMapper();
        } else {
            throw new Exception('Undefined key mapper for ' . $objectName);
        }

        $bucket = $mapper->keyToBucket($distributedKey);
        $indexObject = $config->getDistributedIndexObject();
        $indexModel = $this->orm->model($indexObject);
        $shard = $indexModel->query()
            ->filters([$this->bucketField => $bucket->getId()])
            ->fields([$this->shardField])
            ->fetchOne();

        if (empty($shard)) {
            return null;
        }
        return $shard;
    }

    /**
     * Get shards for list of objects
     * @param string $objectName
     * @param array<mixed> $distributedKeys
     * @return array<string,array<string>>  [shard_id=>[key1,key2,key3], shard_id2=>[...]]
     * @throws \Exception
     */
    public function findObjectsShards(string $objectName, array $distributedKeys): array
    {
        $config = $this->orm->config($objectName);
        $keyField = $config->getBucketMapperKey();

        if ($keyField === null) {
            throw new Exception('Undefined key field in mapper for ' . $objectName);
        }

        $fieldObject = $config->getField($keyField);

        if ($fieldObject->isNumeric()) {
            $mapper = $this->getNumericMapper();
        } elseif ($fieldObject->isText(true)) {
            $mapper = $this->getStringMapper();
        } else {
            throw new Exception('Undefined key mapper for ' . $objectName);
        }

        $indexObject = $config->getDistributedIndexObject();
        $indexModel = $this->orm->model($indexObject);

        $result = [];
        $search = [];

        foreach ($distributedKeys as $key) {
            $bucket = $mapper->keyToBucket($key);
            $search[$bucket->getId()][] = $key;
        }

        $shardData = $indexModel->query()
            ->filters([$this->bucketField => array_keys($search)])
            ->fields([$this->shardField, $this->bucketField])
            ->fetchAll();

        if (empty($shardData)) {
            return [];
        }

        foreach ($shardData as $row) {
            /**
             * @var string $shardId
             */
            $shardId = $row[$this->shardField];
            $bucketId = $row[$this->bucketField];
            if (!isset($result[$shardId])) {
                $result[$shardId] = [];
            }
            if (isset($search[$bucketId])) {
                $result[$shardId] = array_merge($result[$shardId], $search[$bucketId]);
            }
        }
        return $result;
    }
}
