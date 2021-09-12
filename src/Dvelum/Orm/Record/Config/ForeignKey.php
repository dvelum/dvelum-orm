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

namespace Dvelum\Orm\Record\Config;

use Dvelum\Orm\Model;
use Dvelum\Orm\Orm;
use Dvelum\Orm\Record\Config;

/**
 * Class Field
 * @package Dvelum\Orm\Record\Config
 */
class ForeignKey
{
    /**
     * Check if Foreign keys can be used
     * @return bool
     * @throws \Exception
     */
    public function canUseForeignKeys(Config $config): bool
    {
        $configData = $config->getConfig();
        if ($configData->offsetExists('disable_keys') && $configData->get('disable_keys')) {
            return false;
        }

        if (!$config->isTransact()) {
            return false;
        }

        return true;
    }

    /**
     * Get list of foreign keys
     * @param Config $config
     * @return array<int,array>
     * array(
     *    array(
     *      'curDb' => string,
     *        'curObject' => string,
     *        'curTable' => string,
     *        'curField'=> string,
     *        'isNull'=> boolean,
     *        'toDb'=> string,
     *        'toObject'=> string,
     *        'toTable'=> string,
     *        'toField'=> string,
     *      'onUpdate'=> string
     *      'onDelete'=> string
     *   ),
     *  ...
     *  )
     * @throws \Exception
     */
    public function getForeignKeys(Config $config, Orm $orm): array
    {
        if (!$this->canUseForeignKeys($config)) {
            return [];
        }

        $curModel = $orm->model($config->getName());
        $curDb = $curModel->getDbConnection();
        $curDbCfg = $curDb->getConfig();

        $links = $config->getLinks([Config::LINK_OBJECT]);

        if (empty($links)) {
            return [];
        }

        $keys = [];
        foreach ($links as $object => $fields) {
            $oConfig = $orm->config($object);
            /*
             *  Only InnoDb implements Foreign Keys
             */
            if (!$oConfig->isTransact()) {
                continue;
            }

            $oModel = $orm->model($object);

            /*
             * Foreign keys are only available for objects with the same database connection
             */
            if ($curDb !== $oModel->getDbConnection()) {
                continue;
            }

            foreach ($fields as $name => $linkType) {
                $field = $config->getField($name);

                if ($field->isRequired()) {
                    $onDelete = 'RESTRICT';
                } else {
                    $onDelete = 'SET NULL';
                }

                $keys[] = array(
                    'curDb' => $curDbCfg['dbname'],
                    'curObject' => $config->getName(),
                    'curTable' => $curModel->table(),
                    'curField' => $name,
                    'toObject' => $object,
                    'toTable' => $oModel->table(),
                    'toField' => $oConfig->getPrimaryKey(),
                    'toDb' => $curDbCfg['dbname'],
                    'onUpdate' => 'CASCADE',
                    'onDelete' => $onDelete
                );
            }
        }
        return $keys;
    }
}
