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

namespace Dvelum\App\Orm\Api\Controller;

use Dvelum\App\Orm\Api\Manager;
use Dvelum\App\Orm\Api\Controller;
use Dvelum\Config;
use Dvelum\Lang;
use Dvelum\Orm;

class Record extends Controller
{
    public function indexAction(): void
    {
    }

    public function validateRecordAction(): void
    {
        $object = $this->request->post('object', 'string', '');
        $shard = $this->request->post('shard', 'string', '');

        if (!$this->ormService->configExists($object)) {
            $this->response->error($this->lang->get('WRONG_REQUEST'));
            return;
        }

        /**
         * @var Orm\Stat $stat
         */
        $stat = $this->container->get(\Dvelum\Orm\Stat::class);

        $config = $this->ormService->config($object);

//        $validateShard = false;
//        if(strlen($shard) && $config->isDistributed()){
//            $validateShard = true;
//        }

        if ($config->isDistributed()) {
            $data = $stat->validateDistributed($object, $shard);
        } else {
            $data = $stat->validate($object);
        }
        $this->response->success($data);
    }

    /**
     * Validate Object Db Structure
     */
    public function validateAction(): void
    {
        $engineUpdate = false;

        $name = $this->request->post('name', 'string', false);
        $shard = $this->request->post('shard', 'string', '');

        if (!$name) {
            $this->response->error($this->lang->get('WRONG_REQUEST'));
            return;
        }

        $objectConfig = $this->ormService->config($name);

        try {
            /**
             * @var Orm\Record\Config $objConfig
             */
            $objConfig = $this->ormService->config($name);
        } catch (\Exception $e) {
            $this->response->error($this->lang->get('CANT_GET_VALIDATE_INFO'));
            return;
        }

        $builder = $this->ormService->getBuilder($name);

        $colUpd = [];
        $indUpd = [];
        $keyUpd = [];
        $shardObjects = [];

        $checkColumns = false;
        $tableExists = false;

        if (strlen($shard) && $objectConfig->isDistributed()) {
            $model = $this->ormService->model($name);
            $connectionName = $model->getConnectionName();
            $db = $model->getDbManager()->getDbConnection($connectionName, null, $shard);
            $builder->setConnection($db);
            $checkColumns = true;
        } elseif ($objectConfig->isDistributed()) {
            $tableExists = true;
        } else {
            $checkColumns = true;
        }

        if ($checkColumns) {
            $tableExists = $builder->tableExists();
            if ($tableExists) {
                $colUpd = $builder->prepareColumnUpdates();
                $indUpd = $builder->prepareIndexUpdates();
                $keyUpd = $builder->prepareKeysUpdate();

                if (method_exists($builder, 'prepareEngineUpdate')) {
                    $engineUpdate = $builder->prepareEngineUpdate();
                }
            }
        }

        $objects = $builder->getRelationUpdates();
        $ormConfig = $this->configStorage->get('sharding.php');

        if ($objConfig->isDistributed() && $ormConfig->get('dist_index_enabled')) {
            $shardObjects = $builder->getDistributedObjectsUpdatesInfo();
        }

        if (
            empty($colUpd) &&
            empty($indUpd) &&
            empty($keyUpd) &&
            $tableExists &&
            !$engineUpdate &&
            empty($objects) &&
            empty($shardObjects)
        ) {
            $this->response->success([], ['nothingToDo' => true]);
            return;
        }

        /**
         * @var \Dvelum\Template\Service $templateService
         */
        $templateService = $this->container->get(\Dvelum\Template\Service::class);
        $template = $templateService->getTemplate();
        $template->disableCache();
        $template->setData(
            [
                'engineUpdate' => $engineUpdate,
                'columns' => $colUpd,
                'indexes' => $indUpd,
                'objects' => $objects,
                'keys' => $keyUpd,
                'tableExists' => $tableExists,
                'tableName' => $this->ormService->model($name)->table(),
                'lang' => $this->lang,
                'shardObjects' => $shardObjects
            ]
        );
        $msg = $template->render('orm_validate_msg.php');
        $this->response->success([], array('text' => $msg, 'nothingToDo' => false));
    }

    /**
     * Build object action
     */
    public function buildAction(): void
    {
        if (!$this->checkCanEdit()) {
            return;
        }

        session_write_close();

        $name = $this->request->post('name', 'string', false);
        $shard = $this->request->post('shard', 'string', '');

        if (!$name) {
            $this->response->error($this->lang->get('WRONG_REQUEST'));
            return;
        }

        if (!$this->ormService->configExists($name)) {
            $this->response->error($this->lang->get('WRONG_REQUEST'));
            return;
        }

        $builder = $this->ormService->getBuilder($name);
        $config = $this->ormService->config($name);

        $buildShard = false;
        if (strlen($shard) && $config->isDistributed()) {
            $buildShard = true;
            $model = $this->ormService->model($name);
            $connectionName = $model->getConnectionName();
            $builder->setConnection($model->getDbManager()->getDbConnection($connectionName, null, $shard));
        }

        if (!$builder->build(true, $buildShard)) {
            $this->response->error($this->lang->get('CANT_EXEC') . ' ' . implode(',', $builder->getErrors()));
            return;
        }
        $this->response->success();
    }

    /**
     * Get object fields
     */
    public function fieldsAction(): void
    {
        $object = $this->request->post('object', 'string', false);

        if (!$object) {
            $this->response->error($this->lang->get('INVALID_VALUE'));
            return;
        }

        try {
            $objectConfig = $this->ormService->config($object);
        } catch (\InvalidArgumentException $e) {
            $this->response->error($this->lang->get('INVALID_VALUE'));
            return;
        }

        $builder = $this->ormService->getBuilder($object);
        $brokenFields = $builder->getBrokenLinks();

        $fieldsCfg = $objectConfig->getFieldsConfig();

        foreach ($fieldsCfg as $k => &$v) {
            $v['name'] = $k;
            $v['unique'] = $objectConfig->getField($k)->isUnique();

            if (isset($brokenFields[$k])) {
                $v['broken'] = true;
            } else {
                $v['broken'] = false;
            }

            if (isset($v['type']) && !empty($v['type'])) {
                if ($v['type'] === 'link') {
                    $v['type'] .= ' (' . $v['link_config']['object'] . ')';
                    $v['link_type'] = $v['link_config']['link_type'];
                    $v['object'] = $v['link_config']['object'];
                    unset($v['link_config']);
                }
                continue;
            }

            $v['type'] = $v['db_type'];

            if (in_array($v['db_type'], Orm\Record\BuilderFactory::$charTypes, true)) {
                $v['type'] .= ' (' . $v['db_len'] . ')';
            } elseif (in_array($v['db_type'], Orm\Record\BuilderFactory::$floatTypes, true)) {
                $v['type'] .= ' (' . $v['db_scale'] . ',' . $v['db_precision'] . ')';
            }
        }
        unset($v);
        $this->response->json(array_values($fieldsCfg));
    }

    /**
     * Get object indexes
     */
    public function indexesAction(): void
    {
        $object = $this->request->post('object', 'string', false);

        if (!$object) {
            $this->response->error($this->lang->get('INVALID_VALUE'));
            return;
        }

        try {
            $objectConfig = $this->ormService->config($object);
        } catch (\InvalidArgumentException $e) {
            $this->response->error($this->lang->get('INVALID_VALUE'));
            return;
        }

        $indexCfg = $objectConfig->getIndexesConfig();

        foreach ($indexCfg as $k => &$v) {
            $v['columns'] = implode(', ', $v['columns']);
            $v['name'] = $k;
        }
        unset($v);

        $this->response->json(array_values($indexCfg));
    }

    /**
     * Remove Db_Object from system
     */
    public function removeAction(): void
    {
        if (!$this->checkCanDelete()) {
            return;
        }

        $objectName = $this->request->post('objectName', 'string', false);
        $deleteTable = $this->request->post('delete_table', \Dvelum\Filter::FILTER_BOOLEAN, false);

        if (!$objectName) {
            $this->response->error($this->lang->get('WRONG_REQUEST'));
            return;
        }

        try {
            $oConfig = $this->ormService->config($objectName);
            if ($deleteTable && ($oConfig->isLocked() || $oConfig->isReadOnly())) {
                $this->response->error($this->lang->get('DB_CANT_DELETE_LOCKED_TABLE'));
            }
        } catch (\Exception $e) {
            $this->response->error($this->lang->get('WRONG_REQUEST'));
            return;
        }

        $manager = new Manager($this->ormService, $this->container->get(Lang::class), $this->configStorage);

        $result = $manager->removeObject($objectName, $deleteTable);

        switch ($result) {
            case 0:
                $this->response->success();
                break;
            case Manager::ERROR_FS:
                $this->response->error($this->lang->get('CANT_WRITE_FS'));
                break;
            case Manager::ERROR_DB:
                $this->response->error($this->lang->get('CANT_WRITE_DB'));
                break;
            case Manager::ERROR_FS_LOCALISATION:
                $this->response->error(
                    $this->lang->get('CANT_WRITE_FS') . ' (' . $this->lang->get('LOCALIZATION_FILE') . ')'
                );
                break;
            case Manager::ERROR_HAS_LINKS:
                $this->response->error($this->lang->get('MSG_ORM_CAND_DELETE_LINKED'));
                break;
            default:
                $this->response->error($this->lang->get('CANT_EXEC'));
        }
    }

    /**
     * Load Db Object info
     */
    public function loadAction(): void
    {
        $object = $this->request->post('object', 'string', false);

        if ($object === false) {
            $this->response->error($this->lang->get('INVALID_VALUE'));
            return;
        }

        try {
            $config = $this->ormService->config($object);
            $info = $config->__toArray();
            $info['name'] = $object;
            $info['use_acl'] = false;

            if (isset($info['acl']) && $info['acl']) {
                $info['use_acl'] = true;
            }

            unset($info['fields']);
            $this->response->success($info);
        } catch (\Exception $e) {
            $this->response->error($this->lang->get('INVALID_VALUE'));
        }
    }

    /*
     * Create / Update Db object
     */
    public function saveAction(): void
    {
        if (!$this->checkCanEdit()) {
            return;
        }

        $recordId = $this->request->post('record_id', 'string', '0');
        $revControl = $this->request->post('rev_control', 'boolean', false);
        $saveHistory = $this->request->post('save_history', 'boolean', false);
        $linkTitle = $this->request->post('link_title', 'string', '');
        $name = $this->request->post('name', 'string', '');
        $disableKeys = $this->request->post('disable_keys', 'boolean', false);

        $pimaryKey = $this->request->post('primary_key', 'string', 'id');

        $connection = $this->request->post('connection', 'string', '');

        $readonly = $this->request->post('readonly', 'boolean', false);
        $locked = $this->request->post('locked', 'boolean', false);

        $usePrefix = $this->request->post('use_db_prefix', 'boolean', false);


        $detalization = $this->request->post('log_detalization', 'string', 'default');

        $distributed = $this->request->post('distributed', 'boolean', false);

        $shardingType = $this->request->post('sharding_type', 'string', null);
        $shardingKey = $this->request->post('sharding_key', 'string', null);


        if ($detalization !== 'extended') {
            $detalization = 'default';
        }

        $dataObject = $this->request->post('parent_object', 'string', '');
        $parentObject = $this->request->post('data_object', 'string', '');

        $reqStrings = ['name', 'title', 'table', 'engine', 'connection'];
        $errors = [];
        $data = [];


        foreach ($reqStrings as $v) {
            $value = $this->request->post($v, 'string', '');

            if (!strlen((string)$value)) {
                $errors[] = array('id' => $v, 'msg' => $this->lang->get('CANT_BE_EMPTY'));
            }

            if ($v !== 'name') {
                $data[$v] = $value;
            }
        }

        if (!empty($errors)) {
            $this->response->error($this->lang->get('FILL_FORM'), $errors);
        }

        if (!$distributed) {
            $shardingType = $shardingKey = null;
        }

        $data['data_object'] = $dataObject;
        $data['parent_object'] = $parentObject;
        $data['rev_control'] = $revControl;
        $data['save_history'] = $saveHistory;
        $data['link_title'] = $linkTitle;
        $data['disable_keys'] = $disableKeys;
        $data['readonly'] = $readonly;
        $data['locked'] = $locked;
        $data['primary_key'] = $pimaryKey;
        $data['use_db_prefix'] = $usePrefix;
        $data['connection'] = $connection;
        $data['log_detalization'] = $detalization;
        $data['distributed'] = $distributed;
        $data['sharding_type'] = $shardingType;
        $data['sharding_key'] = $shardingKey;

        $this->checkExternalProperties($data, $errors);
        if (!empty($errors)) {
            $this->response->error($this->lang->get('FILL_FORM'), $errors);
        }

        $name = strtolower($name);

        if ($recordId === '') {
            $this->createObject($name, $data);
        }
        $this->updateObject($recordId, $name, $data);
    }

    /**
     * Check properties from external modules (plugins)
     * @param array<string,mixed> $data
     * @param array<mixed> $errors
     */
    protected function checkExternalProperties(array &$data, array &$errors): void
    {
        $properties = $this->configStorage->get('orm/properties.php')->__toArray();
        if (empty($properties)) {
            return;
        }

        foreach ($properties as $name => $item) {
            if (!empty($item['validator'])) {
                $validationClass = $item['validator'];
                /**
                 * @var Orm\Property\ValidatorInterface $validationObject
                 */
                $validationObject = new $validationClass($this->request, $this->lang);
                if ($validationObject->isValid()) {
                    $data[$name] = $validationObject->getValue();
                } else {
                    $errors[$name] = $validationObject->getError();
                }
            }
        }
    }

    /**
     * Create Db_Object
     * @param string $name - object name
     * @param array<string,mixed> $data - object config
     */
    protected function createObject(string $name, array $data): void
    {
        $usePrefix = $data['use_db_prefix'];
        $connectionManager = new \Dvelum\Db\Manager($this->container->get('config.main'));
        $connection = $connectionManager->getDbConnection($data['connection']);
        $connectionCfg = $connectionManager->getDbConfig($data['connection']);

        //$db = Model::getGlobalDbConnection();
        $db = $connection;
        $tables = $db->listTables();

        /**
         * @var \Dvelum\Orm\Orm
         */
        $ormService = $this->ormService;

        $oConfigPath = $ormService->getConfigSettings()->get('configPath');
        $configDir = $this->configStorage->getWrite() . $oConfigPath;

        $tableName = $data['table'];

        if ($usePrefix) {
            $tableName = $connectionCfg['prefix'] . $tableName;
        }

        if (in_array($tableName, $tables, true)) {
            $this->response->error(
                $this->lang->get('FILL_FORM'),
                array(array('id' => 'table', 'msg' => $this->lang->get('SB_UNIQUE')))
            );
            return;
        }

        if (file_exists($configDir . strtolower($name) . '.php')) {
            $this->response->error(
                $this->lang->get('FILL_FORM'),
                array(array('id' => 'name', 'msg' => $this->lang->get('SB_UNIQUE')))
            );
        }

        if (!is_dir($configDir) && !@mkdir($configDir, 0655, true)) {
            $this->response->error($this->lang->get('CANT_WRITE_FS') . ' ' . $configDir);
            return;
        }

        /*
         * Write object config
         */
        $newConfig = Config\Factory::create([], $configDir . $name . '.php');

        if (!$this->configStorage->save($newConfig)) {
            $this->response->error($this->lang->get('CANT_WRITE_FS') . ' ' . $configDir . $name . '.php');
        }

        $cfg = $this->configStorage->get($oConfigPath . strtolower($name) . '.php', false, false);
        /*
         * Add fields config
         */
        $data['fields'] = [];

        $cfg->setData($data);
        $this->configStorage->save($cfg);

        try {
            $cfg = $this->ormService->config($name);
            $cfg->setObjectTitle($data['title']);

            if (!$cfg->save()) {
                $this->response->error($this->lang->get('CANT_WRITE_FS'));
                return;
            }

            /*
             * Build database
            */
            $builder = $this->ormService->getBuilder($name);
            $builder->build();
        } catch (\Exception $e) {
            $this->response->error($this->lang->get('CANT_EXEC') . 'code 2');
            return;
        }
        $this->response->success();
    }

    /**
     * @param string $objectName
     * @param string $name
     * @param array<string,mixed> $data
     * @throws Orm\Exception
     */
    protected function updateObject(string $objectName, string $name, array $data): void
    {
        $ormConfig = $this->configStorage->get('orm.php');
        $dataDir = $this->configStorage->getWrite() . $ormConfig->get('object_configs');
        $objectConfigPath = $dataDir . $objectName . '.php';

        if (!is_writable($dataDir)) {
            $this->response->error($this->lang->get('CANT_WRITE_FS') . ' ' . $dataDir);
            return;
        }

        if (file_exists($objectConfigPath) && !is_writable($objectConfigPath)) {
            $this->response->error($this->lang->get('CANT_WRITE_FS') . ' ' . $objectConfigPath);
            return;
        }

        /*
         * Rename object
        */
        if ($objectName !== $name) {
            $this->renameObject($objectName, $name);
        }

        try {
            $config = $this->ormService->config($name);
        } catch (\Exception $e) {
            $this->response->error($this->lang->get('INVALID_VALUE'));
            return;
        }

        /**
         * @var Orm\Record\Builder\MySQL $builder
         */
        $builder = $this->ormService->getBuilder($name);

        /*
         * Rename Db Table
         */
        if ($config->get('table') !== $data['table']) {
            if ($builder->tableExists($data['table'], true)) {
                $this->response->error(
                    $this->lang->get('FILL_FORM'),
                    array(array('id' => 'table', 'msg' => $this->lang->get('SB_UNIQUE')))
                );
                return;
            }

            if (!$builder->renameTable($data['table'])) {
                $this->response->error($this->lang->get('CANT_RENAME_TABLE'));
                return;
            }
        }

        /*
         * Check and apply changes for DB Table engine
         */
        if ($config->get('engine') !== $data['engine']) {
            $err = $builder->checkEngineCompatibility($data['engine']);

            if ($err !== true) {
                $this->response->error($this->lang->get('CANT_EXEC') . ' ', $err);
            }

            if (!$builder->changeTableEngine($data['engine'])) {
                $errors = $builder->getErrors();
                $errorsString = '';
                if (!empty($errors)) {
                    $errorsString = implode(' <br>', $errors);
                }
                $this->response->error($this->lang->get('CANT_EXEC') . ' ' . $errorsString);
            }
        }

        $data['fields'] = $config->getFieldsConfig(false);

        $config->setData($data);
        $config->setObjectTitle($data['title']);

        if (!$config->save()) {
            $this->response->error($this->lang->get('CANT_WRITE_FS'));
            return;
        }

        $this->response->success();
    }

    protected function renameObject(string $oldName, string $newName): void
    {
        $ormConfig = $this->configStorage->get('orm.php');

        $newFileName = $ormConfig->get('object_configs') . $newName . '.php';
        //$oldFileName = $this->appConfig->get('object_configs').$oldName.'.php';

        if (file_exists($newFileName)) {
            $this->response->error(
                $this->lang->get('FILL_FORM'),
                array(array('id' => 'name', 'msg' => $this->lang->get('SB_UNIQUE')))
            );
            return;
        }

        $manager = new Manager($this->ormService, $this->container->get(Lang::class), $this->configStorage);
        $renameResult = $manager->renameObject($ormConfig->get('object_configs'), $oldName, $newName);

        switch ($renameResult) {
            case 0:
                break;
            case Manager::ERROR_FS:
                $this->response->error($this->lang->get('CANT_WRITE_FS'));
                break;
            case Manager::ERROR_FS_LOCALISATION:
                $this->response->error(
                    $this->lang->get('CANT_WRITE_FS') . ' (' . $this->lang->get('LOCALIZATION_FILE') . ')'
                );
                break;
            default:
                $this->response->error($this->lang->get('CANT_EXEC') . ' code 5');
        }
        /*
         * Clear cache
         * @todo refactor
         */
        //Config::resetCache();
    }
}
