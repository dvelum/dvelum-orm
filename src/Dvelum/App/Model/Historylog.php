<?php

/**
 *  DVelum project https://github.com/dvelum/dvelum , https://github.com/k-samuel/dvelum , http://dvelum.net
 *  Copyright (C) 2011-2019  Kirill Yegorov
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
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

    public const Delete = 1;
    public const Create = 2;
    public const Update = 3;
    public const Publish = 4;
    public const Sort = 5;
    public const Unpublish = 6;
    public const NewVersion = 7;

    /**
     * Log action. Fill history table
     * @param int|null $userId
     * @param int $recordId
     * @param int $type
     * @param string $object
     * @return bool
     * @throws Exception
     */
    public function log(?int $userId, $recordId, $type, $object): bool
    {
        if (!is_int($type)) {
            throw new Exception('History::log Invalid type');
        }

        $obj = $this->orm->record($this->name);
        $obj->setValues(
            [
                'user_id' => (int)$userId,
                'record_id' => (int)$recordId,
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
     * @return array
     */
    public function getLog($tableName, $recordId, $start = 0, $limit = 25): array
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
     * @param int $objectId
     * @param int $userId
     * @param string $date
     * @param string $before
     * @param string $after
     * @return int | false
     */
    public function saveState($operation, $objectName, $objectId, $userId, $date, $before = null, $after = null)
    {
        // Check object type
        if (!$this->orm->configExists($objectName)) {
            $this->logError('Invalid object name "' . $objectName . '"');
            return false;
        }

        try {
            $o = $this->orm->record('Historylog');
            $o->setValues(array(
                              'type' => $operation,
                              'object' => $objectName,
                              'record_id' => $objectId,
                              'user_id' => $userId,
                              'date' => $date,
                              'before' => $before,
                              'after' => $after
                          ));

            $id = $o->save(false);
            if (!$id) {
                throw new Exception('Cannot save object state ' . $objectName . '::' . $objectId);
            }

            return $id;
        } catch (Exception $e) {
            $this->logError($e->getMessage());
            return false;
        }
    }
}
