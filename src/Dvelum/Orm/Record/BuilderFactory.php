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

namespace Dvelum\Orm\Record;

use Dvelum\Config;
use Dvelum\Config\Storage\StorageInterface;
use Dvelum\Lang\Dictionary;
use Dvelum\Orm;
use Laminas\Db\Adapter\Platform\PlatformInterface;

/**
 * Builder for Orm\Record
 * @package Orm
 * @subpackage Orm\Record
 * @author Kirill Ygorov
 * @license General Public License version 3
 *
 */
class BuilderFactory
{
    protected bool $writeLog = false;
    protected string $logPrefix = '0.1';
    protected string $logsPath = './logs/';
    protected bool $foreignKeys = false;

    /**
     * @param ?array<string,mixed> $configOptions
     */
    public function __construct(?array $configOptions)
    {
        if ($configOptions !== null) {
            foreach ($configOptions as $key => $value) {
                if (isset($this->{$key})) {
                    $this->{$key} = $value;
                }
            }
        }
    }

    /**
     * @param string $objectName
     * @return Builder\AbstractAdapter
     * @throws Orm\Exception
     */
    public function factory(
        Orm\Orm $orm,
        StorageInterface $configStorage,
        Dictionary $lang,
        string $objectName
    ): Builder\AbstractAdapter {
        $objectConfig = $orm->config($objectName);
        $adapter = 'Builder_Generic';
        $config = Config::factory(\Dvelum\Config\Factory::SIMPLE, $adapter);

        $log = false;
        if ($this->writeLog) {
            $log = new \Dvelum\Log\File\Sql(
                $this->logsPath . $objectConfig->get('connection') . '-' . $this->logPrefix . '-build.sql'
            );
        }

        $ormConfig = $configStorage->get('orm.php');

        $config->setData(
            [
                'objectName' => $objectName,
                'configPath' => $ormConfig->get('object_configs'),
                'log' => $log,
                'useForeignKeys' => $this->foreignKeys
            ]
        );

        $model = $orm->model($objectName);
        /**
         * @var PlatformInterface|null
         */
        $platform = $model->getDbConnection()->getAdapter()->getPlatform();
        if ($platform === null) {
            throw new Orm\Exception('Undefined Platform');
        }

        $platform = $platform->getName();

        $builderAdapter = '\\Dvelum\\Orm\\Record\\Builder\\' . $platform;

        if (class_exists($builderAdapter)) {
            return new $builderAdapter($config, $orm, $configStorage, $lang);
        }

        $builderAdapter = '\\Dvelum\\Orm\\Record\\Builder\\Generic\\' . $platform;

        if (class_exists($builderAdapter)) {
            return new $builderAdapter($config, $orm, $configStorage, $lang);
        }

        throw new Orm\Exception('Undefined Platform');
    }

    /**
     * @var string[]
     */
    public static array $booleanTypes = [
        'bool',
        'boolean'
    ];
    /**
     * @var string[]
     */
    public static array $numTypes = [
        'tinyint',
        'smallint',
        'mediumint',
        'int',
        'integer',
        'bigint',
        'float',
        'double',
        'decimal',
        'bit',
        'biginteger'
    ];
    /**
     * @var string[]
     */
    public static array $intTypes = [
        'tinyint',
        'smallint',
        'mediumint',
        'int',
        'integer',
        'bigint',
        'bit',
        'biginteger'
    ];
    /**
     * @var string[]
     */
    public static array $floatTypes = [
        'decimal',
        'float',
        'double'
    ];
    /**
     * @var string[]
     */
    public static array $charTypes = [
        'char',
        'varchar'
    ];
    /**
     * @var string[]
     */
    public static array $textTypes = [
        'tinytext',
        'text',
        'mediumtext',
        'longtext'
    ];
    /**
     * @var string[]
     */
    public static array $dateTypes = [
        'date',
        'datetime',
        'time',
        'timestamp'
    ];
    /**
     * @var string[]
     */
    public static array $blobTypes = [
        'tinyblob',
        'blob',
        'mediumblob',
        'longblob'
    ];
}
