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

use Dvelum\App\EventManager;
use Dvelum\Cache\CacheInterface;
use Dvelum\Config\ConfigInterface;
use Dvelum\Config\Storage\StorageInterface;
use Dvelum\Lang;
use Dvelum\Lang\Dictionary;
use Dvelum\Orm\Distributed\Record as DistributedRecord;
use Dvelum\Db;
use Dvelum\Orm\Record\Builder\AbstractAdapter;
use Dvelum\Orm\Record\BuilderFactory;
use Dvelum\Orm\Record\Config\FieldFactory;
use Dvelum\Orm\Record\Manager;
use Dvelum\Security\CryptServiceInterface;
use Dvelum\Utils;
use Dvelum\Config;
use Dvelum\Log;
use Psr\Log\LoggerInterface;

class Orm
{
    /**
     * @var array<string,\Dvelum\Orm\Record\Config>
     */
    protected array $configObjects = [];
    /**
     * @var array<string>
     */
    protected array $configFiles = [];
    /**
     * @var array<string,Model|\Dvelum\Orm\Distributed\Model>
     */
    protected array $models = [];
    /**
     * @var ConfigInterface<int|string,mixed>
     */
    protected $configSettings;
    /**
     * @var ConfigInterface<int|string,mixed>
     */
    protected $modelSettings;
    /**
     * @var CryptServiceInterface;
     */
    private $cryptService;
    /**
     * @var Record\Store|null $storage
     */
    protected ?Record\Store $storage = null;
    /**
     * @var DistributedRecord\Store|null $distributedStorage
     */
    protected ?DistributedRecord\Store $distributedStorage = null;

    protected \Closure $distributedStoreLoader;

    protected ?EventManager $eventManager = null;
    /**
     * @var ConfigInterface<int|string,mixed> $config
     */
    protected ConfigInterface $config;
    /**
     * @var LoggerInterface|null $log
     */
    protected ?LoggerInterface $log = null;
    /**
     * @var Record\Config\Translator|false
     */
    protected $translator = false;

    protected string $language;

    protected \Closure $storeLoader;
    /**
     * @var mixed
     */
    protected $store;

    protected Lang $lang;

    protected Config\Storage\StorageInterface $configStorage;

    private BuilderFactory $builderFactory;
    private Distributed $distributedProvider;
    /**
     * @var callable $distributedLoader
     */
    private $distributedLoader;

    private FieldFactory $fieldFactory;
    /**
     * @var callable $fieldFactoryLoader
     */
    private $fieldFactoryLoader;

    /**
     * @param ConfigInterface<int|string,mixed> $config
     * @param Db\ManagerInterface $dbManager
     * @param string $language
     * @param Lang $lang
     * @param StorageInterface $configStorage
     * @param callable $distributedLoader
     * @param callable $fieldFactoryLoader
     * @param CacheInterface|null $cache
     * @throws \Exception
     */
    public function __construct(
        ConfigInterface $config,
        Db\ManagerInterface $dbManager,
        string $language,
        Lang $lang,
        Config\Storage\StorageInterface $configStorage,
        callable $distributedLoader,
        callable $fieldFactoryLoader,
        ?CacheInterface $cache = null
    ) {
        $this->config = $config;
        $this->language = $language;
        $this->lang = $lang;
        $this->eventManager = new EventManager($this, $configStorage);
        $this->configStorage = $configStorage;
        $this->distributedLoader = $distributedLoader;
        $this->fieldFactoryLoader = $fieldFactoryLoader;

        if ($cache) {
            $this->eventManager->setCache($cache);
        }

        $orm = $this;

        $this->modelSettings = Config\Factory::create(
            [
                'hardCacheTime' => $config->get('hard_cache'),
                'dataCache' => $cache,
                'defaultDbManager' => $dbManager,
                'logLoader' => function () use ($orm) {
                    return $orm->getLog();
                }
            ]
        );

        $this->configSettings = Config\Factory::create(
            [
                'configPath' => $config->get('object_configs'),
                'translatorLoader' => function () use ($orm) {
                    return $orm->getTranslator();
                },
                'useForeignKeys' => $config->get('foreign_keys'),
                'ivField' => $config->get('iv_field'),
            ]
        );

        $this->storeLoader = function () use ($orm) {
            return $orm->storage();
        };
        $this->distributedStoreLoader = function () use ($orm) {
            return $orm->distributedStorage();
        };
    }

    /**
     * Get ORM configuration options
     * @return ConfigInterface<int|string,mixed>
     */
    public function getConfig(): ConfigInterface
    {
        return $this->config;
    }

    /**
     * @return bool|Record\Config\Translator
     * @throws \Exception
     */
    public function getTranslator()
    {
        if (empty($this->translator)) {
            $commonFile = $this->language . '/objects.php';
            $objectsDir = $this->language . '/' . $this->getConfig()->get('translations_dir');
            $this->translator = new Record\Config\Translator($commonFile, $objectsDir, $this->lang);
        }
        return $this->translator;
    }

    /**
     * @return CryptServiceInterface
     */
    public function getCryptService(): \Dvelum\Security\CryptServiceInterface
    {
        if (empty($this->cryptService)) {
            $this->cryptService = new \Dvelum\Security\CryptService(Config::storage()->get('crypt.php'));
        }
        return $this->cryptService;
    }

    /**
     * @return LoggerInterface|null
     * @throws \Exception
     */
    public function getLog(): ?LoggerInterface
    {
        if (!empty($this->log)) {
            return $this->log;
        }

        if ($this->config->get('db_object_error_log')) {
            $this->log = new Log\File($this->config->get('db_object_error_log_path'));
            /*
             * Switch to Db_Object error log
             */
            if (!empty($this->config->get('error_log_object'))) {
                $errorModel = $this->model($this->config->get('error_log_object'));
                $errorModel->setLog($this->log);
                $errorTable = $errorModel->table();
                $errorDb = $errorModel->getDbConnection();

                $logDb = new Log\Db('error_log', $errorDb, $errorTable);
                $this->log = new Log\MixedLog($this->log, $logDb);
            }
        }
        return $this->log;
    }

    public function storage(): Record\Store
    {
        if (empty($this->storage)) {
            $storageOptions = [
                'linksObject' => $this->config->get('links_object'),
                'historyObject' => $this->config->get('history_object'),
                'versionObject' => $this->config->get('version_object'),
            ];
            $storeClass = $this->config->get('storage');
            $this->storage = new $storeClass($this, $storageOptions);
            $this->storage->setEventManager($this->eventManager);

            if (!empty($this->log)) {
                $this->storage->setLog($this->log);
            }
        }
        return $this->storage;
    }

    public function distributedStorage(): Record\Store
    {
        if (empty($this->distributedStorage)) {
            $storageOptions = [
                'linksObject' => $this->config->get('links_object'),
                'historyObject' => $this->config->get('history_object'),
                'versionObject' => $this->config->get('version_object'),
            ];
            $distributedStoreClass = $this->config->get('distributed_storage');
            $this->distributedStorage = new $distributedStoreClass(
                $this->distributed(),
                $this,
                $storageOptions
            );
            $this->distributedStorage->setEventManager($this->eventManager);
            if (!empty($this->log)) {
                $this->distributedStorage->setLog($this->log);
            }
        }
        return $this->distributedStorage;
    }

    /**
     * @param string $name
     * @param bool $id
     * @param string|bool $shard
     * @return mixed
     * @throws \Exception
     * @deprecated
     */
    public function object(string $name, $id = false, $shard = false)
    {
        return $this->record($name, $id, $shard);
    }

    /**
     * Factory method of object creation is preferable to use, cf. method  __construct() description
     * @param string $name
     * @param int|bool $id , optional default false
     * @param string|null $shard . optional
     * @return RecordInterface
     * @throws \Exception
     */
    public function record(string $name, $id = false, ?string $shard = null): RecordInterface
    {
        $config = $this->config($name);

        if (!$config->isDistributed()) {
            $recordClass = $this->config->get('record');
            return new $recordClass($this, $config, $id);
        } else {
            if ($config->isShardRequired() && !empty($id) && empty($shard)) {
                throw new \InvalidArgumentException('Shard is required for Object ' . $name);
            }
            $recordClass = $this->config->get('distributed_record');
            return new $recordClass($this->distributed(), $this, $config, $id, $shard);
        }
    }


    /**
     * Factory method of object creation is preferable to use, cf. method  __construct() description
     * @param string $name
     * @param int|int[]|bool $id , optional default false
     * @param string|bool $shard . optional
     * @return RecordInterface[]
     * @throws \Exception
     */
    public function records(string $name, array $id, $shard = false): array
    {
        $recordClass = $this->config->get('record');
        $config = $this->config($name);

        $list = [];
        $model = $this->model($name);
        $data = $model->getItems($id);

        /*
         * Load links info
         */
        $links = $config->getLinks([Record\Config::LINK_OBJECT_LIST]);
        $linksData = [];

        if (!empty($links)) {
            foreach ($links as $fields) {
                foreach ($fields as $field => $linkType) {
                    $fieldObject = $config->getField($field);
                    if ($fieldObject->isManyToManyLink()) {
                        $relationsObject = $config->getRelationsObject($field);
                        if (empty($relationsObject)) {
                            throw new \Exception('Undefined relations object for field ' . $field);
                        }
                        $relationsData = $this->model((string)$relationsObject)->query()
                            ->params(
                                [
                                    'sort' => 'order_no',
                                    'dir' => 'ASC'
                                ]
                            )
                            ->filters(['source_id' => $id])
                            ->fields(['target_id', 'source_id'])
                            ->fetchAll();
                    } else {
                        $linkedObject = $fieldObject->getLinkedObject();
                        if (empty($linkedObject)) {
                            throw new \Exception('Undefined linked object object for field ' . $field);
                        }
                        $linksObject = $this->model($linkedObject)->getStore()->getLinksObjectName();
                        $linksModel = $this->model($linksObject);
                        $relationsData = $linksModel->query()
                            ->params(['sort' => 'order', 'dir' => 'ASC'])
                            ->filters(
                                [
                                    'src' => $name,
                                    'src_id' => $id,
                                    'src_field' => $field,
                                    'target' => $linkedObject
                                ]
                            )
                            ->fields(['target_id', 'source_id' => 'src_id'])
                            ->fetchAll();
                    }
                    if (!empty($relationsData)) {
                        $linksData[$field] = Utils::groupByKey('source_id', $relationsData);
                    }
                }
            }
        }

        $primaryKey = $config->getPrimaryKey();
        foreach ($data as $item) {
            /**
             * @var RecordInterface $o
             */
            $o = new $recordClass($name);
            /*
             * Apply links info
             */
            if (!empty($linksData)) {
                foreach ($linksData as $field => $source) {
                    if (isset($source[$item[$primaryKey]])) {
                        $item[$field] = Utils::fetchCol('target_id', $source[$item[$primaryKey]]);
                    }
                }
            }
            $o->setId($item[$primaryKey]);
            $o->setRawData($item);
            $list[$item[$primaryKey]] = $o;
        }
        /**
         * @var RecordInterface[] $list
         */
        return $list;
    }

    /**
     * Instantiate data structure for the objects named $name
     * @param string $name - object name
     * @param bool $force - reload config
     * @return Record\Config
     * @throws Exception
     */
    public function config(string $name, bool $force = false): Record\Config
    {
        $name = strtolower($name);

        if ($force || !isset($this->configObjects[$name])) {
            $config = new Record\Config(
                $name,
                $this->configSettings,
                $this->configStorage,
                $this->fieldFactory(),
                $this->distributed(),
                $force
            );
            $orm = $this;
            $loader = function () use ($orm) {
                return $orm->getCryptService();
            };
            $config->setCryptServiceLoader($loader);
            $this->configObjects[$name] = $config;
        }
        return $this->configObjects[$name];
    }

    /**
     * Object config existence check
     * @param string $name
     * @return bool
     * @throws \Exception
     */
    public function configExists(string $name): bool
    {
        $name = strtolower($name);

        if (isset($this->configObjects[$name]) || isset($this->configFiles[$name])) {
            return true;
        }

        $cfgPath = $this->configSettings->get('configPath');

        if ($this->configStorage->exists($cfgPath . $name . '.php')) {
            $this->configFiles[$name] = $cfgPath . $name . '.php';
            return true;
        }

        return false;
    }

    public function recordExists(string $name, int $id): bool
    {
        if (!$this->configExists($name)) {
            return false;
        }
        try {
            $cfg = $this->config($name);
        } catch (\Throwable $e) {
            return false;
        }

        $model = $this->model($name);
        $data = $model->getItem($id);

        if (empty($data)) {
            return false;
        }
        return true;
    }

    /**
     * @param string $name
     * @param array<int> $id
     * @return bool
     * @throws Exception
     */
    public function recordsExists(string $name, array $id): bool
    {
        if (!$this->configExists($name)) {
            return false;
        }
        try {
            $cfg = $this->config($name);
        } catch (\Throwable $e) {
            return false;
        }

        $model = $this->model($name);
        $data = $model->getItems($id);

        if (empty($data)) {
            return false;
        }

        $data = Utils::fetchCol($cfg->getPrimaryKey(), $data);

        foreach ($id as $v) {
            if (!in_array((int)$v, $data, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Get ORM Object Config settings
     * @return ConfigInterface<int|string,mixed>
     */
    public function getConfigSettings(): ConfigInterface
    {
        return $this->configSettings;
    }

    /**
     * Get Orm Model Settings
     * @return ConfigInterface<int|string,mixed>
     */
    public function getModelSettings(): ConfigInterface
    {
        return $this->modelSettings;
    }

    /**
     * Factory method of model instantiation
     * @param string $objectName — the name of the object in ORM
     * @return Model
     */
    public function model(string $objectName): Model
    {
        $listName = strtolower($objectName);

        if (isset($this->models[$listName])) {
            return $this->models[$listName];
        }

        $objectName = implode('_', array_map('ucfirst', explode('_', $listName)));

        $className = 'Model_' . $objectName;
        $nameSpacedClassName = '\\App\\' . str_replace('_', '\\', $className);
        $distModelClassName = '\\Dvelum' . $nameSpacedClassName;

        $modelSettings = $this->modelSettings;

        if ($this->config($objectName)->isDistributed()) {
            $modelSettings['storeLoader'] = $this->distributedStoreLoader;
        } else {
            $modelSettings['storeLoader'] = $this->storeLoader;
        }

        $classVariants = [$className, $nameSpacedClassName, $distModelClassName];
        $modelClass = null;
        foreach ($classVariants as $name) {
            if (class_exists($name)) {
                $modelClass = $name;
                break;
            }
        }
        if ($modelClass !== null) {
            if (is_subclass_of($modelClass, \Dvelum\Orm\Distributed\Model::class)) {
                $this->models[$listName] = new $modelClass(
                    $objectName,
                    $modelSettings,
                    $this->config,
                    $this,
                    $this->configStorage,
                    $this->distributed()
                );
            } else {
                $this->models[$listName] = new $modelClass(
                    $objectName, $modelSettings, $this->config, $this, $this->configStorage
                );
            }
        } else {
            // Create Virtual Model
            if ($this->config($objectName)->isDistributed()) {
                $this->models[$listName] = new Distributed\Model(
                    $objectName,
                    $modelSettings,
                    $this->config,
                    $this,
                    $this->configStorage,
                    $this->distributed()
                );
            } else {
                $this->models[$listName] = new Model(
                    $objectName, $modelSettings, $this->config, $this, $this->configStorage
                );
            }
        }

        return $this->models[$listName];
    }

    /**
     * Get Db statistics adapter
     * @return Stat
     * @throws \Exception
     */
    public function stat(): Stat
    {
        return new Stat($this, $this->distributed(), $this->lang->getDictionary());
    }

    /**
     * Get ORM object structure builder (sync db structure)
     * @param string $objectName
     * @return AbstractAdapter
     * @throws Exception
     */
    public function getBuilder(string $objectName): AbstractAdapter
    {
        return $this->getBuilderFactory()->factory(
            $this,
            $this->configStorage,
            $this->lang->getDictionary(),
            $objectName
        );
    }

    private function getBuilderFactory(): BuilderFactory
    {
        $ormConfig = $this->configStorage->get('orm.php')->__toArray();

        if (!isset($this->builderFactory)) {
            $this->builderFactory = new BuilderFactory(
                [
                    'writeLog' => $ormConfig['use_orm_build_log'],
                    'foreignKeys' => $ormConfig['foreign_keys'],
                    'logPrefix' => $ormConfig['build_log_prefix'],
                    'logsPath' => $ormConfig['log_path']
                ]
            );
        }
        return $this->builderFactory;
    }

    public function getRecordManager(): Manager
    {
        return new Manager($this->configStorage, $this);
    }

    public function distributed(): Distributed
    {
        if (!isset($this->distributedProvider)) {
            $this->distributedProvider = ($this->distributedLoader)();
        }
        return $this->distributedProvider;
    }

    public function fieldFactory(): FieldFactory
    {
        if (!isset($this->fieldFactory)) {
            $this->fieldFactory = ($this->fieldFactoryLoader)();
        }
        return $this->fieldFactory;
    }
}