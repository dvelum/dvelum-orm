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

use Dvelum\Orm\Distributed\Key\GeneratorInterface;
use Dvelum\Orm\Distributed\Key\Reserved;
use Dvelum\Config\ConfigInterface;
use Dvelum\Orm\Model;
use Dvelum\Orm\Orm;
use Dvelum\Orm\Record;
use Dvelum\Orm\RecordInterface;
use Exception;

class UserKeyNoID implements GeneratorInterface
{
    /**
     * @var ConfigInterface<string,mixed> $config
     */
    protected ConfigInterface $config;
    protected string $shardField;
    protected bool $exceptIndexPrimaryKey = true;
    protected Orm $orm;

    public function __construct(Orm $orm, ConfigInterface $config)
    {
        $this->orm = $orm;
        $this->config = $config;
        $this->shardField = $config->get('shard_field');
    }

    /**
     * Delete reserved index
     * @param RecordInterface $object
     * @param mixed $distributedKey
     * @return bool
     */
    public function deleteIndex(RecordInterface $object, $distributedKey): bool
    {
        try {
            $objectConfig = $object->getConfig();
            $indexObject = $objectConfig->getDistributedIndexObject();
            $model = $this->orm->model($indexObject);
            $db = $model->getDbConnection();
            $shardingKey = $objectConfig->getShardingKey();
            if (empty($shardingKey)) {
                return false;
            }
            $db->delete(
                $model->table(),
                $db->quoteIdentifier($db->quoteIdentifier($shardingKey) . ' = ' . $db->quote($distributedKey))
            );
            return true;
        } catch (Exception $e) {
            $model->logError('Sharding::reserveIndex ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Reserve object id, add to routing table
     * @param Record $object
     * @param string $shard
     * @return Reserved|null
     * @throws Exception
     */
    public function reserveIndex(RecordInterface $object, string $shard): ?Reserved
    {
        $objectConfig = $object->getConfig();
        $indexObject = $objectConfig->getDistributedIndexObject();
        $model = $this->orm->model($indexObject);
        $indexConfig = $model->getObjectConfig();

        $fieldList = $indexConfig->getFields();
        $primary = $indexConfig->getPrimaryKey();

        $indexData = [
            $this->shardField => $shard
        ];
        /**
         * @var Record\Config\Field $field
         */
        foreach ($fieldList as $field) {
            $fieldName = $field->getName();

            if (($this->exceptIndexPrimaryKey && $fieldName == $primary) || $fieldName == $this->shardField) {
                continue;
            }

            try {
                if ($fieldName == $primary && $object->getInsertId()) {
                    $indexData[$fieldName] = $object->getInsertId();
                } else {
                    $indexData[$fieldName] = $object->get($fieldName);
                }
            } catch (Exception $e) {
                $model->logError(
                    'Sharding Invalid index structure for  ' . $objectConfig->getName() . ' ' . $e->getMessage()
                );
                return null;
            }
        }
        return $this->reserveKey($object, $indexData);
    }

    /**
     * Get object shard id
     * @param string $objectName
     * @param mixed $distributedKey
     * @return mixed
     */
    public function findObjectShard(string $objectName, $distributedKey)
    {
        $objectConfig = $this->orm->config($objectName);
        $indexObject = $objectConfig->getDistributedIndexObject();

        $model = $this->orm->model($indexObject);

        $query = $model->query()->filters(
            [
                $objectConfig->getShardingKey() => $distributedKey
            ]
        );

        $shardData = $query->fetchRow();

        if (empty($shardData)) {
            return null;
        }
        return $shardData[$this->shardField];
    }

    /**
     * Get shards for list of objects
     * @param string $objectName
     * @param array<mixed> $distributedKeys
     * @return array<string,array<string>>  [shard_id=>[key1,key2,key3], shard_id2=>[...]]
     * @throws Exception
     */
    public function findObjectsShards(string $objectName, array $distributedKeys): array
    {
        $objectConfig = $this->orm->config($objectName);
        $indexObject = $objectConfig->getDistributedIndexObject();

        $distributedKey = $objectConfig->getShardingKey();

        if (empty($distributedKey)) {
            throw new Exception('undefined distributed key name');
        }

        $model = $this->orm->model($indexObject);
        $query = $model->query()->filters([$objectConfig->getShardingKey() => $distributedKeys]);

        $shardData = $query->fetchAll();

        if (empty($shardData)) {
            return [];
        }

        $result = [];
        /**
         * @var array<int|string,array<string,string>> $shardData
         */
        foreach ($shardData as $item) {
            $result[$item[$this->shardField]][] = $item[$distributedKey];
        }
        return $result;
    }

    /**
     * Detect object shard by own rules
     * @param Record $record
     * @return null|string
     */
    public function detectShard(RecordInterface $record): ?string
    {
        $objectConfig = $record->getConfig();
        $indexObject = $objectConfig->getDistributedIndexObject();
        $model = $this->orm->model($indexObject);

        $distributedKey = $objectConfig->getShardingKey();

        if (empty($distributedKey) || empty($record->get($distributedKey))) {
            return null;
        }

        $shard = null;

        $data = $model->query()
            ->filters([$distributedKey => $record->get($distributedKey)])
            ->params(['limit' => 1])
            ->fetchRow();

        if (!empty($data)) {
            $shard = $data[$this->shardField];
        }
        return $shard;
    }

    /**
     * Reserve
     * @param RecordInterface $object
     * @param array<string,int> $keyData
     * @return Reserved|null
     */
    public function reserveKey(RecordInterface $object, array $keyData): ?Reserved
    {
        $result = $this->insertOrGetKey($object, $keyData);
        if (empty($result)) {
            // try restart
            $result = $this->insertOrGetKey($object, $keyData);
        }
        return $result;
    }

    /**
     * Detect shard for user key
     * @param string $objectName
     * @param mixed $key
     * @return null|string
     * @throws \Exception
     */
    public function detectShardByKey(string $objectName, $key): ?string
    {
        $objectConfig = $this->orm->config($objectName);
        $indexObject = $objectConfig->getDistributedIndexObject();
        $model = $this->orm->model($indexObject);
        $keyName = $objectConfig->getShardingKey();

        $data = $model->query()->filters([$keyName => $key])->params(['limit' => 1])->fetchRow();
        if (!empty($data)) {
            return $data[$this->shardField];
        }
        return null;
    }

    /**
     * Change shard value for user key in index table
     * @param string $objectName
     * @param mixed $shardingKeyValue
     * @param string $newShard
     * @return bool
     */
    public function changeShard(string $objectName, $shardingKeyValue, string $newShard): bool
    {
        $objectConfig = $this->orm->config($objectName);
        $shardingKey = $objectConfig->getShardingKey();
        $indexObject = $objectConfig->getDistributedIndexObject();
        $model = $this->orm->model($indexObject);
        $db = $model->getDbConnection();

        if (empty($shardingKey)) {
            return false;
        }

        try {
            $db->update(
                $model->table(),
                [
                    $this->shardField => $newShard
                ],
                $db->quoteIdentifier($shardingKey) . ' = ' . $db->quote($shardingKeyValue)
            );
            return true;
        } catch (Exception $e) {
            $model->logError($e->getMessage());
            return false;
        }
    }

    /**
     * @param RecordInterface $record
     * @param array<string,mixed> $keyData
     * @return Reserved|null
     * @throws Exception
     */
    protected function insertOrGetKey(RecordInterface $record, array $keyData): ?Reserved
    {
        $objectName = $record->getName();
        $objectConfig = $this->orm->config($objectName);
        $indexObject = $objectConfig->getDistributedIndexObject();
        $model = $this->orm->model($indexObject);
        $keyName = $objectConfig->getShardingKey();

        $data = $model->query()->filters([$keyName => $keyData[$keyName]])->params(['limit' => 1])->fetchRow();

        if (!empty($data)) {
            $reserved = new Reserved();
            $reserved->setShard($data[$this->shardField]);
            return $reserved;
        }
        $db = $model->getDbConnection();
        try {
            $db->beginTransaction();
            $db->insert($model->table(), $keyData);
            $id = $db->lastInsertId($model->table());
            $data = $model->query()->filters([$model->getPrimaryKey() => $id])->fetchRow();
            if (empty($data)) {
                throw new Exception('Transaction error');
            }
            $db->commit();
            $reserved = new Reserved();
            $reserved->setShard($data[$this->shardField]);
            return $reserved;
        } catch (Exception $e) {
            $db->rollback();
            $model->logError($e->getMessage());
            $model->logError(
                'Cannot reserve key for ' . $objectName . ':: ' . $keyData[$this->shardField] . ' try restart'
            );
        }
        return null;
    }
}
