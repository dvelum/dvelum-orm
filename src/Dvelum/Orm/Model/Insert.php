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

namespace Dvelum\Orm\Model;

use Dvelum\Orm\Model;
use Dvelum\Db\Adapter;

class Insert implements InsertInterface
{
    /**
     * @var Model $model
     */
    protected Model $model;
    /**
     * @var Adapter $db
     */
    protected Adapter $db;

    public function __construct(Model $model)
    {
        $this->model = $model;
        $this->db = $model->getDbConnection();
    }

    /**
     * Insert multiple rows (not safe but fast)
     * @param array<int,array> $records
     * @param int $chunkSize , optional default 500
     * @param bool $ignore - optional default false Ignore errors
     * @return bool
     */
    public function bulkInsert(array $records, int $chunkSize = 500, bool $ignore = false): bool
    {
        if (empty($records)) {
            return true;
        }

        $chunks = array_chunk($records, $chunkSize);

        $keys = array_keys($records[key($records)]);

        foreach ($keys as &$key) {
            $key = $this->db->quoteIdentifier((string)$key);
        }
        unset($key);

        $keys = implode(',', $keys);

        foreach ($chunks as $rowset) {
            foreach ($rowset as &$row) {
                foreach ($row as &$colValue) {
                    if (is_bool($colValue)) {
                        $colValue = intval($colValue);
                    } elseif (is_null($colValue)) {
                        $colValue = 'NULL';
                    } else {
                        $colValue = $this->db->quote($colValue);
                    }
                }
                unset($colValue);
                $row = implode(',', $row);
            }
            unset($row);

            $sql = 'INSERT ';

            if ($ignore) {
                $sql .= 'IGNORE ';
            }

            $sql .= 'INTO ' . $this->model->table() . ' (' . $keys . ') ' . "\n" . ' VALUES ' . "\n" . '(' .
                implode(')' . "\n" . ',(', array_values($rowset)) . ') ' . "\n" . '';

            try {
                $this->db->query($sql);
            } catch (\Exception $e) {
                $this->model->logError('multiInsert: ' . $e->getMessage());
                return false;
            }
        }
        return true;
    }

    /**
     * Insert single record on duplicate key update
     * @param array<string,mixed> $data
     * @return bool
     */
    public function onDuplicateKeyUpdate(array $data): bool
    {
        if (empty($data)) {
            return true;
        }

        $keys = array_keys($data);

        foreach ($keys as &$val) {
            $val = $this->db->quoteIdentifier($val);
        }
        unset($val);

        $values = array_values($data);
        foreach ($values as &$val) {
            if (is_bool($val)) {
                $val = intval($val);
            } elseif (is_null($val)) {
                $val = 'NULL';
            } else {
                $val = $this->db->quote($val);
            }
        }
        unset($val);

        $sql = 'INSERT INTO ' . $this->db->quoteIdentifier($this->model->table()) . ' (' .
            implode(',', $keys) .
            ') VALUES (' . implode(',', $values) . ') ON DUPLICATE KEY UPDATE ';

        $updates = [];
        foreach ($keys as $key) {
            $updates[] = $key . ' = VALUES(' . $key . ') ';
        }

        $sql .= implode(', ', $updates) . ';';

        try {
            $this->db->query($sql);
            return true;
        } catch (\Exception $e) {
            $this->model->logError($e->getMessage() . ' SQL: ' . $sql);
            return false;
        }
    }
}
