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

namespace Dvelum\Orm\Record\Field;

use Dvelum\Orm\Exception;

/**
 * Db_Object Property class
 * Note: "id"  property creates automatically
 * @package Db
 * @subpackage Db_Object
 * @author Kirill A Egorov kirill.a.egorov@gmail.com
 * @copyright Copyright (C) 2011-2012  Kirill A Egorov,
 * DVelum project https://github.com/dvelum/dvelum , http://dvelum.net
 * @license General Public License version 3
 * @deprecated
 */
class Property
{
    /**
     *  List of acceptable properties
     * @var array<string>
     */
    public static array $acceptedData = [
        'name',
        'title',
        'required',
        'allow_html',
        'db_type',
        'db_len',
        'db_isNull',
        'db_default',
        'db_unsigned',
        'db_scale',
        'db_precision',
        'db_auto_increment',
        'type',
        'link_config',
        'is_search',
        'unique',
        'validator',
        'system',
        'lazyLang',
        'locked',
        'readonly',
        'connection',
        'use_db_prefix',
        'hidden',
        'relations_type',
        'sharding',
        'data_object',
        'parent_object'
    ];
    /**
     * @var int[]
     */
    public static array $numberLength = [
        'tinyint' => 3,
        'smallint' => 5,
        'mediumint' => 8,
        'int' => 10,
        'bigint' => 20
    ];

    /**
     * Properties data
     * @var array<mixed>
     */
    protected array $data = [];

    protected string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    /**
     * Set property data
     * @param array<mixed> $data
     * @return void
     * @throws \Exception
     */
    public function setData(array $data): void
    {
        foreach ($data as $key => $value) {
            if (in_array($key, self::$acceptedData, true)) {
                $this->data[$key] = $value;
            } else {
                throw new Exception('Invalid property name "' . $key . '"');
            }
        }
    }

    /**
     * Empty data
     * @return void
     */
    public function clear(): void
    {
        $this->data = [];
    }

    /**
     * Getter
     * @param string $key
     * @return mixed
     * @throws \Exception
     */
    public function __get(string $key)
    {
        if (isset($this->data[$key])) {
            return $this->data[$key];
        } else {
            throw new Exception('Invalid property name "' . $key . '"');
        }
    }

    public function __isset(string $key): bool
    {
        return isset($this->data[$key]);
    }

    /**
     * Get SQL part
     * @return string
     */
    public function __toSql(): string
    {
        $s = '';
        $isNumber = false;

        $dbType = strtolower($this->data['db_type']);

        switch ($dbType) {
            case 'boolean':
                $this->data['db_isNull'] = false;
                $s .= '`' . $this->name . '` ' . $dbType . ' ';
                break;
            case 'tinyint':
            case 'smallint':
            case 'mediumint':
            case 'int':
            case 'bigint':
                $this->data['db_len'] = self::$numberLength[$dbType];

                $s .= '`' . $this->name . '` ' . $dbType . ' (' . $this->data['db_len'] . ')';

                if (
                    isset($this->data['db_unsigned']) &&
                    $this->data['db_unsigned'] &&
                    strtolower($dbType) !== 'boolean'
                ) {
                    $s .= ' UNSIGNED ';
                }

                if (
                    $dbType !== 'boolean' &&
                    isset($this->data['db_auto_increment']) &&
                    $this->data['db_auto_increment']
                ) {
                    $s .= ' AUTO_INCREMENT ';
                }

                $isNumber = true;
                break;

            case 'bit':
                $s .= '`' . $this->name . '` ' . $dbType . ' ';

                if (
                    isset($this->data['db_unsigned']) &&
                    $this->data['db_unsigned'] &&
                    strtolower($dbType) !== 'boolean'
                ) {
                    $s .= ' UNSIGNED ';
                }

                $isNumber = true;
                break;
            case 'real':
            case 'float':
            case 'double':
            case 'decimal':
                $s .= '`' . $this->name . '` ' .
                    $dbType .
                    '(' . $this->data['db_scale'] . ',' .
                    $this->data['db_precision'] .
                    ') ';

                if (isset($this->data['db_unsigned']) && $this->data['db_unsigned']) {
                    $s .= ' UNSIGNED ';
                }

                $isNumber = true;
                break;
            case 'char':
            case 'varchar':
                /*
                Auto set default '' for NOT NULL string properties
                if(!isset($this->data['db_isNull']) || !$this->data['db_isNull'])
                    if(!isset($this->data['db_default']) || $this->data['db_default'] === false)
                        $this->data['db_default'] = '';
                */
                $s = '`' . $this->name . '` ' . $dbType . ' (' . $this->data['db_len'] . ')  ';
                break;
            case 'date':
            case 'time':
            case 'timestamp':
            case 'datetime':
                if (isset($this->data['db_default']) && !strlen((string)$this->data['db_default'])) {
                    unset($this->data['db_default']);
                }
                $s = '`' . $this->name . '` ' . $dbType . ' ';
                break;
            case 'tinytext':
            case 'text':
            case 'mediumtext':
            case 'longtext':
                $s = '`' . $this->name . '` ' . $dbType . ' ';
                if (isset($this->data['db_default'])) {
                    unset($this->data['db_default']);
                }
                if (!isset($this->data['required']) || !$this->data['required']) {
                    $this->data['db_isNull'] = true;
                }
                break;
        }

        if (!$this->data['db_isNull']) {
            $s .= 'NOT NULL ';
            if (isset($this->data['db_default']) && $this->data['db_default'] !== false) {
                if ($isNumber) {
                    $s .= " DEFAULT " . $this->data['db_default'] . " ";
                } else {
                    $s .= " DEFAULT '" . $this->data['db_default'] . "' ";
                }
            }
        } else {
            $s .= ' NULL ';
        }

        if (isset($this->data['db_auto_increment']) && $this->data['db_auto_increment']) {
            $s .= ' AUTO_INCREMENT ';
        }

        $s .= " COMMENT '" . addslashes($this->data['title']) . "' ";
        return $s;
    }

    /**
     * Setter
     * @param string $key
     * @param mixed $value
     * @throws \Exception
     */
    public function __set(string $key, $value): void
    {
        if (in_array($key, self::$acceptedData, true)) {
            $this->data[$key] = $value;
        } else {
            throw new \Exception('Invalid property name');
        }
    }

    /**
     * Property filter
     * @param array<string,mixed> $fieldInfo - property config data
     * @param mixed $value
     * @return mixed
     * @throws \Exception
     */
    public static function filter(array $fieldInfo, $value)
    {
        switch (strtolower($fieldInfo['db_type'])) {
            case 'tinyint':
            case 'smallint':
            case 'mediumint':
            case 'int':
            case 'bigint':
                $value = \Dvelum\Filter::filterValue('int', $value);
                break;

            case 'float':
            case 'double':
            case 'decimal':
                $value = \Dvelum\Filter::filterValue('float', $value);

                break;
            case 'bool':
            case 'boolean':
                $value = \Dvelum\Filter::filterValue('boolean', $value);
                break;
            case 'date':
            case 'time':
            case 'timestamp':
            case 'datetime':
                $value = \Dvelum\Filter::filterValue('string', $value);
                break;
            case 'tinytext':
            case 'text':
            case 'bit':
            case 'mediumtext':
            case 'longtext':
            case 'char':
            case 'varchar':
                if (!isset($fieldInfo['allow_html']) || !$fieldInfo['allow_html']) {
                    $value = \Dvelum\Filter::filterValue('string', $value);
                }
                break;
            default:
                throw new \Exception('Invalid property type "' . $fieldInfo['db_type'] . '"');
        }
        return $value;
    }
}
