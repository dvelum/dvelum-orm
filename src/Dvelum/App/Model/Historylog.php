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

namespace Dvelum\App\Model;

use Dvelum\Orm;
use Dvelum\Orm\Model;
use Exception;

/**
 * History logger
 * @author Kirill Egorov 2011
 */
class Historylog extends Model
{
    /**
     * Action types
     * @var array<string>
     */
    public static array $actions = [
        1 => 'Delete',
        2 => 'Create',
        3 => 'Update',
        4 => 'Publish',
        5 => 'Sort',
        6 => 'Unpublish',
        7 => 'New Version'
    ];

    public const DELETE = 1;
    public const CREATE = 2;
    public const UPDATE = 3;
    public const PUBLISH = 4;
    public const SORT = 5;
    public const UNPUBLISH = 6;
    public const NEW_VERSION = 7;

    /**
     * Log action. Fill history table
     * @param int|null $userId
     * @param int|null $recordId
     * @param int $type
     * @param string $object
     * @return bool
     * @throws Exception
     */
    public function log(?int $userId, ?int $recordId, ?int $type, string $object): bool
    {
        if (!is_int($type)) {
            throw new Exception('History::log Invalid type');
        }

        $obj = $this->orm->record($this->name);
        $obj->setValues(
            [
                'user_id' => (int)$userId,
                'record_id' => $recordId,
                'type' => (int)$type,
                'date' => date('Y-m-d H:i:s'),
                'object' => $object
            ]
        );
        return (bool)$obj->save(false);
    }

    /**
     * Get log for the  data item
     * @param string $tableName
     * @param int $recordId
     * @param int $start - optional
     * @param int $limit - optional
     * @return array<int,array<string,mixed>>
     */
    public function getLog(string $tableName, int $recordId, int $start = 0, int $limit = 25): array
    {
        $db = $this->getDbConnection();
        $sql = $db->select()
            ->from(array('l' => $this->table()), ['type', 'date'])
            ->where('l.table_name = ?', $tableName)
            ->where('l.record_id = ?', $recordId)
            ->joinLeft(
                array('u' => $this->orm->model('User')->table()),
                ' l.user_id = u.id',
                array('user' => 'u.name)')
            )
            ->order('l.date DESC')
            ->limit($limit, $start);

        $data = $db->fetchAll($sql);

        if (!empty($data)) {
            foreach ($data as &$v) {
                if (isset(self::$actions[$v['type']])) {
                    $v['type'] = self::$actions[$v['type']];
                } else {
                    $v['type'] = 'unknown';
                }
            }
            return $data;
        }

        return [];
    }

    /**
     * Save object state
     * @param int $operation
     * @param string $objectName
     * @param int|null $objectId
     * @param int|null $userId
     * @param string $date
     * @param string $before
     * @param string $after
     * @return bool
     */
    public function saveState(
        int $operation,
        string $objectName,
        ?int $objectId,
        ?int $userId,
        string $date,
        string $before = null,
        string $after = null
    ): bool {
        // Check object type
        if (!$this->orm->configExists($objectName)) {
            $this->logError('Invalid object name "' . $objectName . '"');
            return false;
        }

        try {
            $o = $this->orm->record('Historylog');
            $o->setValues(
                array(
                    'type' => $operation,
                    'object' => $objectName,
                    'record_id' => $objectId,
                    'user_id' => $userId,
                    'date' => $date,
                    'before' => $before,
                    'after' => $after
                )
            );

            $success = $o->save(false);
            if (!$success) {
                throw new Exception('Cannot save object state ' . $objectName . '::' . $objectId);
            }
            return true;
        } catch (Exception $e) {
            $this->logError($e->getMessage());
            return false;
        }
    }
}
