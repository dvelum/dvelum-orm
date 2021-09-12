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

namespace Dvelum\Orm\Record;

use Dvelum\Orm;
use Dvelum\Security\CryptServiceInterface;
use Dvelum\Config as Cfg;
use Dvelum\Orm\Exception;

/**
 * Orm Record structure config
 */
class Config
{
    public const LINK_OBJECT = 'object';
    public const LINK_OBJECT_LIST = 'multi';
    public const LINK_DICTIONARY = 'dictionary';

    public const RELATION_MANY_TO_MANY = 'many_to_many';

    public const SHARDING_TYPE_GLOABAL_ID = 'global_id';
    public const SHARDING_TYPE_KEY = 'sharding_key';
    public const SHARDING_TYPE_KEY_NO_INDEX = 'sharding_key_no_index';
    public const SHARDING_TYPE_VIRTUAL_BUCKET = 'virtual_bucket';

    /**
     * @var Cfg\ConfigInterface<int|string,mixed> $settings
     */
    protected $settings;

    /**
     * @var Cfg\ConfigInterface<int|string,mixed>
     */
    protected $config;

    /**
     * Additional fields config for objects under rev. control
     * @var array<int|string,mixed>
     */
    protected static $vcFields;

    /**
     * List of system fields used for encryption
     * @var array<int|string,mixed>
     */
    protected static $cryptFields;

    /**
     * List of system fields used for sharding
     * @var array<int|string,mixed>
     */
    protected $distributedFields;

    /**
     * @var string $name
     */
    protected string $name;

    /**
     * Translation adapter
     * @var Orm\Record\Config\Translator | bool
     */
    protected $translator = false;

    /**
     * Translation flag
     * @var bool
     */
    protected bool $translated = false;

    /**
     * Database table prefix
     * @var string
     */
    protected string $dbPrefix;
    /**
     * @var array<int|string,mixed>
     */
    protected array $localCache = [];

    /**
     * @var CryptServiceInterface
     */
    private ?CryptServiceInterface $cryptService = null;
    /**
     * @var callable|null $cryptServiceLoader
     */
    protected $cryptServiceLoader = null;

    protected Orm\Distributed $distributed;

    protected Cfg\Storage\StorageInterface $configStorage;

    protected Orm\Record\Config\FieldFactory $fieldFactory;

    /**
     * Reload object Properties
     */
    public function reloadProperties(): void
    {
        $this->localCache = [];
        $this->loadProperties();
    }

    /**
     * @param string $name
     * @param Cfg\ConfigInterface<int|string,mixed> $settings
     * @param Cfg\Storage\StorageInterface $configStorage
     * @param Config\FieldFactory $fieldFactory
     * @param Orm\Distributed $distributed
     * @param bool $force
     * @throws \Exception
     */
    public function __construct(
        string $name,
        Cfg\ConfigInterface $settings,
        Cfg\Storage\StorageInterface $configStorage,
        Orm\Record\Config\FieldFactory $fieldFactory,
        Orm\Distributed $distributed,
        bool $force = false
    ) {
        $this->fieldFactory = $fieldFactory;
        $this->configStorage = $configStorage;
        $this->distributed = $distributed;
        $this->settings = $settings;
        $this->name = strtolower($name);

        $path = $this->settings->get('configPath') . $name . '.php';

        $this->config = $configStorage->get($path, !$force, false);
        $this->loadProperties();
    }


    /**
     * Get config files path
     * @return string
     * @throws \Exception
     */
    public function getConfigPath(): string
    {
        return $this->settings->get('configPath');
    }

    /**
     * Get object name
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Lazy loading for items translation
     */
    protected function prepareTranslation(): void
    {
        if ($this->translated) {
            return;
        }

        $dataLink = &$this->config->dataLink();
        $translator = $this->getTranslator();
        $translator->translate($this->name, $dataLink);
        $this->translated = true;
    }

    /**
     * Prepare config, load system properties
     */
    protected function loadProperties(): void
    {
        $dataLink = &$this->config->dataLink();
        $pKeyName = $this->getPrimaryKey();

        if (!isset($dataLink['distributed'])) {
            $dataLink['distributed'] = false;
        }


        $keyConfig = 'system/pk_field.php';

        if ($this->isDistributed()) {
            $shardingType = $this->getShardingType();
            switch ($shardingType) {
                case self::SHARDING_TYPE_KEY_NO_INDEX:
                    break;
                case self::SHARDING_TYPE_VIRTUAL_BUCKET:
                    // not using auto increment
                    if ($this->getBucketMapperKey() == $pKeyName) {
                        $keyConfig = 'distributed/pk_field.php';
                    }
                    break;
                default:
                    // not using autoincrement
                    $keyConfig = 'distributed/pk_field.php';
                    break;
            }
        }
        $dataLink['fields'][$pKeyName] = $this->configStorage->get(
            $this->settings->get('configPath') . $keyConfig
        )->__toArray();

        /*
         * System index init
         */
        $dataLink['indexes']['PRIMARY'] = array(
            'columns' => [$pKeyName],
            'fulltext' => false,
            'unique' => true,
            'primary' => true,
            'system' => true,
            // distributed objects does not use auto increment index
            'db_auto_increment' => $dataLink['fields'][$pKeyName]['db_auto_increment'],
            'is_search' => true,
            'lazyLang' => true
        );

        /*
         * Load additional fields for object under revision control
         */
        if (isset($dataLink['rev_control']) && $dataLink['rev_control']) {
            $dataLink['fields'] = array_merge($dataLink['fields'], $this->getVcFields());
        }

        /**
         * Load additional encryption fields
         */
        if ($this->hasEncrypted()) {
            $dataLink['fields'] = array_merge($dataLink['fields'], $this->getEncryptionFields());
        }


        if ((isset($dataLink['distributed']) && $dataLink['distributed']) || $this->isIndexObject()) {
            $dataLink['fields'] = array_merge($dataLink['fields'], $this->getDistributedFields());
        }

        if ($this->isIndexObject()) {
            $dataLink['indexes'] = $this->initIndexIndexes();
        }
    }

    /**
     * Get Version control fields
     * @return array<int|string,mixed>
     * @throws \Exception
     */
    protected function getVcFields(): array
    {
        if (!isset(self::$vcFields)) {
            self::$vcFields = $this->configStorage->get(
                $this->settings->get('configPath') . 'vc/vc_fields.php'
            )->__toArray();
        }

        return self::$vcFields;
    }

    /**
     * Get encryption fields
     * @return array<int|string,mixed>
     * @throws \Exception
     */
    protected function getEncryptionFields(): array
    {
        if (!isset(self::$cryptFields)) {
            self::$cryptFields = $this->configStorage->get(
                $this->settings->get('configPath') . 'enc/fields.php'
            )->__toArray();
        }

        return self::$cryptFields;
    }

    /**
     * Get a list of fields to be used for search
     * @return array<int|string,mixed>
     * @throws \Exception
     */
    public function getSearchFields(): array
    {
        $fields = [];
        $fieldsConfig = $this->get('fields');

        foreach ($fieldsConfig as $k => $v) {
            if ($this->getField($k)->isSearch()) {
                $fields[] = $k;
            }
        }
        return $fields;
    }

    /**
     * Get a configuration element by key (system method)
     * @param string $key
     * @return mixed
     * @throws \Exception
     */
    public function get(string $key)
    {
        if ($key === 'fields' || $key === 'title') {
            $this->prepareTranslation();
        }

        return $this->config->get($key);
    }

    /**
     * Check if the object is using revision control
     * @return bool
     * @throws \Exception
     */
    public function isRevControl(): bool
    {
        return ($this->config->offsetExists('rev_control') && $this->config->get('rev_control'));
    }

    /**
     * Get a list of indices (from the configuration)
     * @param boolean $includeSystem -optional default = true
     * @return array<int|string,mixed>
     * @throws \Exception
     */
    public function getIndexesConfig($includeSystem = true): array
    {
        $list = [];
        if ($this->config->offsetExists('indexes')) {
            foreach ($this->config->get('indexes') as $k => $v) {
                if (!$includeSystem && isset($v['system']) && $v['system']) {
                    continue;
                }
                $list[$k] = $v;
            }
        }
        return $list;
    }

    /**
     * Get the field configuration
     * @param string $field
     * @return array<int|string,mixed>
     * @throws Exception
     */
    public function getFieldConfig(string $field): array
    {
        $this->prepareTranslation();

        if (!isset($this->config['fields'][$field])) {
            throw new Exception('Invalid field name: ' . $field);
        }

        return $this->config['fields'][$field];
    }

    /**
     * Get index config
     * @param string $index
     * @return array<int|string,mixed>
     * @throws Exception
     */
    public function getIndexConfig($index): array
    {
        $this->prepareTranslation();

        if (!isset($this->config['indexes'][$index])) {
            throw new Exception('indexes Index name: ' . $index);
        }

        return $this->config['indexes'][$index];
    }

    /**
     * Get the configuration of all fields
     * @param bool $includeSystem -optional default = true
     * @return array<string,mixed>
     * @throws \Exception
     */
    public function getFieldsConfig(bool $includeSystem = true): array
    {
        $this->prepareTranslation();

        if ($includeSystem) {
            return $this->config['fields'];
        }

        $fields = $this->config['fields'];
        unset($fields[$this->getPrimaryKey()]);

        foreach ($fields as $k => $field) {
            if (isset($field['system']) && $field['system']) {
                unset($fields[$k]);
            }
        }
        return $fields;
    }

    /**
     * Get object fields
     * @return Config\Field[]
     * @throws \Exception
     */
    public function getFields(): array
    {
        $result = [];
        $config = $this->getFieldsConfig();
        foreach ($config as $name => $cfg) {
            $result[$name] = $this->getField($name);
        }
        return $result;
    }

    /**
     * Get the configuration of system fields
     * @return array<int|string,mixed>
     * @throws \Exception
     */
    public function getSystemFieldsConfig(): array
    {
        $this->prepareTranslation();
        $primaryKey = $this->getPrimaryKey();
        $fields = [];

        if ($this->isRevControl()) {
            $fields = $this->getVcFields();
        }

        if ($this->hasEncrypted()) {
            $fields = array_merge($fields, $this->getEncryptionFields());
        }

        $fields[$primaryKey] = $this->config['fields'][$primaryKey];

        return $fields;
    }

    /**
     * Get a list of fields linking to external objects
     * @param array<int|string,mixed> $linkTypes - optional link type filter
     * @param bool $groupByObject - group field by linked object, default true
     * @return array<string,array>
     *  [objectName=>[field => link_type]] | [field =>["object"=>objectName,"link_type"=>link_type]]
     * @throws \Exception
     */
    public function getLinks(
        $linkTypes = [Orm\Record\Config::LINK_OBJECT, Orm\Record\Config::LINK_OBJECT_LIST],
        $groupByObject = true
    ): array {
        $relation = new Orm\Record\Config\Relation();
        return $relation->getLinks($this, $linkTypes, $groupByObject);
    }

    /**
     * Check if the object uses history log
     * @return bool
     * @throws \Exception
     */
    public function hasHistory(): bool
    {
        if ($this->config->offsetExists('save_history') && $this->config->get('save_history')) {
            return true;
        }
        return false;
    }

    /**
     * Check if the object uses extended history log
     * @return bool
     * @throws \Exception
     */
    public function hasExtendedHistory(): bool
    {
        if (
            $this->config->offsetExists('save_history')
            &&
            $this->config->get('save_history')
            &&
            $this->config->offsetExists('log_detalization')
            &&
            $this->config->get('log_detalization') === 'extended'
        ) {
            return true;
        }

        return false;
    }

    /**
     * Check if object has db prefix
     * @return bool
     * @throws \Exception
     */
    public function hasDbPrefix(): bool
    {
        return $this->config->get('use_db_prefix');
    }

    /**
     * Check if the field is present in the description
     * @param string $field
     * @return bool
     */
    public function fieldExists(string $field): bool
    {
        return isset($this->config['fields'][$field]);
    }

    /**
     * Get the name of the class, which is the field validator
     * @param string $field
     * @return mixed  string class name / boolean false
     * @throws Exception
     */
    public function getValidator(string $field)
    {
        if (!$this->fieldExists($field)) {
            throw new Exception('Invalid property name');
        }

        if (
            isset($this->config['fields'][$field]['validator']) &&
            !empty($this->config['fields'][$field]['validator'])
        ) {
            return $this->config['fields'][$field]['validator'];
        }

        return false;
    }

    /**
     * Convert into array
     * @return array<int|string,mixed>
     */
    public function __toArray(): array
    {
        $this->prepareTranslation();
        return $this->config->__toArray();
    }

    /**
     * Get the title for the object
     * @return string
     */
    public function getTitle(): string
    {
        $this->prepareTranslation();
        return $this->config['title'];
    }

    /**
     * Set object title
     * @param string $title
     * @return void
     */
    public function setObjectTitle(string $title): void
    {
        $this->prepareTranslation();
        $this->config['title'] = $title;
    }

    /**
     * Get the name of the field linking to this object and used as a text representation
     * @return string
     * @throws \Exception
     */
    public function getLinkTitle(): string
    {
        $this->prepareTranslation();

        if (isset($this->config['link_title']) && !empty($this->config['link_title'])) {
            return $this->config['link_title'];
        } else {
            return $this->getPrimaryKey();
        }
    }

    /**
     * Check if object is readonly
     * @return bool
     * @throws \Exception
     */
    public function isReadOnly(): bool
    {
        return $this->config->get('readonly');
    }

    /**
     * Check if object structure is locked
     * @return bool
     * @throws \Exception
     */
    public function isLocked(): bool
    {
        return $this->config->get('locked');
    }

    /**
     * Check if there are transactions available for this type of objects
     * @return bool
     * @throws \Exception
     */
    public function isTransact(): bool
    {
        if (strtolower($this->config->get('engine')) === 'innodb') {
            return true;
        }
        return false;
    }

    /**
     * Save the object configuration
     */
    public function save(): bool
    {
        $fields = $this->getFieldsConfig(false);
        $indexes = $this->getIndexesConfig(false);

        $config = clone $this->config;
        $translator = $this->getTranslator();

        $translation = $translator->getTranslation($this->getName(), true);
        $translation['title'] = $this->config->get('title');

        foreach ($fields as $field => & $cfg) {
            $translation['fields'][$field] = $cfg['title'];
            unset($cfg['title']);
        }
        unset($cfg);

        $config->set('fields', $fields);
        $config->set('indexes', $indexes);
        $config->offsetUnset('title');

        if ($this->isDistributed()) {
            $config->set('distributed_indexes', $this->getDistributedIndexesConfig(false));
        }

        try {
            $translator->save($this->getName(), $translation);
        } catch (\Exception $e) {
            return false;
        }
        return Cfg::storage()->save($config);
    }

    /**
     * Replace configuration data with an array
     * @param array<int|string,mixed> $data
     * @return void
     */
    public function setData(array $data): void
    {
        $this->config->setData($data);
    }

    /**
     * Get configuration as array
     * @return array<int|string,mixed>
     */
    public function getData(): array
    {
        return $this->config->__toArray();
    }

    /**
     * Init indexes for distributed index object
     * @return array<int|string,mixed>
     * @throws \Exception
     */
    protected function initIndexIndexes(): array
    {
        $list = $this->config->get('indexes');
        $shardingField = $this->configStorage->get('sharding.php')->get('shard_field');

        $list[$shardingField] = [
            'columns' => [$shardingField],
            'fulltext' => false,
            'unique' => false,
            'primary' => false,
            'db_auto_increment' => false,
            'is_search' => false,
            'lazyLang' => false,
            'system' => true
        ];

        $dataObject = $this->createConfigObject($this->getDataObject());
        $dataIndexes = $dataObject->getIndexesConfig();
        $currentFields = $this->getFields();

        foreach ($currentFields as $field) {
            $fieldName = $field->getName();
            if (isset($list[$fieldName]) || $fieldName == $this->getPrimaryKey()) {
                continue;
            }
            if (
                isset($dataIndexes[$fieldName]) &&
                count($dataIndexes[$fieldName]['columns']) === 1 &&
                $dataIndexes[$fieldName]['columns'][0] === $fieldName
            ) {
                $list[$fieldName] = $dataIndexes[$fieldName];
            } else {
                $list[$fieldName] = [
                    'columns' => [$fieldName],
                    'fulltext' => false,
                    'unique' => false,
                    'primary' => false,
                    'db_auto_increment' => false,
                    'is_search' => true,
                    'lazyLang' => false,
                    'system' => true
                ];
            }
            $list[$fieldName]['system'] = true;
        }
        return $list;
    }

    /**
     * Get list of distributed indexes
     * @param bool $includeSystem
     * @return array<int|string,mixed>
     * @throws \Exception
     */
    public function getDistributedIndexesConfig(bool $includeSystem = true): array
    {
        if (!$this->isDistributed()) {
            return [];
        }

        $list = [];

        if ($this->config->offsetExists('distributed_indexes')) {
            $list = $this->config->get('distributed_indexes');
        }

        // Set Required Indexes
        if ($includeSystem) {
            $shardingField = $this->configStorage->get('sharding.php')->get('shard_field');
            $primaryKey = $this->getPrimaryKey();
            $list[$primaryKey] = [
                'field' => $primaryKey,
                'is_system' => true,
            ];
            $list[$shardingField] = ['field' => $shardingField, 'is_system' => true];
            $distributedKey = $this->getShardingKey();
            if (!empty($distributedKey) && $distributedKey !== $primaryKey) {
                $unique = false;
                $type = $this->getShardingType();
                if ($type === self::SHARDING_TYPE_KEY_NO_INDEX || $type === self::SHARDING_TYPE_VIRTUAL_BUCKET) {
                    $unique = true;
                }
                $list[$distributedKey] = ['field' => $distributedKey, 'is_system' => true, 'unique' => $unique];
            }
        }
        return $list;
    }

    /**
     * Get Config object
     * @return Cfg\ConfigInterface<int|string,mixed>
     */
    public function getConfig(): Cfg\ConfigInterface
    {
        return $this->config;
    }

    /**
     * Check if object is system defined
     * @return bool
     */
    public function isSystem(): bool
    {
        $link = &$this->config->dataLink();
        if (isset($link['system']) && $this->config['system']) {
            return true;
        }

        return false;
    }

    /**
     * service stub
     * @return string
     * @throws \Exception
     */
    public function getPrimaryKey(): string
    {
        if (isset($this->localCache['primary_key'])) {
            return $this->localCache['primary_key'];
        }

        $key = 'id';

        if ($this->config->offsetExists('primary_key')) {
            $cfgKey = $this->config->get('primary_key');
            if (!empty($cfgKey)) {
                $key = $cfgKey;
            }
        }

        $this->localCache['primary_key'] = $key;

        return $key;
    }

    /**
     * Inject translation adapter
     * @param Config\Translator $translator
     * @return void
     */
    public function setTranslator(Config\Translator $translator): void
    {
        $this->translator = $translator;
    }

    /**
     * Get Translation adapter
     * @return Config\Translator
     * @throws \Exception
     */
    public function getTranslator(): Config\Translator
    {
        if (empty($this->translator)) {
            $this->translator = $this->settings->get('translatorLoader')();
        }

        return $this->translator;
    }

    /**
     * Check for encoded fields
     * @return bool
     */
    public function hasEncrypted(): bool
    {
        foreach ($this->config['fields'] as $config) {
            if (isset($config['type']) && $config['type'] === 'encrypted') {
                return true;
            }
        }
        return false;
    }

    /**
     * Get names of encrypted fields
     * @return array<int|string,mixed>
     * @throws \Exception
     */
    public function getEncryptedFields(): array
    {
        $fields = [];
        $fieldsConfig = $this->get('fields');

        foreach ($fieldsConfig as $k => $v) {
            if (isset($v['type']) && $v['type'] === 'encrypted') {
                $fields[] = $k;
            }
        }

        return $fields;
    }


    /**
     * Get public key field
     * @return string
     * @throws \Exception
     */
    public function getIvField(): string
    {
        return $this->settings->get('ivField');
    }

    /**
     * Get name of relations Db_Object
     * @param string $field
     * @return bool|string
     * @throws Exception
     */
    public function getRelationsObject(string $field)
    {
        $relation = new Orm\Record\Config\Relation();
        return $relation->getRelationsObject($this, $field);
    }

    /**
     * Check if field is system field of version control
     * @param string $field - field name
     * @return bool
     * @throws \Exception
     */
    public function isVcField($field): bool
    {
        $vcFields = $this->getVcFields();
        return isset($vcFields[$field]);
    }

    /**
     * Check if object is relations object
     * @return bool
     * @throws \Exception
     */
    public function isRelationsObject(): bool
    {
        if (
            $this->isSystem() &&
            $this->config->offsetExists('parent_object') &&
            !empty($this->config->get('parent_object'))
        ) {
            return true;
        }

        return false;
    }

    /**
     * Check if object is sharding index
     */
    public function isIndexObject(): bool
    {
        $link = &$this->config->dataLink();
        if (
            isset($link['system'])
            &&
            $link['system']
            &&
            isset($link['data_object'])
            &&
            !empty($link['data_object'])
            &&
            ($this->createConfigObject($link['data_object']))->isDistributed()
        ) {
            return true;
        }

        return false;
    }

    /**
     * Get Data object for index
     * @return string
     * @throws Exception
     */
    public function getDataObject(): string
    {
        if (!$this->isIndexObject()) {
            throw new Exception('Cannot get data object. ' . $this->getName() . ' is not index object');
        }
        return $this->config->get('data_object');
    }

    /**
     * Get object field
     * @param string $name
     * @return Config\Field
     * @throws \Exception
     */
    public function getField(string $name): Config\Field
    {
        $name = (string)$name;
        return $this->fieldFactory->getField($this, $name);
    }

    /**
     * Set crypt service loader
     * @param callable $loader
     */
    public function setCryptServiceLoader(callable $loader): void
    {
        $this->cryptServiceLoader = $loader;
    }

    /**
     * Set encryption service adapter
     * @param CryptServiceInterface $service
     */
    public function setCryptService(CryptServiceInterface $service): void
    {
        $this->cryptService = $service;
    }

    /**
     * Get encryption service adapter
     * @return CryptServiceInterface
     */
    public function getCryptService(): CryptServiceInterface
    {
        if (empty($this->cryptService)) {
            /**
             * @var callable $service
             */
            $service = $this->cryptServiceLoader;
            $this->cryptService = $service();
        }
        return $this->cryptService;
    }

    /**
     * Check if object uses sharding strategy
     * @return bool
     */
    public function isDistributed(): bool
    {
        $link = &$this->config->dataLink();

        if (isset($link['distributed']) && $link['distributed']) {
            return true;
        }
        return false;
    }

    /**
     * Chek if object loader requires Shard
     * @return bool
     * @throws \Exception
     */
    public function isShardRequired(): bool
    {
        if (!$this->isDistributed()) {
            return false;
        }

        switch ($this->getShardingType()) {
            case self::SHARDING_TYPE_VIRTUAL_BUCKET:
            case self::SHARDING_TYPE_KEY_NO_INDEX:
                return true;
            default:
                return false;
        }
    }

    /**
     * Get object for storing distributed id for current object
     * @return string
     * @throws Exception
     */
    public function getDistributedIndexObject()
    {
        if ($this->isDistributed()) {
            return $this->getName() . $this->configStorage->get('sharding.php')->get('dist_index_postfix');
        }
        throw new Exception('Object has no distribution');
    }

    /**
     * Check if object has global distributed index
     */
    public function hasDistributedIndexRecord(): bool
    {
        if ($this->isDistributed()) {
            $sharding = $this->getShardingType();
            if (in_array($sharding, [self::SHARDING_TYPE_GLOABAL_ID, self::SHARDING_TYPE_KEY])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get system sharding fields
     * @return array<int|string,mixed>
     * @throws \Exception
     */
    public function getDistributedFields(): array
    {
        if (!isset($this->distributedFields)) {
            $this->distributedFields = $this->configStorage->get(
                $this->settings->get('configPath') . 'distributed/fields.php'
            )->__toArray();
        }

        $type = $this->getShardingType();
        if ($type == self::SHARDING_TYPE_KEY_NO_INDEX || $type === self::SHARDING_TYPE_KEY) {
            $key = $this->getShardingKey();
            if (!empty($key)) {
                $this->distributedFields[$key] = $this->getField($key)->getConfig();
                if ($this->isIndexObject()) {
                    $this->distributedFields[$key]['system'] = true;
                }
            }
        }

        if (
            $type === self::SHARDING_TYPE_VIRTUAL_BUCKET ||
            (
                $this->isIndexObject() &&
                $this->createConfigObject($this->getDataObject())->getShardingType(
                ) === self::SHARDING_TYPE_VIRTUAL_BUCKET
            )
        ) {
            $bucketFields = $this->configStorage->get(
                $this->settings->get('configPath') . 'distributed/bucket_fields.php'
            )->__toArray();
            foreach ($bucketFields as $k => $v) {
                $this->distributedFields[$k] = $v;
            }
        }
        return $this->distributedFields;
    }

    /**
     * @param string $name
     * @return Config
     */
    private function createConfigObject(string $name): Config
    {
        return new self($name, $this->settings, $this->configStorage, $this->fieldFactory, $this->distributed);
    }

    /**
     * Get sharding type for distributed object
     * @return null|string
     * @throws \Exception
     */
    public function getShardingType(): ?string
    {
        if (!$this->config->offsetExists('sharding_type')) {
            return null;
        }
        return $this->config->get('sharding_type');
    }

    /**
     * Get distributed key field
     * @return null|string
     * @throws \Exception
     */
    public function getShardingKey(): ?string
    {
        $type = $this->getShardingType();

        if (!$this->isDistributed() || empty($type)) {
            return null;
        }

        $key = null;
        switch ($type) {
            case self::SHARDING_TYPE_GLOABAL_ID:
                $key = $this->getPrimaryKey();
                break;
            case self::SHARDING_TYPE_KEY:
            case self::SHARDING_TYPE_KEY_NO_INDEX:
                if ($this->config->offsetExists('sharding_key')) {
                    $key = $this->config->get('sharding_key');
                }
                break;
            case self::SHARDING_TYPE_VIRTUAL_BUCKET:
                $key = $this->distributed->getBucketField();
                break;
        }
        return $key;
    }

    /**
     * Get key used for mapping object to virtual bucket.
     * Only for Virtual Bucket sharding
     * @return string|null
     * @throws \Exception
     */
    public function getBucketMapperKey(): ?string
    {
        $type = $this->getShardingType();

        if (!$this->isDistributed() || empty($type) || $type != self::SHARDING_TYPE_VIRTUAL_BUCKET) {
            return null;
        }

        $key = null;
        if ($this->config->offsetExists('sharding_key')) {
            $key = $this->config->get('sharding_key');
        }

        return $key;
    }
}
