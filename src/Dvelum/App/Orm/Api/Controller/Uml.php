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
use Dvelum\Config;
use Dvelum\Orm;

class Uml extends Controller
{
    protected string $mapConfig = 'umlMap.php';

    /**
     * Get data for UML diagram
     */
    public function loadMapAction(): void
    {
        $ormConfig = $this->configStorage->get('orm.php');
        $config = $this->configStorage->get($ormConfig->get('uml_map_data'), true, false);

        $items = $config->get('items');

        $data = [];

        $manager = $this->ormService->getRecordManager();

        $names = $manager->getRegisteredObjects();

        if (empty($names)) {
            $names = [];
        }

        $showObj = $this->request->post('objects', 'array', []);

        if (empty($showObj)) {
            foreach ($names as $name) {
                if (!isset($items[$name]['show']) || $items[$name]['show']) {
                    $showObj[] = $name;
                }
            }
        } else {
            foreach ($showObj as $k => $name) {
                if (!in_array($name, $names, true)) {
                    unset($showObj[$k]);
                }
            }
        }

        $defaultX = 10;
        $defaultY = 10;

        foreach ($names as $index => $objectName) {
            $objectConfig = $this->ormService->config($objectName);
            if (!empty($objectConfig->isRelationsObject()) || !in_array($objectName, $showObj)) {
                unset($names[$index]);
                continue;
            }

            $data[$objectName]['links'] = $objectConfig->getLinks();
            $data[$objectName]['fields'] = [];

            $objectConfig = $this->ormService->config($objectName);
            $fields = $objectConfig->getFieldsConfig();

            foreach ($fields as $fieldName => $fieldData) {
                $data[$objectName]['fields'][] = $fieldName;
                $data[$objectName]['savedlinks'] = [];
                if (isset($items[$objectName])) {
                    $data[$objectName]['position'] = array(
                        'x' => $items[$objectName]['x'],
                        'y' => $items[$objectName]['y']
                    );
                    $data[$objectName]['savedlinks'] = [];
                    if (!empty(isset($items[$objectName]['links']))) {
                        $data[$objectName]['savedlinks'] = $items[$objectName]['links'];
                    }
                } else {
                    $data[$objectName]['position'] = array('x' => $defaultX, 'y' => $defaultY);
                    $defaultX += 10;
                    $defaultY += 10;
                }
            }
            sort($data[$objectName]['fields']);
        }

        foreach ($names as $objectName) {
            foreach ($data[$objectName]['links'] as $link => $link_value) {
                if (!isset($data[$link])) {
                    continue;
                }
                $data[$link]['weight'] = (!isset($data[$link]['weight']) ? 1 : $data[$link]['weight'] + 1);
            }
            if (!isset($data[$objectName]['weight'])) {
                $data[$objectName]['weight'] = 0;
            }
        }

        $fieldName = "weight";

        uasort($data, function ($a, $b) use ($fieldName) {
            if ($a[$fieldName] > $b[$fieldName]) {
                return 1;
            } elseif ($a[$fieldName] < $b[$fieldName]) {
                return -1;
            } else {
                return 0;
            }
            //return strnatcmp((string)$b[$fieldName], (string) $a[$fieldName]);
        });

        $result = [
            'mapWidth' => $config->get('mapWidth'),
            'mapHeight' => $config->get('mapHeight'),
            'items' => $data
        ];
        $this->response->success($result);
    }

    /**
     * Save object coordinates
     */
    public function saveMapAction(): void
    {
        if (!$this->checkCanEdit()) {
            return;
        }

        $map = $this->request->post('map', 'raw', '');

        if (!strlen($map)) {
            $this->response->error($this->lang->get('WRONG_REQUEST'));
            return;
        }

        $data = json_decode($map, true);

        $ormConfig = $this->configStorage->get('orm.php');

        $config = $this->configStorage->get($ormConfig->get('uml_map_data'), true, false);

        $saved = $config->get('items');

        $manager = $this->ormService->getRecordManager();
        $registered = $manager->getRegisteredObjects();
        if (empty($registered)) {
            $registered = [];
        }

        /**
         * Check objects map from request and set show property
         */
        foreach ($data as $k => $item) {
            if (!in_array($k, $registered, true)) {
                unset($data[$k]);
                continue;
            }
            $data[$k]['show'] = true;
        }

        /**
         * Add saved map objects with checking that object is registered
         */
        foreach ($saved as $k => $item) {
            $item['show'] = false;
            if (!array_key_exists($k, $data) && in_array($k, $registered, true)) {
                $data[$k] = $item;
            }
        }

        $config->set('items', $data);

        if ($this->configStorage->save($config)) {
            $this->response->success();
        } else {
            $this->response->error($this->lang->get('CANT_WRITE_FS'));
        }
    }
}
