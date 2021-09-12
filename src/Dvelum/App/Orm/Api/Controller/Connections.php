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

use Dvelum\App\Backend\Orm;
use Dvelum\App\Orm\Api\Controller;
use Dvelum\Config;
use Dvelum\Db\Adapter;
use Dvelum\Request;
use Dvelum\Response\ResponseInterface;
use Dvelum\Orm\Model;
use Dvelum\Orm\Record\Manager;
use Dvelum\Orm\Record\Import;
use Psr\Container\ContainerInterface;

class Connections extends Controller
{
    /**
     * @var \Dvelum\App\Orm\Api\Connections $connections
     */
    protected \Dvelum\App\Orm\Api\Connections $connections;

    public function __construct(
        Request $request,
        ResponseInterface $response,
        ContainerInterface $container,
        bool $canEdit = true,
        bool $canDelete = true
    ) {
        parent::__construct($request, $response, $container, $canEdit, $canDelete);
        $this->connections = new \Dvelum\App\Orm\Api\Connections(
            $container->get('config.main')->get('db_configs'),
            $this->configStorage,
            $this->lang
        );
    }

    public function indexAction(): void
    {
        $this->response->notFound();
    }

    public function listAction(): void
    {
        $devType = $this->request->post('devType', 'int', false);

        if ($devType === false || !$this->connections->typeExists($devType)) {
            $this->response->error($this->lang->get('WRONG_REQUEST') . ' undefined devType');
            return;
        }

        $connections = $this->connections->getConnections($devType);
        $data = [];
        if (!empty($connections)) {
            foreach ($connections as $name => $cfg) {
                if ($name === 'default') {
                    $system = true;
                } else {
                    $system = false;
                }

                $data[] = array(
                    'id' => $name,
                    'system' => $system,
                    'devType' => $devType,
                    'username' => $cfg->get('username'),
                    'dbname' => $cfg->get('dbname'),
                    'host' => $cfg->get('host'),
                    'adapter' => $cfg->get('adapter'),
                    'isolation' => $cfg->get('transactionIsolationLevel')
                );
            }
        }
        $this->response->success($data);
    }

    public function removeAction(): void
    {
        if (!$this->checkCanEdit()) {
            return;
        }

        $id = $this->request->post('id', 'string', false);

        if ($id === false) {
            $this->response->error($this->lang->get('WRONG_REQUEST') . ' undefined id');
            return;
        }

        try {
            $this->connections->removeConnection($id);
        } catch (\Exception $e) {
            $this->response->error($e->getMessage());
            return;
        }

        $this->response->success();
    }

    public function loadAction(): void
    {
        $id = $this->request->post('id', 'string', false);
        $devType = $this->request->post('devType', 'int', false);

        if ($id === false) {
            $this->response->error($this->lang->get('WRONG_REQUEST') . ' undefined id');
            return;
        }


        if ($devType === false || !$this->connections->typeExists($devType)) {
            $this->response->error($this->lang->get('WRONG_REQUEST') . ' undefined devType');
            return;
        }

        $data = $this->connections->getConnection($devType, $id);

        if (!$data) {
            $this->response->error($this->lang->get('CANT_LOAD'));
            return;
        }


        $data = $data->__toArray();
        $data['id'] = $id;
        unset($data['password']);
        $this->response->success($data);
    }

    public function saveAction(): void
    {
        if (!$this->checkCanEdit()) {
            return;
        }

        $oldId = $this->request->post('oldid', 'string', false);
        $id = $this->request->post('id', 'string', false);
        $devType = $this->request->post('devType', 'int', false);
        $host = $this->request->post('host', 'string', false);
        $user = $this->request->post('username', 'string', false);
        $base = $this->request->post('dbname', 'string', false);
        $charset = $this->request->post('charset', 'string', false);
        $pass = $this->request->post('password', 'string', false);

        $setpass = $this->request->post('setpass', 'boolean', false);
        $adapter = $this->request->post('adapter', 'string', false);
        $transactionIsolationLevel = $this->request->post('transactionIsolationLevel', 'string', false);
        $port = $this->request->post('port', 'int', false);
        $prefix = $this->request->post('prefix', 'string', '');

        if ($devType === false) {
            $this->response->error($this->lang->get('WRONG_REQUEST'));
            return;
        }


        /*
         * INPUT FIX
         */
        if ($oldId === 'false') {
            $oldId = false;
        }

        if ($oldId === false || empty($oldId)) {
            $cfg = $this->connections->getConfig();
            foreach ($cfg as $type => $data) {
                if ($this->connections->connectionExists((int)$type, $id)) {
                    $this->response->error($this->lang->get('FILL_FORM'), ['id' => $this->lang->get('SB_UNIQUE')]);
                    return;
                }
            }

            if (!$this->connections->createConnection($id)) {
                $this->response->error($this->lang->get('CANT_CREATE'));
                return;
            }

            $con = $this->connections->getConnection($devType, $id);
        } else {
            if ($oldId !== $id) {
                $cfg = $this->connections->getConfig();
                foreach ($cfg as $type => $data) {
                    if ($this->connections->connectionExists((int)$type, $id)) {
                        $this->response->error(
                            $this->lang->get('FILL_FORM'),
                            ['id' => $this->lang->get('SB_UNIQUE')]
                        );
                        return;
                    }
                }
            }

            if (!$this->connections->connectionExists($devType, $id) && $oldId === $id) {
                $this->response->error($this->lang->get('WRONG_REQUEST'));
                return;
            }
            $con = $this->connections->getConnection($devType, (string)$oldId);
        }

        if (!$con) {
            $this->response->error($this->lang->get('CANT_CREATE'));
            return;
        }

        if ($setpass) {
            $con->set('password', $pass);
        }

        if ($port !== false && $port !== 0) {
            $con->set('port', $port);
        } else {
            $con->remove('port');
        }

        $storage = Config::storage();

        // Disable config merging
        $con->setParentId(null);

        $con->set('username', $user);
        $con->set('dbname', $base);
        $con->set('host', $host);
        $con->set('charset', $charset);
        $con->set('adapter', $adapter);
        $con->set('driver', $adapter);
        $con->set('transactionIsolationLevel', $transactionIsolationLevel);
        $con->set('prefix', $prefix);

        if (!$storage->save($con)) {
            $this->response->error($this->lang->get('CANT_WRITE_FS') . ' ' . $con->getName());
            return;
        }

        if ($oldId !== false && $oldId !== $id) {
            if (!$this->connections->renameConnection($oldId, $id)) {
                $this->response->error($this->lang->get('CANT_WRITE_FS'));
                return;
            }
        }
        $this->response->success();
    }

    public function testAction(): void
    {
        $id = $this->request->post('id', 'string', false);
        $devType = $this->request->post('devType', 'int', false);
        $port = $this->request->post('port', 'int', false);
        $host = $this->request->post('host', 'string', false);
        $user = $this->request->post('username', 'string', false);
        $base = $this->request->post('dbname', 'string', false);
        $charset = $this->request->post('charset', 'string', false);
        $pass = $this->request->post('password', 'string', false);
        $updatePwd = $this->request->post('setpass', 'boolean', false);
        $adapter = $this->request->post('adapter', 'string', false);
        $adapterNamespace = $this->request->post('adapterNamespace', 'string', false);

        if ($devType === false) {
            $this->response->error($this->lang->get('WRONG_REQUEST'));
            return;
        }


        $config = array(
            'username' => $user,
            'password' => $pass,
            'dbname' => $base,
            'host' => $host,
            'charset' => $charset,
            'adapter' => $adapter,
            'adapterNamespace' => $adapterNamespace
        );

        if ($port !== false) {
            $config['port'] = $port;
        }

        if ($id !== false && $id !== 'false' && !$updatePwd) {
            $oldCfg = $this->connections->getConnection($devType, $id);

            if (!$oldCfg) {
                $this->response->error($this->lang->get('WRONG_REQUEST') . ' invalid file');
                return;
            }
            $config['password'] = $oldCfg->get('password');
        }

        try {
            $config['driver'] = $config['adapter'];
            $db = new Adapter($config);
            $db->query('SET NAMES ' . $charset);
            $this->response->success();
        } catch (\Exception $e) {
            $this->response->error($this->lang->get('CANT_CONNECT') . ' ' . $e->getMessage());
        }
    }

    public function tableListAction(): void
    {
        $connectionId = $this->request->post('connId', 'string', false);
        $connectionType = $this->request->post('type', 'integer', false);

        if ($connectionId === false || $connectionType === false) {
            $this->response->error($this->lang->get('WRONG_REQUEST'));
            return;
        }

        $cfg = $this->connections->getConnection($connectionType, $connectionId);
        if (!$cfg) {
            $this->response->error($this->lang->get('WRONG_REQUEST'));
            return;
        }

        /**
         * @var array<string,mixed>
         */
        $cfg = $cfg->__toArray();

        $conManager = new \Dvelum\Db\Manager($this->configStorage->get('config.main'));
        try {
            $connection = $conManager->initConnection($cfg);
        } catch (\Exception $e) {
            $this->response->error($this->lang->get('CANT_CONNECT') . ' ' . $e->getMessage());
            return;
        }

        $meta = $connection->getMeta();
        $tables = $meta->getTableNames();

        $data = [];

        foreach ($tables as $v) {
            $data[] = ['id' => $v, 'title' => $v];
        }

        $this->response->success($data);
    }

    public function fieldsListAction(): void
    {
        $connectionId = $this->request->post('connId', 'string', false);
        $connectionType = $this->request->post('type', 'integer', false);
        $table = $this->request->post('table', 'string', false);

        if ($connectionId === false || $connectionType === false || $table === false) {
            $this->response->error($this->lang->get('WRONG_REQUEST'));
            return;
        }

        $cfg = $this->connections->getConnection($connectionType, $connectionId);

        if (!$cfg) {
            $this->response->error($this->lang->get('WRONG_REQUEST'));
            return;
        }

        $conManager = new \Dvelum\Db\Manager($this->configStorage->get('config.main'));
        /**
         * @var array<string,mixed>
         */
        $cfgArray = $cfg->__toArray();
        try {
            $connection = $conManager->initConnection($cfgArray);
        } catch (\Exception $e) {
            $this->response->error($this->lang->get('CANT_CONNECT') . ' ' . $e->getMessage());
            return;
        }

        $data = [];

        $meta = $connection->getMeta();
        $columns = $meta->getColumns($table);

        foreach ($columns as $v => $k) {
            $data[] = ['name' => $v, 'type' => $k->getDataType()];
        }

        $this->response->success($data);
    }

    public function externalTablesAction(): void
    {
        $connectionId = $this->request->post('connId', 'string', false);
        $connectionType = $this->request->post('type', 'integer', false);

        if ($connectionId === false || $connectionType === false) {
            $this->response->error($this->lang->get('WRONG_REQUEST'));
            return;
        }


        $cfg = $this->connections->getConnection($connectionType, $connectionId);

        if (!$cfg) {
            $this->response->error($this->lang->get('WRONG_REQUEST'));
            return;
        }

        /**
         * @var array<string,mixed>
         */
        $cfg = $cfg->__toArray();
        try {
            $cfg['driver'] = $cfg['adapter'];
            $db = new Adapter($cfg);
            $db->query('SET NAMES ' . $cfg['charset']);
            $tables = $db->listTables();
        } catch (\Exception $e) {
            $this->response->error($this->lang->get('CANT_CONNECT') . ' ' . $e->getMessage());
            return;
        }

        $data = [];

        $manager = $this->ormService->getRecordManager();
        $objects = $manager->getRegisteredObjects();

        $tablesObjects = [];

        $orm = $this->container->get(\Dvelum\Orm\Orm::class);
        if (!empty($objects)) {
            foreach ($objects as $object) {
                $model = $orm->model($object);
                $tablesObjects[$model->table()][] = $object;
            }
        }

        if (!empty($tables)) {
            foreach ($tables as $table) {
                $same = false;

                if (isset($tablesObjects[$table]) && !empty($tablesObjects[$table])) {
                    foreach ($tablesObjects[$table] as $oName) {
                        $mCfg = $orm->model($oName)->getDbConnection()->getConfig();
                        if ($mCfg['host'] === $cfg['host'] && $mCfg['dbname'] === $cfg['dbname']) {
                            $same = true;
                            break;
                        }
                    }
                }
                if (!$same) {
                    $data[] = array('name' => $table);
                }
            }
        }
        $this->response->success($data);
    }

    public function connectObjectAction(): void
    {
        if (!$this->checkCanEdit()) {
            return;
        }

        $connectionId = $this->request->post('connId', 'string', false);
        $connectionType = $this->request->post('type', 'integer', false);
        $table = $this->request->post('table', 'string', false);

        $errors = null;

        if ($connectionId === false || $connectionType === false || $table === false) {
            $this->response->error($this->lang->get('WRONG_REQUEST'));
            return;
        }

        $cfg = $this->connections->getConnection($connectionType, $connectionId);

        if (!$cfg) {
            $this->response->error($this->lang->get('WRONG_REQUEST'));
            return;
        }
        /**
         * @var array<string,mixed>
         */
        $cfg = $cfg->__toArray();
        try {
            $cfg['driver'] = $cfg['adapter'];
            $db = new Adapter($cfg);
            $db->query('SET NAMES ' . $cfg['charset']);
        } catch (\Exception $e) {
            $this->response->error($this->lang->get('CANT_CONNECT') . ' ' . $e->getMessage());
            return;
        }

        $import = new Import();

        if (!$import->isValidPrimaryKey($db, $table)) {
            $errors = $import->getErrors();

            if (!empty($errors)) {
                $errors = '<br>' . implode('<br>', $errors);
            } else {
                $errors = '';
            }

            $this->response->error(
                $this->lang->get('DB_CANT_CONNECT_TABLE') . ' ' . $this->lang->get(
                    'DB_MSG_UNIQUE_PRIMARY'
                ) . ' ' . $errors
            );
            return;
        }

        $manager = $this->ormService->getRecordManager();
        $newObjectName = strtolower(str_replace('_', '', $table));

        if ($manager->objectExists($newObjectName)) {
            $newObjectName = strtolower(str_replace('_', '', $cfg['dbname'])) . $newObjectName;
            if ($manager->objectExists($newObjectName)) {
                $k = 0;
                $alphabet = \Dvelum\Utils\Strings::alphabetEn();

                while ($manager->objectExists($newObjectName)) {
                    if (!isset($alphabet[$k])) {
                        $this->response->error('Can not create unique object name' . $errors);
                        return;
                    }

                    $newObjectName .= $alphabet[$k];
                    $k++;
                }
            }
        }

        $config = $import->createConfigByTable($db, $table, $cfg['prefix']);
        if (empty($config)) {
            $errors = $import->getErrors();
            if (!empty($errors)) {
                $errors = '<br>' . implode('<br>', $errors);
            } else {
                $errors = '';
            }

            $this->response->error($this->lang->get('DB_CANT_CONNECT_TABLE') . ' ' . $errors);
            return;
        } else {
            $config['connection'] = $connectionId;
            $ormConfig = $this->configStorage->get('orm.php');
            $path = $ormConfig->get('object_configs') . $newObjectName . '.php';

            if (!$this->configStorage->create($path)) {
                $this->response->error($this->lang->get('CANT_WRITE_FS') . ' ' . $path);
                return;
            }

            $cfg = $this->configStorage->get($path, true, true);
            $cfg->setData($config);
            if (!$this->configStorage->save($cfg)) {
                $this->response->error($this->lang->get('CANT_WRITE_FS') . ' ' . $path);
                return;
            }
        }
        $this->response->success();
    }
}
