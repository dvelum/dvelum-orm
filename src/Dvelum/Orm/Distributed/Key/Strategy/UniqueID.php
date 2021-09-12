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
use Dvelum\Config\ConfigInterface;
use Dvelum\Orm\Model;
use Dvelum\Orm\Orm;
use Dvelum\Orm\Record;
use Dvelum\Orm\Distributed\Key\Reserved;
use Dvelum\Orm\RecordInterface;
use Exception;

class UniqueID implements GeneratorInterface
{
    /**
     * @var ConfigInterface<string,mixed> $config
     */
    protected ConfigInterface $config;
    protected string $shardField;
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
     * @param mixed $indexId
     * @return bool
     */
    public function deleteIndex(RecordInterface $object, $indexId): bool
    {
        $objectConfig = $object->getConfig();
        $indexObject = $objectConfig->getDistributedIndexObject();
        $model = $this->orm->model($indexObject);
        $db = $model->getDbConnection();
        try {
            $db->delete($model->table(), $db->quoteIdentifier($model->getPrimaryKey()) . ' = ' . $db->quote($indexId));
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
        $db = $model->getDbConnection();

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

            if ($fieldName === $primary || $fieldName === $this->shardField) {
                continue;
            }

            try {
                $indexData[$fieldName] = $object->get($fieldName);
            } catch (Exception $e) {
                $model->logError(
                    'Sharding Invalid index structure for  ' . $objectConfig->getName() . ' ' . $e->getMessage()
                );
                return null;
            }
        }

        try {
            $db->beginTransaction();
            $db->insert($model->table(), $indexData);

            $id = $db->lastInsertId($model->table(), $objectConfig->getPrimaryKey());
            $db->commit();

            $result = new Reserved();
            $result->setId($id);
            $result->setShard($shard);

            return $result;
        } catch (Exception $e) {
            $db->rollback();
            $model->logError('Sharding::reserveIndex ' . $e->getMessage());
            return null;
        }
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
        $query = $model->query()->filters([$objectConfig->getPrimaryKey() => $distributedKey]);

        $shardData = $query->fetchRow();

        if (empty($shardData)) {
            return false;
        }
        return $shardData[$this->shardField];
    }

    /**
     * Get shards for list of objects
     * @param string $objectName
     * @param array<string> $distributedKeys
     * @return array<string,array<int,string>>  [shard_id=>[key1,key2,key3], shard_id2=>[...]]
     * @throws Exception
     */
    public function findObjectsShards(string $objectName, array $distributedKeys): array
    {
        $objectConfig = $this->orm->config($objectName);
        $indexObject = $objectConfig->getDistributedIndexObject();

        $model = $this->orm->model($indexObject);
        $query = $model->query()->filters([$objectConfig->getPrimaryKey() => $distributedKeys]);

        /**
         * @var array<int,array<string,string>>
         */
        $shardData = $query->fetchAll();

        if (empty($shardData)) {
            return [];
        }
        $result = [];
        $idField = $model->getObjectConfig()->getPrimaryKey();
        foreach ($shardData as $item) {
            $result[$item[$this->shardField]][] = $item[$idField];
        }
        return $result;
    }

    /**
     * Detect object shard by own rules
     * @param RecordInterface $record
     * @return null|string
     */
    public function detectShard(RecordInterface $record): ?string
    {
        return null;
    }
}
