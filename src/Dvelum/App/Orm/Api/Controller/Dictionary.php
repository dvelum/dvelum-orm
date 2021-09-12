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

use Dvelum\App\Dictionary\Service;
use Dvelum\App\Orm\Api\Controller;
use Dvelum\App\Dictionary\Manager;

class Dictionary extends Controller
{
    public function indexAction(): void
    {
    }

    /**
     * Create new dictionary or rename existed
     */
    public function updateAction(): void
    {
        if (!$this->checkCanEdit()) {
            return;
        }

        $id = $this->request->post('id', 'string', false);
        $name = strtolower($this->request->post('name', 'string', false));

        $manager = $this->container->get(Manager::class);
        if (!$name) {
            $this->response->error($this->lang->get('WRONG_REQUEST'));
        }

        if (!$id) {
            if (!$manager->create($name)) {
                $this->response->error(
                    $this->lang->get('CANT_WRITE_FS') . ' ' . $this->lang->get('OR') . ' ' . $this->lang->get(
                        'DICTIONARY_EXISTS'
                    )
                );
            }
        } else {
            if (!$manager->rename($id, $name)) {
                $this->response->error($this->lang->get('CANT_WRITE_FS'));
            }
        }

        $this->response->success();
    }

    /**
     * Remove dictionary
     */
    public function removeAction(): void
    {
        $manager = $this->container->get(Manager::class);

        if (!$this->checkCanDelete()) {
            return;
        }

        $name = strtolower($this->request->post('name', 'string', false));
        if (empty($name)) {
            $this->response->error($this->lang->get('WRONG_REQUEST'));
            return;
        }

        if (!$manager->remove($name)) {
            $this->response->error($this->lang->get('CANT_WRITE_FS'));
        } else {
            $this->response->success();
        }
    }

    /**
     * Get dictionary list
     */
    public function listAction(): void
    {
        $manager = $this->container->get(Manager::class);
        $data = [];
        $list = $manager->getList();

        if (!empty($list)) {
            foreach ($list as $v) {
                $data[] = ['id' => $v, 'title' => $v];
            }
        }

        $this->response->success($data);
    }

    /**
     * Get dictionary records list
     */
    public function recordsAction(): void
    {
        $name = strtolower($this->request->post('dictionary', 'string', false));
        if (empty($name)) {
            $this->response->error($this->lang->get('WRONG_REQUEST'));
            return;
        }

        $list = $this->container->get(Service::class)->get($name)->getData();

        $data = [];

        if (!empty($list)) {
            foreach ($list as $k => $v) {
                $data[] = ['id' => $k, 'key' => $k, 'value' => $v];
            }
        }

        $this->response->success($data);
    }

    /**
     * Update dictionary records
     */
    public function updateRecordsAction(): void
    {
        if (!$this->checkCanEdit()) {
            return;
        }

        $dictionaryName = strtolower($this->request->post('dictionary', 'string', false));
        $data = $this->request->post('data', 'raw', false);
        $data = json_decode($data, true);

        if (empty($data) || !strlen($dictionaryName)) {
            $this->response->error($this->lang->get('WRONG_REQUEST'));
            return;
        }

        $dictionary = $this->container->get(Service::class)->get($dictionaryName);

        foreach ($data as $v) {
            if ($dictionary->isValidKey($v['key']) && $v['key'] != $v['id']) {
                $this->response->error($this->lang->get('WRONG_REQUEST'));
            }

            if (!empty($v['id'])) {
                $dictionary->removeRecord($v['id']);
            }
            $dictionary->addRecord($v['key'], $v['value']);
        }

        if (!$this->container->get(Manager::class)->saveChanges($dictionaryName)) {
            $this->response->error($this->lang->get('CANT_WRITE_FS'));
            return;
        }
        $this->response->success();
    }

    /**
     * Remove dictionary record
     */
    public function removeRecordsAction(): void
    {
        $dictionaryName = strtolower($this->request->post('dictionary', 'string', false));
        $name = $this->request->post('name', 'string', false);

        if (!strlen($name) || !strlen($dictionaryName)) {
            $this->response->error($this->lang->get('WRONG_REQUEST'));
            return;
        }

        $dictionary = $this->container->get(Service::class)->get($dictionaryName);
        $dictionary->removeRecord($name);

        if (!$this->container->get(Manager::class)->saveChanges($dictionaryName)) {
            $this->response->error($this->lang->get('CANT_WRITE_FS'));
            return;
        }

        $this->response->success();
    }
}
