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

use Dvelum\App\Orm\Api\Controller;
use Dvelum\App\Orm\Api\Manager;
use Dvelum\Lang;
use Dvelum\Orm;

class Index extends Controller
{
    public function indexAction(): void
    {
    }

    /**
     * Save Object indexes
     * @todo validate index columns, check if they exists in config
     */
    public function saveAction(): void
    {
        if (!$this->checkCanEdit()) {
            return;
        }

        $object = $this->request->post('object', 'string', false);
        $index = $this->request->post('index', 'string', false);
        $columns = $this->request->post('columns', 'array', array());
        $name = $this->request->post('name', 'string', false);
        $unique = $this->request->post('unique', 'boolean', false);
        $fulltext = $this->request->post('fulltext', 'boolean', false);

        if (!$object) {
            $this->response->error($this->lang->get('WRONG_REQUEST') . ' code 1');
            return;
        }

        if (!$name) {
            $this->response->error(
                $this->lang->get('FILL_FORM'),
                [['id' => 'name', 'msg' => $this->lang->get('CANT_BE_EMPTY')]]
            );
            return;
        }

        try {
            $objectCfg = $this->ormService->config($object);
        } catch (\Exception $e) {
            $this->response->error($this->lang->get('WRONG_REQUEST') . ' code 2');
            return;
        }

        $indexData = array(
            'columns' => $columns,
            'unique' => $unique,
            'fulltext' => $fulltext,
            'PRIMARY' => false
        );

        $indexes = $objectCfg->getIndexesConfig();

        if ($index !== $name && array_key_exists((string)$name, $indexes)) {
            $this->response->error(
                $this->lang->get('FILL_FORM'),
                [['id' => 'name', 'msg' => $this->lang->get('SB_UNIQUE')]]
            );
            return;
        }

        $indexManager = new Orm\Record\Config\IndexManager();
        if ($index != $name) {
            $indexManager->removeIndex($objectCfg, $index);
        }

        $indexManager->setIndexConfig($objectCfg, $name, $indexData);

        if ($objectCfg->save()) {
            $this->response->success();
        } else {
            $this->response->error($this->lang->get('CANT_WRITE_FS'));
        }
    }

    /**
     * Delete object index
     */
    public function deleteAction(): void
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
        } catch (\Exception $e) {
            $this->response->error($this->lang->get('WRONG_REQUEST') . ' code 2');
            return;
        }

        $indexManager = new Orm\Record\Config\IndexManager();
        $indexManager->removeIndex($objectCfg, $index);

        if ($objectCfg->save()) {
            $this->response->success();
        } else {
            $this->response->error($this->lang->get('CANT_WRITE_FS'));
        }
    }

    /**
     * Load index config action
     */
    public function loadAction(): void
    {
        $object = $this->request->post('object', 'string', false);
        $index = $this->request->post('index', 'string', false);

        if (!$object || !$index) {
            $this->response->error($this->lang->get('INVALID_VALUE'));
            return;
        }

        $manager = new Manager($this->ormService, $this->container->get(Lang::class), $this->configStorage);
        $indexConfig = $manager->getIndexConfig($object, $index);

        if ($indexConfig === null) {
            $this->response->error($this->lang->get('INVALID_VALUE'));
            return;
        }

        $this->response->success($indexConfig);
    }
}
