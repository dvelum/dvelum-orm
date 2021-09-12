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

namespace Dvelum\Orm\Record;

use Dvelum\Db;
use Exception;

/**
 * Import component, experimental class
 * @package ORM
 * @subpackage Object
 * @license General Public License version 3
 * @example
 */
class Import
{
    /**
     * @var array<string>
     */
    protected array $errors = [];

    /**
     * @return array<int,string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Check if PRIMARY KEY of external DB table is correct
     * @param Db\Adapter $db
     * @param string $table
     * @return bool
     */
    public function isValidPrimaryKey(Db\Adapter $db, string $table): bool
    {
        /**
         * @var Db\Metadata $meta
         */
        $meta = $db->getMeta();
        $primary = $meta->findPrimaryKey($table);

        if (empty($primary)) {
            $this->errors[] = 'No primary key';
            return false;
        }

        $column = $meta->getAdapter()->getColumn($primary, $table);
        $dataType = $column->getDataType();
        if (empty($dataType)) {
            return false;
        }
        $dataType = strtolower($dataType);

        if (!in_array($dataType, BuilderFactory::$numTypes, true)) {
            $this->errors[] = 'PRIMARY KEY is not numeric';
            return false;
        }

        /**
         * @todo check autoincrement or PG SERIAL/SEQUENCE
         */
//        if($primary['IDENTITY']!=1){
//            $this->errors[] = 'The PRIMARY KEY is not using auto-increment';
//            return false;
//        }
        return true;
    }

    /**
     * @param Db\Adapter $dbAdapter
     * @param string $tableName
     * @param mixed $adapterPrefix , optional default - false
     * @return array<string,mixed>
     * @throws Exception
     * @todo cleanup the code
     */
    public function createConfigByTable(Db\Adapter $dbAdapter, string $tableName, $adapterPrefix = false): array
    {
        $config = [];

        if ($adapterPrefix && strpos($tableName, $adapterPrefix) === 0) {
            $config['table'] = substr($tableName, strlen($adapterPrefix));
            $config['use_db_prefix'] = true;
        } else {
            $config['table'] = $tableName;
            $config['use_db_prefix'] = false;
        }

        $config['readonly'] = false;
        $config['system'] = false;
        $config['locked'] = false;
        $config['disable_keys'] = false;
        $config['rev_control'] = false;
        $config['save_history'] = true;

        /**
         * @var Db\Metadata $meta
         */
        $meta = $dbAdapter->getMeta();
        $primary = $meta->findPrimaryKey($tableName);

        if (empty($primary)) {
            return [];
        }

        $config['primary_key'] = $primary;
        $config['link_title'] = $primary;

        $columns = $dbAdapter->getMeta()->getColumns($tableName);

        $engine = $dbAdapter->fetchRow('SHOW TABLE STATUS WHERE `Name` = "' . $tableName . '"');

        // $indexes = $dbAdapter->fetchAll('SHOW INDEX FROM `' . $tableName . '`');
        $indexes = $dbAdapter->getMeta()->getConstraints($tableName);

        $index = [];
        $indexGroups = [];
        foreach ($indexes as $k => $v) {
            /**
             * @var \Laminas\Db\Metadata\Object\ConstraintObject $v
             */
            if ($v->isForeignKey()) {
                continue;
            }

            $hash = $meta->indexHashByColumns($v->getColumns());

            if (strtolower($hash) == $config['primary_key']) {
                continue;
            }

            $flag = false;
            if (!empty($index)) {
                foreach ($index as $key => &$val) {
                    if ($key == $hash) {
                        $val['columns'][] = $hash;
                        $flag = true;

                        if ($v->isUnique()) {
                            $indexGroups[$hash][] = $hash;
                        }

                        break;
                    }
                }
            }

            unset($val);
            if ($flag) {
                continue;
            }

            if ($v->getType() == 'FULLTEXT') {
                $index[$hash]['fulltext'] = true;
            } else {
                $index[$hash]['fulltext'] = false;
            }

            /**
             * Non_unique
             * 0 if the index cannot contain duplicates, 1 if it can.
             */
            if (!$v->isUnique()) {
                $index[$hash]['unique'] = false;
            } else {
                $index[$hash]['unique'] = true;
                $indexGroups[$hash][] = $hash;
            }

            $index[$hash]['columns'] = $v->getColumns();
        }

        $fields = [];
        $objectFields = [];
        foreach ($columns as $k => $v) {
            /**
             * @var \Laminas\Db\Metadata\Object\ColumnObject $v
             */
            $name = $v->getName();
            if (strtolower($name) == $config['primary_key']) {
                continue;
            }

            $objectFields[$name] = array(
                'title' => $name,
                'db_type' => strtolower((string)$v->getDataType())
            );

            $fieldLink = &$objectFields[$name];

            if (!empty($v->getCharacterMaximumLength())) {
                $fieldLink['db_len'] = $v->getCharacterMaximumLength();
            }

            if ($v->getColumnDefault() !== null) {
                $fieldLink['db_default'] = $v->getColumnDefault();
            }

            if ($v->getIsNullable()) {
                $fieldLink['db_isNull'] = true;
                $fieldLink['required'] = false;
            } else {
                $fieldLink['db_isNull'] = false;
                $fieldLink['required'] = true;
            }

            if ($v->getNumericUnsigned()) {
                $fieldLink['db_unsigned'] = true;
            }

            if (!empty($v->getNumericPrecision())) {
                $fieldLink['db_scale'] = $v->getNumericPrecision();
            }

            if (!empty($v->getNumericScale())) {
                $fieldLink['db_precision'] = $v->getNumericScale();
            }

            if (array_key_exists($name, $indexGroups)) {
                $fieldLink['unique'] = $indexGroups[$name];
            }

//            if($v['IDENTITY'])
//               $fieldLink['auto_increment'] = true;

            unset($fieldLink);
        }

        $config['engine'] = $engine['Engine'];
        $config['fields'] = $objectFields;

        if (!empty($index)) {
            $config['indexes'] = $index;
        }

        return $config;
    }
}
