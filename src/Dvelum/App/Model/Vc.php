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

use Dvelum\Db\Select;
use Dvelum\Orm;
use Dvelum\Orm\Model;

class Vc extends Model
{
    /**
     * Create new  version
     * @return int|false
     * @throws \Exception
     * @property Orm\Record $object
     */
    public function newVersion(Orm\RecordInterface $object)
    {
        $object->commitChanges();
        $newVersion = ($this->getLastVersion($object->getName(), $object->getId()) + 1);
        $newData = $object->getData();

        if ($object->getConfig()->hasEncrypted()) {
            $ivField = $object->getConfig()->getIvField();
            $ivKey = $object->get($ivField);

            if (empty($ivKey)) {
                $service = new \Dvelum\Security\CryptService(\Dvelum\Config::storage()->get('crypt.php'));
                $ivKey = $service->createVector();
                $newData[$ivField] = $ivKey;
            }

            $newData = $this->getStore()->encryptData($object, $newData);
        }

        $newData['id'] = $object->getId();
        try {
            $vObject = $this->orm->record('vc');
            $vObject->set('date', date('Y-m-d'));
            $vObject->set('data', base64_encode(serialize($newData)));
            $vObject->set('user_id', \Dvelum\App\Session\User::factory()->getId());
            $vObject->set('version', $newVersion);
            $vObject->set('record_id', $object->getId());
            $vObject->set('object_name', $object->getName());
            $vObject->set('date', date('Y-m-d H:i:s'));

            if ($vObject->save()) {
                return $newVersion;
            }

            return false;
        } catch (\Exception $e) {
            $this->logError(
                'Cannot create new version for ' . $object->getName() . '::' . $object->getId() . ' ' . $e->getMessage()
            );
            return false;
        }
    }

    /**
     * Get last version
     * @param string $objectName
     * @param mixed $recordId integer / array
     * @return mixed integer / array
     */
    public function getLastVersion(string $objectName, $recordId)
    {
        if (!is_array($recordId)) {
            $sql = $this->db->select()
                ->from(
                    $this->table(),
                    ['max_version' => 'MAX(version)']
                )
                ->where('record_id =?', $recordId)
                ->where('object_name =?', $objectName);
            return (int)$this->db->fetchOne($sql);
        }

        $sql = $this->db->select()
            ->from($this->table(), array('max_version' => 'MAX(version)', 'rec' => 'record_id'))
            ->where('`record_id` IN(?)', $recordId)
            ->where('`object_name` =?', $objectName)
            ->group('record_id');

        $revs = $this->db->fetchAll($sql);

        if (empty($revs)) {
            return [];
        }

        $data = [];
        foreach ($revs as $v) {
            $data[$v['rec']] = $v['max_version'];
        }

        return $data;
    }

    /**
     * @param Select $sql
     * @param string $fieldAlias
     */
    protected function queryAddAuthor(Select $sql, string $fieldAlias): void
    {
        $sql->joinLeft(
            array('u1' => $this->orm->model('User')->table()),
            'user_id = u1.id',
            [$fieldAlias => 'u1.name']
        );
    }

    /**
     * Get version data
     * @param string $objectName
     * @param int $recordId
     * @param int $version
     * @return array<string,mixed>
     */
    public function getData(string $objectName, int $recordId, int $version): array
    {
        $sql = $this->db->select()
            ->from($this->table(), array('data'))
            ->where('object_name = ?', $objectName)
            ->where('record_id =?', $recordId)
            ->where('version = ?', $version);

        $data = $this->db->fetchOne($sql);

        if (!empty($data)) {
            return unserialize(base64_decode($data));
        }
        return [];
    }

    /**
     * Remove item from version control
     * @param string $object
     * @param int $recordId
     */
    public function removeItemVc(string $object, int $recordId): void
    {
        $select = $this->db->select()
            ->from($this->table(), ['id'])
            ->where('`object_name` = ?', $this->db->quote($object))
            ->where('`record_id` = ?', $recordId);
        $vcIds = $this->db->fetchCol($select);
        $store = $this->getStore();
        $store->deleteObjects($this->name, $vcIds);
    }
}
