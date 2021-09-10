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

use Dvelum\Cache\CacheInterface;
use Dvelum\Config;
use Dvelum\Orm;
use Dvelum\Db;
use Dvelum\Service;
use Dvelum\Utils;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Base class for data models
 */
class Model
{
    /**
     * @var Config\ConfigInterface<string,mixed>
     *
     * // Global (For all Models) Hard caching time
     * 'hardCacheTime'  => 60,
     * // Default Cache_Interface
     * 'dataCache' => false  ,
     * // Db object storage interface
     * 'dbObjectStore'  => false,
     * // Default Connection manager
     * 'defaultDbManager' => false,
     * // Default error log adapter
     * 'errorLog' =>false
     */
    protected Config\ConfigInterface $settings;
    /**
     * DB Object Storage
     * @var Orm\Record\Store
     */
    protected $store;

    /**
     * Database connection
     * @var Db\Adapter
     */
    protected $db;

    /**
     * Db_Object config
     * @var Orm\Record\Config | null
     */
    private ?Orm\Record\Config $objectConfig = null;

    /**
     * @var Config\ConfigInterface<string,mixed>
     */
    private Config\ConfigInterface $lightConfig;

    /**
     * Object / model name
     * @var string
     */
    protected string $name;

    /**
     * Hard caching time (without validation) for frondend , seconds
     * @var int
     */
    protected int $cacheTime;

    /**
     * Current Cache Interface
     * @var CacheInterface | false
     */
    protected $cache = false;


    /**
     * DB table prefix
     * @var string
     */
    protected string $dbPrefix = '';

    /**
     * Connection manager
     * @var Db\OrmManager|Db\ManagerInterface
     */
    protected Db\ManagerInterface $dbManager;

    /**
     * Table name
     * @var string
     */
    protected string $table;

    /**
     * Current error log adapter
     * @var \Psr\Log\LoggerInterface | false | null
     */
    protected $log = false;

    /**
     * List of search fields
     * @var array<string> | null
     */
    protected ?array $searchFields = null;

    protected \Dvelum\Orm\Orm $orm;

    protected Config\Storage\StorageInterface $configStorage;

    /**
     * Get DB table prefix
     * @return string
     */
    public function getDbPrefix(): string
    {
        return $this->dbPrefix;
    }

    /**
     * @param string $objectName
     * @param Config\ConfigInterface<int|string,mixed> $settings
     * @param Config\ConfigInterface<int|string,mixed> $ormConfig
     * @throws \Exception
     */
    public function __construct(
        string $objectName,
        Config\ConfigInterface $settings,
        Config\ConfigInterface $ormConfig,
        \Dvelum\Orm\Orm $orm,
        Config\Storage\StorageInterface $configStorage
    ) {
        $this->settings = $settings;
        $this->orm = $orm;
        $this->configStorage = $configStorage;

        $this->store = $settings->get('storeLoader')();
        $this->name = strtolower($objectName);
        $this->cacheTime = $settings->get('hardCacheTime');

        if ($settings->offsetExists('dataCache')) {
            $this->cache = $settings->get('dataCache');
        } else {
            $this->cache = false;
        }

        $this->dbManager = $settings->get('defaultDbManager');

        $this->lightConfig = $this->configStorage->get(
            $ormConfig->get('object_configs') . $this->name . '.php',
            true,
            false
        );

        $conName = $this->lightConfig->get('connection');

        $this->db = $this->dbManager->getDbConnection($conName);

        if ($this->lightConfig->get('use_db_prefix')) {
            $this->dbPrefix = $this->dbManager->getDbConfig($conName)['prefix'];
        } else {
            $this->dbPrefix = '';
        }

        $this->table = $this->lightConfig->get('table');
    }

    /**
     * Get current Db connectionName
     * @return string
     * @throws \Exception
     */
    public function getConnectionName(): string
    {
        return $this->lightConfig->get('connection');
    }

    /**
     * Get db connection for shard
     * @param string $shard
     * @return Db\Adapter
     */
    public function getDbShardConnection(string $shard): Db\Adapter
    {
        $curName = $this->getDbConnectionName();
        return $this->getDbManager()->getDbConnection($curName, null, $shard);
    }

    /**
     * Lazy load of ORM\Record\Config
     * @return Record\Config
     * @throws \Exception
     */
    public function getObjectConfig(): Orm\Record\Config
    {
        if (empty($this->objectConfig)) {
            try {
                $this->objectConfig = $this->orm->config($this->name);
            } catch (\Exception $e) {
                throw new \Exception('Object ' . $this->name . ' is not exists');
            }
        }
        return $this->objectConfig;
    }

    /**
     * Get Master Db connector
     * return Db\Adapter
     */
    public function getDbConnection(): Db\Adapter
    {
        return $this->db;
    }

    /**
     * Get connection name
     * @return string
     */
    public function getDbConnectionName(): string
    {
        return (string)$this->getObjectConfig()->get('connection');
    }

    /**
     * Get current db manager
     * @return \Dvelum\Db\ManagerInterface
     */
    public function getDbManager(): \Dvelum\Db\ManagerInterface
    {
        return $this->dbManager;
    }

    /**
     * Get storage adapter
     * @return Orm\Distributed\Record\Store
     */
    public function getStore(): Orm\Record\Store
    {
        if (empty($this->store)) {
            $this->store = $this->settings->get('storeLoader')();
        }
        return $this->store;
    }

    /**
     * Get the name of the object, which the model refers to
     * @return string
     */
    public function getObjectName(): string
    {
        return $this->name;
    }

    /**
     * Get key for cache
     * @param array $params - parameters can not contain arrays, objects and resources
     * @return string
     */
    public function getCacheKey(array $params): string
    {
        return md5($this->getObjectName() . '-' . implode('-', $params));
    }

    /**
     * Get the name of the database table (with prefix)
     * @return string
     */
    public function table(): string
    {
        return $this->dbPrefix . $this->table;
    }

    /**
     * Get record by id
     * @param int $id
     * @param array<int|string,string>|string $fields — optional — the list of fields to retrieve
     * @return array<int|string,string>
     * @throws \Exception
     */
    public function getItem(int $id, $fields = ['*']): array
    {
        $primaryKey = $this->getPrimaryKey();
        $query = $this->query()->filters([$primaryKey => $id])->fields($fields);

        $result = $query->fetchRow();

        if (empty($result)) {
            $result = [];
        }
        return $result;
    }

    /**
     *  Get the object data using cache
     * @param int $id - object identifier
     * @param mixed $lifetime
     * @return array<int|string,string>
     * @throws \Exception
     */
    public function getCachedItem(int $id, $lifetime = false)
    {
        if (!$this->cache) {
            return $this->getItem($id);
        }

        $cacheKey = $this->getCacheKey(['item', $id]);
        $data = $this->cache->load($cacheKey);

        if ($data !== false) {
            return $data;
        }

        $data = $this->getItem($id);
        $this->cache->save($cacheKey, $data, $lifetime);

        return $data;
    }

    /**
     * Get data record by field value using cache. Returns first occurrence
     * @param string $field - field name
     * @param mixed $value - field value
     * @return array<string,mixed>
     * @throws \Exception
     */
    public function getCachedItemByField(string $field, $value): array
    {
        $cacheKey = $this->getCacheKey(['item', $field, (string)$value]);
        $data = false;

        if ($this->cache) {
            $data = $this->cache->load($cacheKey);
        }

        if ($data !== false) {
            return $data;
        }

        $data = $this->getItemByField($field, $value);

        if (empty($data)) {
            $data = [];
        }

        if ($this->cache && $data) {
            $this->cache->save($cacheKey, $data);
        }

        return $data;
    }

    /**
     * Get Item by field value. Returns first occurrence
     * @param string $fieldName
     * @param mixed $value
     * @param string|array<int|string,string> $fields
     * @return array<int|string,mixed>
     * @throws \Exception
     */
    public function getItemByField(string $fieldName, $value, $fields = '*'): array
    {
        try {
            $sql = $this->db->select()->from($this->table(), $fields);
            $sql->where($this->db->quoteIdentifier($fieldName) . ' = ?', $value)->limit(1);
            return $this->db->fetchRow($sql);
        } catch (\Exception $e) {
            $this->logError($e->getMessage());
            throw $e;
        }
    }

    /**
     * Get a number of entries a list of IDs
     * @param array<int> $ids - list of IDs
     * @param mixed $fields - optional - the list of fields to retrieve
     * @param bool $useCache - optional, defaul false
     * @return array<int,array<int|string,mixed>>
     * @throws \Exception
     */
    public function getItems(array $ids, $fields = '*', bool $useCache = false): array
    {
        $data = false;
        $cacheKey = '';

        if (empty($ids)) {
            return [];
        }

        if ($useCache && $this->cache) {
            $cacheKey = $this->getCacheKey(array('list', serialize(func_get_args())));
            $data = $this->cache->load($cacheKey);
        }

        if ($data === false) {
            $sql = $this->db->select()
                ->from($this->table(), $fields)
                ->where($this->db->quoteIdentifier($this->getPrimaryKey()) . ' IN(' . Utils::listIntegers($ids) . ')');

            $data = $this->db->fetchAll($sql);

            if (!$data) {
                $data = [];
            }

            if ($useCache && $this->cache) {
                $this->cache->save($cacheKey, $data, $this->cacheTime);
            }
        }
        return $data;
    }

    /**
     * Create Model\Query
     * @return Model\Query
     */
    public function query(): Model\Query
    {
        return new Model\Query($this->orm, $this);
    }

    /**
     * Get object title
     * @param Orm\RecordInterface $object - object for getting title
     * @return string - object title
     * @throws \Exception
     */
    public function getTitle(Orm\RecordInterface $object): string
    {
        $objectConfig = $object->getConfig();
        $title = $objectConfig->getLinkTitle();
        if (strpos($title, '{') !== false) {
            $fields = $objectConfig->getFieldsConfig(true);
            foreach ($fields as $name => $cfg) {
                $value = $object->get($name);
                if (is_array($value)) {
                    $value = implode(', ', $value);
                }
                $title = str_replace('{' . $name . '}', (string)$value, $title);
            }
        } else {
            if ($object->fieldExists($title)) {
                $title = $object->get($title);
            }
        }
        return (string)$title;
    }

    /**
     * Delete record
     * @param mixed $recordId record ID
     * @return bool
     */
    public function remove($recordId): bool
    {
        try {
            /**
             * @var \Dvelum\Orm\RecordInterface $object
             */
            $object = $this->orm->record($this->name, $recordId);
        } catch (\Exception $e) {
            $this->logError('Remove record ' . $recordId . ' : ' . $e->getMessage());
            return false;
        }

        if ($this->getStore()->delete($object)) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Check whether the field value is unique
     * Returns true if value $fieldValue is unique for $fieldName field
     * otherwise returns false
     * @param int $recordId — record ID
     * @param string $fieldName — field name
     * @param mixed $fieldValue — field value
     * @return bool
     * @throws \Exception
     */
    public function checkUnique(int $recordId, string $fieldName, $fieldValue): bool
    {
        return !(boolean)$this->db->fetchOne(
            $this->db->select()
                ->from($this->table(), ['count' => 'COUNT(*)'])
                ->where($this->db->quoteIdentifier($this->getPrimaryKey()) . ' != ?', $recordId)
                ->where($this->db->quoteIdentifier($fieldName) . ' =?', $fieldValue)
        );
    }

    /**
     * Get primary key name
     * @return string
     */
    public function getPrimaryKey(): string
    {
        $key = '';

        if ($this->lightConfig->offsetExists('primary_key')) {
            $key = $this->lightConfig->get('primary_key');
        }

        if (empty($key)) {
            return 'id';
        } else {
            return $key;
        }
    }

    public function refreshTableInfo()
    {
        $config = $this->getObjectConfig();
        $conName = $this->lightConfig->get('connection');
        $this->db = $this->dbManager->getDbConnection($conName);

        if ($config->hasDbPrefix()) {
            $this->dbPrefix = $this->dbManager->getDbConfig($conName)['prefix'];
        } else {
            $this->dbPrefix = '';
        }

        $this->table = $this->lightConfig->get('table');
    }

    /**
     * Set current log adapter
     * @param \Psr\Log\LoggerInterface|false $log
     */
    public function setLog($log): void
    {
        $this->log = $log;
    }

    /**
     * Get logs Adapter
     * @return LoggerInterface|null
     * @throws \Exception
     */
    public function getLogsAdapter(): ?LoggerInterface
    {
        if ($this->log === false) {
            if ($this->settings->offsetExists('logLoader') && is_callable($this->settings->get('logLoader'))) {
                $this->log = $this->settings->get('logLoader')();
            } else {
                $this->log = null;
            }
        }
        return $this->log;
    }

    /**
     * Log error message
     * @param string $message
     * @return void
     */
    public function logError(string $message): void
    {
        $log = $this->getLogsAdapter();
        if (empty($log)) {
            return;
        }
        $log->log(\Psr\Log\LogLevel::ERROR, get_called_class() . ': ' . $message);
    }

    /**
     * Get list of search fields (get from ORM)
     */
    public function getSearchFields()
    {
        if (is_null($this->searchFields)) {
            $this->searchFields = $this->getObjectConfig()->getSearchFields();
        }
        return $this->searchFields;
    }

    /**
     * Set
     * @param array $fields
     * @return void
     */
    public function setSearchFields(array $fields): void
    {
        $this->searchFields = $fields;
    }

    /**
     * Reset search fields list (get from ORM)
     * @return void
     */
    public function resetSearchFields(): void
    {
        $this->searchFields = null;
    }

    /**
     * Get Orm\Record config array
     * @return Config\ConfigInterface<int|string,mixed>
     */
    public function getLightConfig(): Config\ConfigInterface
    {
        return $this->lightConfig;
    }

    /**
     * @return false|CacheInterface
     */
    public function getCacheAdapter()
    {
        return $this->cache;
    }

    /**
     * Get insert object
     * @return Model\InsertInterface
     */
    public function insert(): Model\InsertInterface
    {
        return new Orm\Model\Insert($this);
    }


    /**
     * @param string $name
     * @return static
     * @deprecated backward compatibility
     */
    public static function factory(string $name): self
    {
        return Orm::factory()->model($name);
    }
}