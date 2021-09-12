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
use Exception;
use Dvelum\Orm\Record;

class Distributed extends Controller
{
    public function indexAction(): void
    {
    }

    /**
     * Add distributed index
     */
    public function addDistributedIndexAction(): void
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

        $manager = new Manager($this->ormService, $this->container->get(Lang::class), $this->configStorage);

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
    public function distIndexesAction(): void
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
    public function deleteDistributedIndexAction(): void
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

        $manager = new Manager($this->ormService, $this->container->get(Lang::class), $this->configStorage);
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
    public function listShardingTypesAction(): void
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
    public function listShardingFieldsAction(): void
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
    public function acceptedDistributedFieldsAction(): void
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
