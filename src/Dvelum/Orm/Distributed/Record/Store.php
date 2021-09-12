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

namespace Dvelum\Orm\Distributed\Record;

use Dvelum\Orm\Distributed;
use Dvelum\Orm;
use Dvelum\Db;
use Dvelum\Orm\Model;
use Exception;
use Psr\Log\LogLevel;

class Store extends \Dvelum\Orm\Record\Store
{
    /**
     * @var Distributed $sharding
     */
    protected Distributed $sharding;

    /**
     * @var string|null
     */
    protected ?string $shard = null;

    /**
     * @param Distributed $distributed
     * @param array<string,mixed> $config
     */
    public function __construct(Distributed $distributed, Orm\Orm $orm, array $config = [])
    {
        parent::__construct($orm, $config);
        $this->sharding = $distributed;
    }

    public function setShard(?string $shard): void
    {
        $this->shard = $shard;
    }

    /**
     * Load record data
     * @param string $objectName
     * @param int $id
     * @return array<string,mixed>
     */
    public function load(string $objectName, int $id): array
    {
        /**
         * @var \Dvelum\Orm\Distributed\Model $model
         */
        $model = $this->orm->model($objectName);

        if (empty($this->shard)) {
            $result = $model->getItem($id);
        } else {
            $result = $model->getItemFromShard($id, $this->shard);
        }

        if (empty($result)) {
            $result = [];
        }

        return $result;
    }

    /**
     * Insert record
     * @param Orm\RecordInterface $object
     * @param array<int|string,mixed> $data
     * @return int|null record id
     */
    protected function insertRecord(Orm\RecordInterface $object, array $data): ?int
    {
        $insert = $this->sharding->reserveIndex($object);

        if (empty($insert)) {
            if ($this->log) {
                $this->log->log(LogLevel::ERROR, $object->getName() . '::insert Cannot reserve index for object');
            }
            return null;
        }

        $insertId = $insert->getId();
        $shardingField = $this->sharding->getShardField();

        $object->set($shardingField, $insert->getShard());
        if ($object->getConfig()->getShardingType() == Orm\Record\Config::SHARDING_TYPE_VIRTUAL_BUCKET) {
            $bucketField = $this->sharding->getBucketField();
            $object->set($bucketField, $insert->getBucket());
            $data[$bucketField] = $insert->getBucket();
            if (!empty($object->getInsertId())) {
                $insertId = $object->getInsertId();
            }
        }

        $data[$shardingField] = $insert->getShard();
        if (!empty($insertId)) {
            $data[$object->getConfig()->getPrimaryKey()] = $insertId;
        }

        $data = $object->serializeLinks($data);
        $db = $this->getDbConnection($object);

        $model = $this->orm->model($object->getName());
        try {
            $db->beginTransaction();
            if (empty($insertId)) {
                $db->insert($model->table(), $data);
                $insertId = $db->lastInsertId($model->table());
            } else {
                $db->insert($model->table(), $data);
            }
            $db->commit();
        } catch (Exception $e) {
            $sType = $object->getConfig()->getShardingType();
            if (
                $insertId &&
                $sType == Orm\Record\Config::SHARDING_TYPE_GLOABAL_ID ||
                $sType == Orm\Record\Config::SHARDING_TYPE_KEY
            ) {
                $this->sharding->deleteIndex($object, $insertId);
            }

            if ($this->log) {
                $this->log->log(LogLevel::ERROR, $object->getName() . '::insert ' . $e->getMessage());
            }
            return null;
        }
        return $insertId;
    }

    /**
     * Delete record
     * @param Orm\RecordInterface $object
     * @return bool
     */
    protected function deleteRecord(Orm\RecordInterface $object): bool
    {
        $objectConfig = $object->getConfig();
        $indexObject = $objectConfig->getDistributedIndexObject();
        $indexModel = $this->orm->model($indexObject);

        $db = $indexModel->getDbConnection();
        $db->beginTransaction();

        if ($objectConfig->hasDistributedIndexRecord()) {
            try {
                /**
                 * @var Orm\RecordInterface $obj
                 */
                $obj = $this->orm->record($indexObject, $object->getId());
                $obj->delete(false);
            } catch (Exception $e) {
                if ($this->log) {
                    $this->log->log(
                        LogLevel::ERROR,
                        $object->getName() . ' cant delete index' . $object->getId()
                    );
                }
                return false;
            }
        }

        if (!parent::deleteRecord($object)) {
            $db->rollback();
            return false;
        }
        $db->commit();
        return true;
    }

    /**
     * @param Orm\RecordInterface $object
     * @return Db\Adapter
     */
    protected function getDbConnection(Orm\RecordInterface $object): Db\Adapter
    {
        $field = $this->sharding->getShardField();
        $shardId = $object->get($field);

        if (empty($shardId)) {
            $shardId = null;
        }

        $objectModel = $this->orm->model($object->getName());
        return $objectModel->getDbManager()->getDbConnection($objectModel->getDbConnectionName(), null, $shardId);
    }

    /**
     * Update record
     * @param Orm\RecordInterface $object
     * @return bool
     */
    protected function updateRecord(Orm\RecordInterface $object): bool
    {
        $db = $this->getDbConnection($object);

        $updates = $object->getUpdates();

        if ($object->getConfig()->hasEncrypted()) {
            $updates = $this->encryptData($object, $updates);
        }

        $this->updateLinks($object);

        $updates = $object->serializeLinks($updates);

        $shardingIndex = $object->getConfig()->getDistributedIndexObject();
        $indexModel = $this->orm->model($shardingIndex);
        $indexConfig = $indexModel->getObjectConfig();
        $indexDb = $indexModel->getDbConnection();

        $indexFields = $indexConfig->getFields();
        $indexUpdates = [];

        foreach ($updates as $field => $value) {
            if (isset($indexFields[$field]) && $indexConfig->getPrimaryKey() !== $field) {
                $indexUpdates[$field] = $value;
            }
        }

        $model = $this->orm->model($object->getName());
        if (!empty($updates)) {
            try {
                if (!empty($indexUpdates)) {
                    $indexDb->beginTransaction();
                    $indexDb->update(
                        $indexModel->table(),
                        $indexUpdates,
                        $db->quoteIdentifier(
                            $object->getConfig()->getPrimaryKey()
                        ) . ' = ' . $object->getId()
                    );
                }
                $db->update(
                    $model->table(),
                    $updates,
                    $db->quoteIdentifier($object->getConfig()->getPrimaryKey()) . ' = ' . $object->getId()
                );
                if (!empty($indexUpdates)) {
                    $indexDb->commit();
                }
            } catch (Exception $e) {
                if ($this->log) {
                    $this->log->log(LogLevel::ERROR, $object->getName() . '::update ' . $e->getMessage());
                }
                if (!empty($indexUpdates)) {
                    $indexDb->rollback();
                }
                return false;
            }
        }
        return true;
    }

    /**
     * Validate unique fields, object field groups
     * Returns array of errors or null .
     * @param string $objectName
     * @param int|null $recordId
     * @param array<string,mixed> $groupsData
     * @return array<string,mixed>|null
     * @throws Orm\Exception
     */
    public function validateUniqueValues(string $objectName, ?int $recordId, array $groupsData): ?array
    {
        $objectConfig = $this->orm->config($objectName);
        $model = $this->orm->model($objectConfig->getDistributedIndexObject());

        $db = $model->getDbConnection();
        $primaryKey = $model->getPrimaryKey();

        try {
            foreach ($groupsData as $group) {
                $sql = $db->select()
                    ->from($model->table(), array('count' => 'COUNT(*)'));

                if ($recordId !== null) {
                    $sql->where(' ' . $db->quoteIdentifier($primaryKey) . ' != ?', $recordId);
                }

                foreach ($group as $k => $v) {
                    if ($k === $primaryKey) {
                        continue;
                    }
                    $sql->where($db->quoteIdentifier($k) . ' =?', $v);
                }

                $count = $db->fetchOne($sql);

                if ($count > 0) {
                    return $group;
                }
            }
        } catch (Exception $e) {
            if ($this->log) {
                $this->log->log(LogLevel::ERROR, $objectName . '::validate ' . $e->getMessage());
            }
            return null;
        }
        return null;
    }
}
