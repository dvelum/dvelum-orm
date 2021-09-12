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

namespace Dvelum\Orm;

use Dvelum\Db\Adapter;
use Dvelum\Lang;
use Dvelum\Orm;
use Dvelum\Utils;

class Stat
{
    private Orm\Orm $orm;
    private Lang\Dictionary $lang;
    private Distributed $distributed;

    /**
     * @param \Dvelum\Orm\Orm $orm
     * @param Lang\Dictionary $lang
     * @param Distributed $distributed
     */
    public function __construct(Orm\Orm $orm, Distributed $distributed, Lang\Dictionary $lang)
    {
        $this->orm = $orm;
        $this->lang = $lang;
        $this->distributed = $distributed;
    }

    /**
     * Get orm objects statistics
     * @return array<int, array{name:string,table:string,engine:string,vc:bool,fields:int,title:string,luik_title:string,rev_contril:bool,save_history:bool,system:bool,db_host:string,db_name:string,locked:bool,readonly:bool,primary_key:string,connection:string,distributed:bool}>
     * @phpstan-return array<int,array<string,mixed>>
     */
    public function getInfo(): array
    {
        $data = [];

        /*
         * Getting list of objects
         */
        $manager = $this->orm->getRecordManager();
        $names = $manager->getRegisteredObjects();

        if (empty($names)) {
            return [];
        }

        /*
         * forming result set
         */
        foreach ($names as $objectName) {
            $configObject = $this->orm->config($objectName);
            $objectModel = $this->orm->model($objectName);
            $config = $configObject->__toArray();
            $objectTable = $objectModel->table();

            $oDb = $objectModel->getDbConnection();
            $oDbConfig = $oDb->getConfig();


            $title = '';
            $saveHistory = true;
            $linkTitle = '';

            if (isset($config['title']) && !empty($config['title'])) {
                $title = $config['title'];
            }

            if (isset($config['link_title']) && !empty($config['link_title'])) {
                $linkTitle = $config['link_title'];
            }

            if (isset($config['save_history']) && !$config['save_history']) {
                $saveHistory = false;
            }

            $data[] = [
                'name' => $objectName,
                'table' => $objectTable,
                'engine' => $config['engine'],
                'vc' => $config['rev_control'],
                'fields' => count($config['fields']),

                'title' => $title,
                'link_title' => $linkTitle,
                'rev_control' => $config['rev_control'],
                'save_history' => $saveHistory,

                'system' => $configObject->isSystem(),

                'db_host' => $oDbConfig['host'],
                'db_name' => $oDbConfig['dbname'],
                'locked' => $config['locked'],
                'readonly' => $config['readonly'],
                'primary_key' => $configObject->getPrimaryKey(),
                'connection' => $config['connection'],
                'distributed' => $configObject->isDistributed(),
                'external' => ''
                /* @todo check external */
            ];
        }
        return $data;
    }

    /**
     * @param string $objectName
     * @param Adapter|null $db
     * @return array<int,array>
     */
    public function getDetails(string $objectName, ?Adapter $db = null): array
    {
        $objectModel = $this->orm->model($objectName);
        if (empty($db)) {
            $db = $objectModel->getDbConnection();
        }
        $data = $this->getTableInfo($objectName, $db);
        return [$data];
    }

    /**
     * @param string $objectName
     * @param Adapter $db
     * @return array<string,mixed>
     * @throws \Exception
     */
    protected function getTableInfo(string $objectName, Adapter $db): array
    {
        $objectModel = $this->orm->model($objectName);
        $objectTable = $objectModel->table();

        $records = 0;
        $dataLength = 0;
        $indexLength = 0;
        $size = 0;

        $tableInfo = [
            'rows' => [],
            'data_length' => null,
            'index_length' => null
        ];

        $data = [];

        if ($db->getAdapter()->getPlatform()->getName() === 'MySQL') {
            $platformAdapter = '\\Dvelum\\Orm\\Stat\\' . $db->getAdapter()->getPlatform()->getName();

            if (class_exists($platformAdapter)) {
                $adapter = new $platformAdapter();
                $tableData = $adapter->getTablesInfo($db, $objectTable);
            }

            if (!empty($tableData)) {
                $tableInfo = [
                    'rows' => $tableData['Rows'],
                    'data_length' => $tableData['Data_length'],
                    'index_length' => $tableData['Index_length']
                ];
            }
            unset($tableData);

            if (!empty($tableInfo)) {
                $records = $tableInfo['rows'];
                $dataLength = Utils::formatFileSize((int)$tableInfo['data_length']);
                $indexLength = Utils::formatFileSize((int)$tableInfo['index_length']);
                $size = Utils::formatFileSize((int)$tableInfo['data_length'] + (int)$tableInfo['index_length']);
            }

            $data = [
                'name' => $objectTable,
                'records' => number_format((int)$records, 0, '.', ' '),
                'data_size' => $dataLength,
                'index_size' => $indexLength,
                'size' => $size,
                'engine' => $objectModel->getObjectConfig()->get('engine'),
                'external' => ''
                /* @todo check external */
            ];
        }

        return $data;
    }

    /**
     * @param string $objectName
     * @param string|null $shard
     * @return array<int,mixed>
     * @throws Exception
     */
    public function getDistributedDetails(string $objectName, ?string $shard = null): array
    {
        $config = $this->orm->config($objectName);
        if (!$config->isDistributed()) {
            throw new Exception($objectName . ' is not distributed');
        }
        $objectModel = $this->orm->model($objectName);
        $connectionName = $objectModel->getConnectionName();
        $shards = $this->distributed->getShards();
        $table = $objectModel->table();
        $data = [];

        if (!empty($shards)) {
            if (!empty($shard)) {
                $shardInfo = $this->getTableInfo(
                    $objectName,
                    $objectModel->getDbManager()->getDbConnection(
                        $connectionName,
                        null,
                        $shard
                    )
                );
                $shardInfo['name'] = $shard . ' : ' . $table;
                $data[] = $shardInfo;
            } else {
                foreach ($shards as $info) {
                    $shardInfo = $this->getTableInfo(
                        $objectName,
                        $objectModel->getDbManager()->getDbConnection(
                            $connectionName,
                            null,
                            $info['id']
                        )
                    );
                    $shardInfo['name'] = $info['id'] . ' : ' . $table;
                    $data[] = $shardInfo;
                }
            }
        }
        return $data;
    }

    /**
     * Validate Db object
     * @param string $objectName
     * @return array<string,mixed>
     * @throws \Exception
     */
    public function validate(string $objectName): array
    {
        $config = $this->orm->config($objectName);
        $builder = $this->orm->getBuilder($objectName);

        $hasBroken = false;

        if ($config->isDistributed()) {
            $valid = $builder->validateDistributedConfig();
        } else {
            $valid = $builder->validate();
        }

        if (!empty($builder->getBrokenLinks())) {
            $hasBroken = true;
        }

        if ($hasBroken || !$valid) {
            $group = $this->lang->get('INVALID_STRUCTURE');
        } else {
            $group = $this->lang->get('VALID_STRUCTURE');
        }
        $result = [
            'title' => $config->getTitle(),
            'name' => $objectName,
            'validdb' => $valid,
            'broken' => $hasBroken,
            'locked' => $config->get('locked'),
            'readonly' => $config->get('readonly'),
            'distributed' => $config->isDistributed(),
            'shard_title' => '-',
            'id' => $objectName
        ];
        return $result;
    }

    /**
     * @param string $objectName
     * @param string $shard
     * @return array<int,mixed>
     */
    public function validateDistributed(string $objectName, string $shard): array
    {
        $config = $this->orm->config($objectName);
        $builder = $this->orm->getBuilder($objectName);
        $model = $this->orm->model($objectName);
        $connectionName = $model->getConnectionName();
        $shards = $this->distributed->getShards();

        $result[] = $this->validate($objectName);

        foreach ($shards as $item) {
            if (strlen($shard) && $item['id'] = !$shard) {
                continue;
            }

            $hasBroken = false;
            $builder->setConnection(
                $model->getDbManager()->getDbConnection($connectionName, null, (string)$item['id'])
            );
            $valid = $builder->validate();

            if (!empty($builder->getBrokenLinks())) {
                $hasBroken = true;
            }

            /*
            if($hasBroken || !$valid) {
                $group =  $lang->get('INVALID_STRUCTURE');
            }else{
                $group =  $lang->get('VALID_STRUCTURE');
            }
            */

            $result[] = [
                'title' => $config->getTitle(),
                'name' => $objectName,
                'validdb' => $valid,
                'broken' => $hasBroken,
                'locked' => $config->get('locked'),
                'readonly' => $config->get('readonly'),
                'distributed' => $config->isDistributed(),
                'shard' => $item['id'],
                'shard_title' => $item['id'],
                'id' => $objectName . $item['id']
            ];
        }
        return $result;
    }
}
