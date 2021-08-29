<?php

/**
 *  DVelum project https://github.com/dvelum/dvelum , https://github.com/k-samuel/dvelum , http://dvelum.net
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
 *
 */
declare(strict_types=1);

namespace Dvelum\App\Orm\Api\Controller;

use Dvelum\App\Orm\Api\Manager;
use Dvelum\App\Orm\Api\Controller;
use Dvelum\Config;
use Dvelum\Lang;
use Dvelum\Orm;
use Exception;
use Dvelum\Orm\Record;

class Distributed extends Controller
{
    public function indexAction()
    {
    }

    /**
     * Add distributed index
     */
    public function addDistributedIndexAction()
    {
        $object = $this->request->post('object', 'string', false);
        $field = $this->request->post('field', 'string', false);

        if (!$object) {
            $this->response->error($this->lang->get('INVALID_VALUE'));
            return;
        }


        try {
            $objectConfig = $this->ormService->config($object);
        } catch (Exception $e) {
            $this->response->error($this->lang->get('INVALID_VALUE'));
            return;
        }

        if (!$objectConfig->fieldExists($field)) {
            $this->response->error($this->lang->get('INVALID_VALUE'));
            return;
        }

        $indexManager = new Record\Config\IndexManager();
        $indexManager->setDistributedIndexConfig($objectConfig, $field, ['field' => $field, 'is_system' => false]);

        $manager = new Manager($this->ormService);

        if ($objectConfig->save()) {
            try {
                if (!$manager->syncDistributedIndex($object)) {
                    $this->response->error($this->lang->get('CANT_WRITE_FS'));
                    return;
                }
                $this->response->success();
            } catch (Exception $e) {
                $this->response->error($e->getMessage());
            }
        } else {
            $this->response->error($this->lang->get('CANT_WRITE_FS'));
        }
    }

    /**
     * Get distributed indexes
     */
    public function distIndexesAction()
    {
        $object = $this->request->post('object', 'string', false);

        if (!$object) {
            $this->response->error($this->lang->get('INVALID_VALUE'));
        }

        try {
            $objectConfig = $this->ormService->config($object);
        } catch (Exception $e) {
            $this->response->error($this->lang->get('INVALID_VALUE'));
            return;
        }

        $list = [];
        $indexCfg = $objectConfig->getDistributedIndexesConfig();

        if (!empty($indexCfg)) {
            foreach ($indexCfg as $v) {
                $list[] = ['field' => $v['field'], 'is_system' => $v['is_system']];
            }
        }
        $this->response->json(array_values($list));
    }

    /**
     * Delete distributed index
     */
    public function deleteDistributedIndexAction()
    {
        if (!$this->checkCanDelete()) {
            return;
        }

        $object = $this->request->post('object', 'string', false);
        $index = $this->request->post('name', 'string', false);

        if (!$object || !$index) {
            $this->response->error($this->lang->get('WRONG_REQUEST'));
            return;
        }

        try {
            $objectCfg = $this->ormService->config($object);
        } catch (Exception $e) {
            $this->response->error($this->lang->get('WRONG_REQUEST ' . ' code 2'));
            return;
        }

        $indexManager = new Record\Config\IndexManager();
        $indexManager->removeDistributedIndex($objectCfg, $index);

        $manager = new Manager($this->ormService);
        if ($objectCfg->save() && $manager->syncDistributedIndex($object)) {
            $this->response->success();
        } else {
            $this->response->error($this->lang->get('CANT_WRITE_FS'));
        }
    }

    /**
     * Sharding types for combobox
     * @throws Exception
     */
    public function listShardingTypesAction()
    {
        $config = $this->configStorage->get('sharding.php')->get('sharding_types');
        $data = [];
        foreach ($config as $index => $item) {
            $data[] = [
                'id' => $index,
                'title' => $this->lang->get($item['title'])
            ];
        }
        $this->response->success($data);
    }

    /**
     * Sharding types for combobox
     * @throws Exception
     */
    public function listShardingFieldsAction()
    {
        $object = $this->request->post('object', 'string', '');

        if (empty($object)) {
            $this->response->success([]);
            return;
        }

        if (!$this->ormService->configExists($object)) {
            $this->response->success([]);
            return;
        }

        $config = $this->ormService->config($object);
        $fields = $config->getFields();

        $data = [];

        foreach ($fields as $item) {
            /**
             * @var Orm\Record\Config\Field $item
             */
            if ($item->isSystem() || $item->isBoolean() || $item->isText()) {
                continue;
            }
            $data[] = [
                'id' => $item->getName(),
                'title' => $item->getName() . ' (' . $item->getTitle() . ')'
            ];
        }

        $pk = $config->getPrimaryKey();
        $data[] = [
            'id' => $pk,
            'title' => $pk . ' (' . $config->getField($pk)->getTitle() . ')'
        ];

        $this->response->success($data);
    }

    /**
     * Get list of fields that can be added as distributed index
     */
    public function acceptedDistributedFieldsAction()
    {
        $object = $this->request->post('object', 'string', false);

        if (!$object) {
            $this->response->error($this->lang->get('INVALID_VALUE'));
            return;
        }

        try {
            $objectConfig = $this->ormService->config($object);
        } catch (Exception $e) {
            $this->response->error($this->lang->get('INVALID_VALUE'));
            return;
        }

        $indexCfg = $objectConfig->getDistributedIndexesConfig();
        $fields = $objectConfig->getFieldsConfig();

        $data = [];
        foreach ($fields as $name => $config) {
            if (isset($indexCfg[$name])) {
                continue;
            }
            $dbType = $config['db_type'];
            if (
                in_array($dbType, Record\BuilderFactory::$charTypes, true)
                ||
                in_array($dbType, Record\BuilderFactory::$numTypes, true)
                ||
                in_array($dbType, Record\BuilderFactory::$dateTypes, true)
            ) {
                $data[] = ['name' => $name];
            }
        }
        $this->response->success($data);
    }
}
