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
use Dvelum\Orm\Exception;
use Dvelum\App\Model\Historylog;
use Dvelum\Orm\Model;
use Dvelum\Orm\Record;
use Dvelum\Utils;

class History extends Controller
{
    /**
     * Get object history
     */
    public function listAction()
    {
        $object = $this->request->post('object', 'string', false);

        if (!$object) {
            $this->response->success([]);
            return;
        }

        $pager = $this->request->post('pager', 'array', []);
        $filter = $this->request->post('filter', 'array', []);

        if (!isset($filter['record_id']) || empty($filter['record_id'])) {
            $this->response->success([]);
            return;
        }

        try {
            /**
             * @var Orm\RecordInterface
             */
            $object = $this->ormService->record($object);
        } catch (\Exception $e) {
            $this->response->success([]);
            return;
        }

        $filter['object'] = $object->getName();

        $history = $this->ormService->model('Historylog');

        $data = $history->query()
            ->filters($filter)
            ->params($pager)
            ->fields(['date', 'type', 'id'])
            ->fetchAll();

        $objectConfig = $this->ormService->config('Historylog');

        $this->addLinkedInfo($objectConfig, ['user_name' => 'user_id'], $data, $objectConfig->getPrimaryKey());

        if (!empty($data)) {
            foreach ($data as &$v) {
                if (isset(\Dvelum\App\Model\Historylog::$actions[$v['type']])) {
                    $v['type'] = App\Model\Historylog::$actions[$v['type']];
                }
            }
            unset($v);
        }
        $this->response->success($data, ['count' => $history->query()->filters($filter)->getCount()]);
    }

    /**
     * Add related objects info into getList results
     * @param Orm\Record\Config $cfg
     * @param array $fieldsToShow list of link fields to process ( key - result field, value - object field)
     * object field will be used as result field for numeric keys
     * @param array & $data rows from  Model::getList result
     * @param string $pKey - name of Primary Key field in $data
     * @throws \Exception
     */
    protected function addLinkedInfo(Orm\Record\Config $cfg, array $fieldsToShow, array &$data, $pKey)
    {
        $fieldsToKeys = [];
        foreach ($fieldsToShow as $key => $val) {
            if (is_numeric($key)) {
                $fieldsToKeys[$val] = $val;
            } else {
                $fieldsToKeys[$val] = $key;
            }
        }

        $links = $cfg->getLinks(
            [
                Orm\Record\Config::LINK_OBJECT,
                Orm\Record\Config::LINK_OBJECT_LIST,
                Orm\Record\Config::LINK_DICTIONARY
            ],
            false
        );

        /*
        foreach ($fieldsToShow as $objectField) {
            if (!isset($links[$objectField])) {
                throw new \Exception($objectField . ' is not Link');
            }
        }
        */

        foreach ($links as $field => $config) {
            if (!isset($fieldsToKeys[$field])) {
                unset($links[$field]);
            }
        }

        $rowIds = Utils::fetchCol($pKey, $data);
        $rowObjects = $this->ormService->records($cfg->getName(), $rowIds);
        $listedObjects = [];

        foreach ($rowObjects as $object) {
            foreach ($links as $field => $config) {
                if ($config['link_type'] === Orm\Record\Config::LINK_DICTIONARY) {
                    continue;
                }

                if (!isset($listedObjects[$config['object']])) {
                    $listedObjects[$config['object']] = [];
                }

                $oVal = $object->get($field);

                if (!empty($oVal)) {
                    if (!is_array($oVal)) {
                        $oVal = [$oVal];
                    }
                    $listedObjects[$config['object']] = array_merge(
                        $listedObjects[$config['object']],
                        array_values($oVal)
                    );
                }
            }
        }

        foreach ($listedObjects as $object => $ids) {
            $listedObjects[$object] = $this->ormService->record($object, array_unique($ids));
        }

        /**
         * @var Lang\Dictionary $dictionaryService
         */
        $dictionaryService = $this->container->get(Lang::class);

        foreach ($data as &$row) {
            if (!isset($rowObjects[$row[$pKey]])) {
                continue;
            }

            foreach ($links as $field => $config) {
                $list = [];
                $rowObject = $rowObjects[$row[$pKey]];
                $value = $rowObject->get($field);

                if (!empty($value)) {
                    if ($config['link_type'] === Orm\Record\Config::LINK_DICTIONARY) {
                        $dictionary = $dictionaryService->get($config['object']);
                        if ($dictionary->isValidKey($value)) {
                            $row[$fieldsToKeys[$field]] = $dictionary->getValue($value);
                        }
                        continue;
                    }

                    if (!is_array($value)) {
                        $value = [$value];
                    }

                    foreach ($value as $oId) {
                        if (isset($listedObjects[$config['object']][$oId])) {
                            $list[] = $listedObjects[$config['object']][$oId]->getTitle();
                        } else {
                            $list[] = '[' . $oId . '] (' . $this->lang->get('DELETED') . ')';
                        }
                    }
                }
                $row[$fieldsToKeys[$field]] = implode('; ', $list);
            }
        }
        unset($row);
    }
}
