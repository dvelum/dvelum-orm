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

namespace Dvelum\Orm\Record\Builder;

use Dvelum\Lang\Dictionary;
use Dvelum\Orm;
use Dvelum\Orm\Record\Config;
use Dvelum\Orm\Model;
use Dvelum\Orm\Record\BuilderFactory;
use Dvelum\Log;
use Dvelum\Lang;
use Dvelum\Config\ConfigInterface;
use Dvelum\Utils;
use Dvelum\Db\Metadata\Object\ColumnObject;
use Laminas\Db\Sql\Ddl;
use Dvelum\Config\Storage\StorageInterface;
use Dvelum\Config as Cfg;
use Exception;

abstract class AbstractAdapter implements BuilderInterface
{
    /**
     * @var array<string>
     */
    protected array $errors = [];

    /**
     * @var \Dvelum\Db\Adapter
     */
    protected \Dvelum\Db\Adapter $db;

    /**
     * @var string $objectName
     */
    protected string $objectName;

    /**
     * @var Orm\Record\Config
     */
    protected Orm\Record\Config $objectConfig;

    /**
     * @var string
     */
    protected string $dbPrefix;

    /**
     * @var Log\File | false
     */
    protected $log = false;

    /**
     * @var bool
     */
    protected bool $useForeignKeys = false;

    /**
     * @var Model
     */
    protected Model $model;

    /**
     * @var string $configPath
     */
    protected string $configPath;
    /**
     * @var array<string,mixed>
     */
    protected array $validationErrors = [];

    protected Orm\Orm $orm;
    protected StorageInterface $configStorage;
    protected Dictionary $lang;

    /**
     * @return array<int,array{name:string,action:string}>
     */
    abstract public function prepareColumnUpdates(): array;

    /**
     * @return array<int,array{name:string,action:string}>
     */
    abstract public function prepareIndexUpdates(): array;

    /**
     * @return array<int,array{name:string,action:string,config:mixed}>
     */
    abstract public function prepareKeysUpdate(): array;

    /**
     * @param ConfigInterface<int|string,mixed> $config
     * @throws \Exception
     */
    public function __construct(
        ConfigInterface $config,
        Orm\Orm $orm,
        StorageInterface $configStorage,
        Dictionary $lang
    ) {
        $this->orm = $orm;
        $this->lang = $lang;
        $this->configStorage = $configStorage;
        $this->configPath = $config->get('configPath');
        $this->objectName = $config->get('objectName');

        if ($config->offsetExists('log') && $config->get('log') instanceof Log\File) {
            $this->log = $config->get('log');
        }

        if ($config->offsetExists('useForeignKeys')) {
            $this->useForeignKeys = $config->get('useForeignKeys');
        }

        $this->model = $orm->model($this->objectName);
        $this->db = $this->model->getDbConnection();
        $this->dbPrefix = $this->model->getDbPrefix();
        $this->objectConfig = $orm->config($this->objectName);
    }

    /**
     * @param \Dvelum\Db\Adapter $db
     */
    public function setConnection(\Dvelum\Db\Adapter $db): void
    {
        $this->db = $db;
    }

    /**
     * Get error messages
     * @return array<string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Check for broken object links
     * @return array<string>
     */
    public function getBrokenLinks(): array
    {
        $links = $this->objectConfig->getLinks();

        if (empty($links)) {
            return [];
        }

        $brokenFields = [];
        foreach ($links as $o => $fieldList) {
            if (!$this->orm->configExists($o)) {
                foreach ($fieldList as $field => $cfg) {
                    $brokenFields[$field] = $o;
                }
            }
        }
        return $brokenFields;
    }

    public function validateDistributedConfig(): bool
    {
        if (!$this->checkRelations()) {
            $this->validationErrors['relations'] = true;
            return false;
        }
        $shardUpdates = $this->getDistributedObjectsUpdatesInfo();
        $linksUpdates = $this->getObjectsUpdatesInfo();

        if (!empty($shardUpdates) || !empty($linksUpdates)) {
            return false;
        }
        return true;
    }

    /**
     * Check if DB table has correct structure
     * @return bool
     */
    public function validate(): bool
    {
        if (!$this->tableExists()) {
            $this->validationErrors['table'] = true;
            return false;
        }
        if (!$this->checkRelations()) {
            $this->validationErrors['relations'] = true;
            return false;
        }
        // Check columns
        $updateColumns = $this->prepareColumnUpdates();
        // Column changes
        if (!empty($updateColumns)) {
            $this->validationErrors['columns'] = true;
            return false;
        }

        // Check indexes
        $updateIndexes = $this->prepareIndexUpdates();
        // Index changes
        if (!empty($updateIndexes)) {
            $this->validationErrors['indexes'] = true;
            return false;
        }

        $updateKeys = [];
        if ($this->useForeignKeys) {
            $updateKeys = $this->prepareKeysUpdate();
        }

        $shardUpdates = $this->getDistributedObjectsUpdatesInfo();
        $linksUpdates = $this->getObjectsUpdatesInfo();

        $this->validationErrors = [
            'keys' => !empty($updateKeys),
            'shards' => !empty($shardUpdates),
            'links' => !empty($linksUpdates)
        ];

        if (!empty($updateKeys) || !empty($shardUpdates) || !empty($linksUpdates)) {
            return false;
        }
        return true;
    }

    /**
     * @return array<string,mixed>
     */
    public function getValidationErrors(): array
    {
        return $this->validationErrors;
    }

    /**
     * Get Existing Columns
     * @return array<string,\Laminas\Db\Metadata\Object\ColumnObject>
     */
    protected function getExistingColumns(): array
    {
        return $this->db->getMeta()->getColumns($this->model->table());
    }


    /**
     * Check if table exists
     * @param string $name - optional, table name,
     * @param bool $addPrefix - optional append prefix, default false
     * @return bool
     */
    public function tableExists(string $name = '', bool $addPrefix = false): bool
    {
        if (empty($name)) {
            $name = $this->model->table();
        }

        if ($addPrefix) {
            $name = $this->model->getDbPrefix() . $name;
        }

        try {
            $tables = $this->db->listTables();
        } catch (\Exception $e) {
            return false;
        }
        return in_array($name, $tables, true);
    }

    /**
     * Check relation objects
     */
    protected function checkRelations(): bool
    {
        $relation = new Orm\Record\Config\Relation();
        $list = $relation->getManyToMany($this->objectConfig);

        if (empty($list)) {
            return true;
        }

        foreach ($list as $fields) {
            if (empty($fields)) {
                continue;
            }

            foreach ($fields as $fieldName => $linkType) {
                $relationObjectName = $this->objectConfig->getRelationsObject($fieldName);
                if (!is_string($relationObjectName) || !$this->orm->configExists($relationObjectName)) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Log queries
     * @param string $sql
     * @return bool
     */
    protected function logSql(string $sql): bool
    {
        if (!$this->log) {
            return true;
        }

        try {
            $this->log->info('--');
            $this->log->info('--' . date('Y-m-d H:i:s'));
            $this->log->info('--');
            $this->log->info($sql);
        } catch (\Error $e) {
            $this->errors[] = 'Cant write to log file ' . $this->log->getFileName();
            return false;
        }

        return true;
    }

    /**
     * Get object foreign keys
     * @return array<string,mixed>
     */
    public function getOrmForeignKeys(): array
    {
        if (!$this->useForeignKeys) {
            return [];
        }

        $keyManager = new Config\ForeignKey();
        $data = $keyManager->getForeignKeys($this->objectConfig, $this->orm);
        $keys = [];

        if (!empty($data)) {
            foreach ($data as $item) {
                $keyName = $this->createForeignKeyName($item);
                $keys[$keyName] = $item;
            }
        }
        return $keys;
    }

    /**
     * Generate index name  for constraint key
     * Mysql limits with 64 chars
     * @param array<string,mixed> $item
     * @return string
     */
    public function createForeignKeyName(array $item): string
    {
        $curObjectConfig = $this->orm->config($item['curObject']);
        $key = '';
        if ($curObjectConfig->isDistributed()) {
            $toObj = $this->orm->config($item['toObject']);
            if ($toObj->isDistributed()) {
                $key = $this->db->getConfig()['dbname'] . '.' . $item['curTable'] . '.' . $item['curField'] .
                    '-' .
                    $this->db->getConfig()['dbname'] . '.' . $item['toTable'] . '.' . $item['toField'];
            }
        }

        if (empty($key)) {
            $key = $item['curDb'] . '.' . $item['curTable'] . '.' . $item['curField'] .
                '-' .
                $item['toDb'] . '.' . $item['toTable'] . '.' . $item['toField'];
        }

        if (mb_strlen($key, 'utf-8') > 64) {
            $key = md5($key);
        }
        return $key;
    }

    /**
     * Get updates information
     * @return array<string,array<string,string>>
     */
    public function getRelationUpdates(): array
    {
        $updates = [];
        $relation = new Orm\Record\Config\Relation();
        $list = $relation->getManyToMany($this->objectConfig);

        foreach ($list as $fields) {
            if (!empty($fields)) {
                /**
                 * @var array<string,mixed> $fields
                 */
                foreach ($fields as $fieldName => $linkType) {
                    $relationObjectName = $this->objectConfig->getRelationsObject($fieldName);
                    if (!is_string($relationObjectName) || !$this->orm->configExists($relationObjectName)) {
                        $updates[$fieldName] = ['name' => (string)$relationObjectName, 'action' => 'add'];
                    }
                }
            }
        }
        return $updates;
    }

    /**
     * Remove object
     * @return bool
     */
    public function remove(): bool
    {
        if ($this->objectConfig->isLocked() || $this->objectConfig->isReadOnly()) {
            $this->errors[] = 'Can not remove locked object table ' . $this->objectConfig->getName();
            return false;
        }

        $sql = null;

        try {
            $model = $this->orm->model($this->objectName);

            if (!$this->tableExists()) {
                return true;
            }

            $db = $this->db;

            $ddl = new Ddl\DropTable($model->table());
            $sql = $db->sql()->buildSqlString($ddl);
            $db->query($sql);
            $this->logSql($sql);
            return true;
        } catch (\Throwable $e) {
            $this->errors[] = $e->getMessage() . ' <br>SQL: ' . $sql;
            return false;
        }
    }

    /**
     * Rename object field
     * @param string $oldName
     * @param string $newName
     * @return bool
     */
    public function renameField(string $oldName, string $newName): bool
    {
        if ($this->objectConfig->isLocked() || $this->objectConfig->isReadOnly()) {
            $this->errors[] = 'Can not build locked object ' . $this->objectConfig->getName();
            return false;
        }

        $fieldConfig = $this->objectConfig->getField($newName);

        $sql = ' ALTER TABLE ' . $this->model->table() . ' CHANGE `' . $oldName . '` ' . $this->getPropertySql(
            $newName,
            $fieldConfig
        );

        try {
            $this->db->query($sql);
            $this->logSql($sql);
            return true;
        } catch (\Throwable $e) {
            echo $e->getMessage();
            $this->errors[] = $e->getMessage() . ' <br>SQL: ' . $sql;
            return false;
        }
    }

    /**
     * Get property SQL query
     * @param string $name
     * @param Orm\Record\Config\Field $field
     * @return string
     */
    abstract protected function getPropertySql(string $name, Orm\Record\Config\Field $field): string;

    /**
     * Update distributed objects
     * @param array<int,array> $list
     * @return bool
     * @throws \Exception
     */
    protected function updateDistributed(array $list): bool
    {
        $shardingConfig = $this->configStorage->get('sharding.php');

        if (!$shardingConfig->get('dist_index_enabled')) {
            return true;
        }

        $lang = $this->lang;
        $usePrefix = true;
        $indexConnection = $shardingConfig->get('dist_index_connection');

        $oConfigPath = $this->objectConfig->getConfigPath();
        $configDir = $this->configStorage->getWrite() . $oConfigPath;

        $objectConfig = $this->orm->config($this->objectName);
        $fieldList = $objectConfig->getDistributedFields();

        if (empty($fieldList)) {
            $this->errors[] = 'Cannot get distributed fields: ' . 'objects/distributed/fields.php';
            return false;
        }

        $distribIndexes = $this->objectConfig->getDistributedIndexesConfig();

        foreach ($distribIndexes as $conf) {
            if (!$conf['is_system']) {
                $field = $this->objectConfig->getField($conf['field']);
                $fieldList[$conf['field']] = $field->__toArray();
                $fieldList[$conf['field']]['db_isNull'] = true;
            }
        }

        foreach ($list as $item) {
            $newObjectName = $item['name'];
            $tableName = $newObjectName;

            $objectData = [
                'data_object' => $this->objectName,
                'connection' => $indexConnection,
                'use_db_prefix' => $usePrefix,
                'disable_keys' => true,
                'locked' => false,
                'readonly' => false,
                'primary_key' => 'id',
                'table' => $tableName,
                'engine' => 'InnoDB',
                'rev_control' => false,
                'link_title' => 'id',
                'save_history' => false,
                'system' => true,
                'fields' => $fieldList,
                'indexes' => [],
            ];

            if (!is_dir($configDir) && !@mkdir($configDir, 0655, true)) {
                $this->errors[] = $lang->get('CANT_WRITE_FS') . ' ' . $configDir;
                return false;
            }

            $newObjectName = strtolower($newObjectName);
            $newConfigPath = $oConfigPath . $newObjectName . '.php';

            if ($this->configStorage->exists($newConfigPath)) {
                $cfg = $this->configStorage->get($newConfigPath);
                $cfg->setData($objectData);
            } else {
                $cfg = Cfg\Factory::create($objectData, $configDir . $newObjectName . '.php');
            }

            /*
             * Write object config
             */
            if (!$this->configStorage->save($cfg)) {
                $this->errors[] = $lang->get('CANT_WRITE_FS') . ' ' . $configDir . $newObjectName . '.php';
                return false;
            }

            $cfg = $this->orm->config($newObjectName, true);

            $cfg->setObjectTitle($this->objectName . ' ID Routes');

            if (!$cfg->save()) {
                $this->errors[] = $lang->get('CANT_WRITE_FS');
                return false;
            }

            /*
             * Build database
             */
            $builder = $this->orm->getBuilder($newObjectName);
            if (!$builder->build()) {
                return false;
            }
        }
        return true;
    }

    /**
     * Create Db_Object`s for relations
     * @throw Exception
     * @param array<string,array<string,string>> $list
     * @return bool
     */
    protected function updateRelations(array $list): bool
    {
        $lang = $this->lang;
        /**
         * @var bool $usePrefix
         */
        $usePrefix = true;
        $connection = $this->objectConfig->get('connection');


        $db = $this->db;
        $tablePrefix = $this->model->getDbPrefix();

        $oConfigPath = $this->objectConfig->getConfigPath();

        $configDir = $this->configStorage->getWrite() . $oConfigPath;
        $fieldList = $this->configStorage->get('objects/relations/fields.php');
        $indexesList = $this->configStorage->get('objects/relations/indexes.php');

        if (empty($fieldList)) {
            throw new Exception('Cannot get relation fields: ' . 'objects/relations/fields.php');
        }

        if (empty($indexesList)) {
            throw new Exception('Cannot get relation indexes: ' . 'objects/relations/indexes.php');
        }

        $fieldList = $fieldList->__toArray();
        $indexesList = $indexesList->__toArray();

        $fieldList['source_id']['link_config']['object'] = $this->objectName;


        foreach ($list as $fieldName => $info) {
            $newObjectName = $info['name'];
            $tableName = $newObjectName;

            $linkedObject = $this->objectConfig->getField($fieldName)->getLinkedObject();

            $fieldList['target_id']['link_config']['object'] = $linkedObject;

            $objectData = [
                'parent_object' => $this->objectName,
                'connection' => $connection,
                'use_db_prefix' => $usePrefix,
                'disable_keys' => false,
                'locked' => false,
                'readonly' => false,
                'primary_key' => 'id',
                'table' => $newObjectName,
                'engine' => 'InnoDB',
                'rev_control' => false,
                'link_title' => 'id',
                'save_history' => false,
                'system' => true,
                'fields' => $fieldList,
                'indexes' => $indexesList,
            ];

            $tables = $db->listTables();

            if ($usePrefix) {
                $tableName = $tablePrefix . $tableName;
            }

            if (in_array($tableName, $tables, true)) {
                throw new Exception(
                    $lang->get('INVALID_VALUE') . ' Table Name: ' . $tableName . ' ' . $lang->get('SB_UNIQUE')
                );
            }

            if (file_exists($configDir . strtolower($newObjectName) . '.php')) {
                throw new Exception(
                    $lang->get('INVALID_VALUE') . ' Object Name: ' . $newObjectName . ' ' . $lang->get('SB_UNIQUE')
                );
            }

            if (!is_dir($configDir) && !@mkdir($configDir, 0655, true)) {
                $this->errors[] = $lang->get('CANT_WRITE_FS') . ' ' . $configDir;
                return false;
            }

            /**
             * @var ConfigInterface<string,mixed> $cfg
             */
            $cfg = Cfg\Factory::create($objectData, $configDir . $newObjectName . '.php');
            /*
             * Write object config
             */
            if (!$this->configStorage->save($cfg)) {
                $this->errors[] = $lang->get('CANT_WRITE_FS') . ' ' . $configDir . $newObjectName . '.php';
                return false;
            }

            $cfg = $this->orm->config($newObjectName);
            $cfg->setObjectTitle(
                $lang->get('RELATIONSHIP_MANY_TO_MANY') . ' ' . $this->objectName . ' & ' . $linkedObject
            );

            if (!$cfg->save()) {
                $this->errors[] = $lang->get('CANT_WRITE_FS') . ' ' . $cfg->getName();
                return false;
            }

            /*
             * Build database
            */
            $builder = $this->orm->getBuilder($newObjectName);
            if (!$builder->build()) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return array<int,array<string,string>>
     */
    public function getDistributedObjectsUpdatesInfo(): array
    {
        if (!$this->objectConfig->isDistributed()) {
            return [];
        }

        $updates = [];

        $idObject = $this->objectConfig->getDistributedIndexObject();
        if (!$this->orm->configExists($idObject)) {
            $updates[] = ['name' => $idObject, 'action' => 'add'];
            return $updates;
        }

        $objectConfig = $this->orm->config($idObject);

        $fields = $this->objectConfig->getDistributedIndexesConfig();

        if (!empty($fields)) {
            $fields = Utils::rekey('field', $fields);
        }

        foreach ($fields as $field) {
            // New field for index object
            if (!$objectConfig->fieldExists($field['field'])) {
                $updates[] = ['name' => $idObject, 'action' => 'update'];
                return $updates;
            }
            $fieldConfig = $this->objectConfig->getField($field['field'])->__toArray();
            $indexConfig = $objectConfig->getField($field['field'])->__toArray();
            unset($fieldConfig['title']);
            unset($indexConfig['title']);
            unset($fieldConfig['db_isNull']);
            unset($fieldConfig['db_isNull']);

            if ($this->objectConfig->getPrimaryKey() == $field['field']) {
                continue;
            }
            unset($fieldConfig['system']);
            unset($indexConfig['system']);
            // field config updated
            if (!empty(Utils::arrayDiffAssocRecursive($fieldConfig, $indexConfig))) {
                $updates[] = ['name' => $idObject, 'action' => 'update'];
                return $updates;
            }
        }
        // delete field from index
        foreach ($objectConfig->getFields() as $field) {
            if (!$field->isSystem() && !isset($fields[$field->getName()])) {
                $updates[] = ['name' => $idObject, 'action' => 'update'];
                return $updates;
            }
        }
        return $updates;
    }

    /**
     * @return array<string,array<string,string>>
     */
    public function getObjectsUpdatesInfo(): array
    {
        $updates = [];
        $relation = new Config\Relation();
        $list = $relation->getManyToMany($this->objectConfig);
        foreach ($list as $fields) {
            if (!empty($fields)) {
                /**
                 * @var array<string,string> $fields
                 */
                foreach ($fields as $fieldName => $linkType) {
                    $relationObjectName = $this->objectConfig->getRelationsObject($fieldName);
                    if (!is_string($relationObjectName) || !$this->orm->configExists($relationObjectName)) {
                        $updates[$fieldName] = ['name' => (string)$relationObjectName, 'action' => 'add'];
                    }
                }
            }
        }
        return $updates;
    }
}
