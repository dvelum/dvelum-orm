<?php
/**
 *  DVelum project https://github.com/dvelum/dvelum
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
 */

namespace Dvelum\Orm\Record;

use Dvelum\Config\Storage\StorageInterface;
use Dvelum\Orm\Model;
use Dvelum\Orm\Orm;
use Dvelum\Utils;
use Dvelum\Orm\Record;

/**
 * Orm Record information expert
 * Helps to find relations between objects
 */
class Expert
{
    protected Orm $orm;
    protected Manager $recordManager;
    protected StorageInterface $configStorage;

    public function __construct(Orm $orm, StorageInterface $configSStorage, Manager $recordManager)
    {
        $this->orm = $orm;
        $this->recordManager = $recordManager;
        $this->configStorage = $configSStorage;
    }

    /**
     * @var array<string,mixed>|null
     */
    static protected ?array $objectAssociations = null;

    protected function buildAssociations() : void
    {
        if (self::$objectAssociations !== null) {
            return;
        }

        $objects = $this->recordManager->getRegisteredObjects();
        if (!empty($objects)) {
            foreach ($objects as $name) {
                $config = $this->orm->config($name);
                $links = $config->getLinks();
                self::$objectAssociations[$name] = $links;
            }
        }
    }

    /**
     * Get Associated objects
     * @param Record $object
     * @return array<string,array>   like
     * array(
     *      'single' => array(
     *            'objectName'=>array(id1,id2,id3),
     *            ...
     *            'objectNameN'=>array(id1,id2,id3),
     *       ),
     *       'multi' =>array(
     *            'objectName'=>array(id1,id2,id3),
     *            ...
     *            'objectNameN'=>array(id1,id2,id3),
     *       )
     * )
     */
    public function getAssociatedObjects(Record $object)
    {
        $linkedObjects = ['single' => [], 'multi' => []];

        $this->buildAssociations();

        $objectName = $object->getName();
        $objectId = $object->getId();

        if (!isset(self::$objectAssociations[$objectName])) {
            return array();
        }

        foreach (self::$objectAssociations as $testObject => $links) {
            if (!isset($links[$objectName])) {
                continue;
            }

            $sLinks = $this->getSingleLinks($objectId, $testObject, $links[$objectName]);

            if (!empty($sLinks)) {
                $linkedObjects['single'][$testObject] = $sLinks;
            }
        }

        $linkedObjects['multi'] = $this->getMultiLinks($objectName, $objectId);

        return $linkedObjects;
    }

    /**
     * Get "single link" associations
     * when object has link as own property
     * @param mixed $objectId
     * @param string $relatedObject - related object name
     * @param array<string,string> $links - links config like
     *    array(
     *        'field1'=>'object',
     *        'field2'=>'multi'
     *        ...
     *        'fieldN'=>'object',
     *  )
     * @return array<mixed>
     */
     protected function getSingleLinks($objectId, $relatedObject, $links): array
    {
        $relatedConfig = $this->orm->config($relatedObject);
        $relatedObjectModel = $this->orm->model($relatedObject);
        $fields = [];

        foreach ($links as $field => $type) {
            if ($type !== 'object') {
                continue;
            }

            $fields[] = $field;
        }

        if (empty($fields)) {
            return [];
        }

        $db = $relatedObjectModel->getDbConnection();
        $sql = $db->select()->from($relatedObjectModel->table(), array($relatedConfig->getPrimaryKey()));
        /**
         * @var bool $first
         */
        $first = true;
        foreach ($fields as $field) {
            if ($first) {
                $sql->where($db->quoteIdentifier((string)$field) . ' =?', $objectId);
            } else {
                $sql->orWhere($db->quoteIdentifier((string)$field) . ' =?', $objectId);
                $first = false;
            }
        }
        $data = $db->fetchAll($sql);


        if (empty($data)) {
            return [];
        }

        return Utils::fetchCol($relatedConfig->getPrimaryKey(), $data);
    }

    /**
     * Get multi-link associations
     * when links stored  in external objects
     * @param string $objectName
     * @param mixed $objectId
     * @return array<int|string,mixed>
     */
     protected function getMultiLinks($objectName, $objectId): array
    {
        $ormConfig = $this->configStorage->get('orm.php');
        $linksModel = $this->orm->model($ormConfig->get('links_object'));
        $db = $linksModel->getDbConnection();
        $linkTable = $linksModel->table();

        $sql = $db->select()
            ->from($linkTable, array('id' => 'src_id', 'object' => 'src'))
            ->where('`target` =?', $objectName)
            ->where('`target_id` =?', $objectId);
        $links = $db->fetchAll($sql);

        $data = [];

        if (!empty($links)) {
            foreach ($links as $record) {
                $data[$record['object']][] = $record['id'];
            }
        }

        return $data;
    }

    /**
     * Check if Object has associated objects
     * @param string $objectName
     * @return array<int,array> - associations
     */
     public function getAssociatedStructures(string $objectName) : array
    {
        $objectName = strtolower($objectName);

        $this->buildAssociations();

        if (empty(self::$objectAssociations)) {
            return [];
        }

        $associations = [];

        foreach (self::$objectAssociations as $object => $data) {
            if (empty($data)) {
                continue;
            }

            foreach ($data as $oName => $fields) {
                if ($oName !== $objectName) {
                    continue;
                }

                $associations[] = array(
                    'object' => $object,
                    'fields' => $fields
                );
            }
        }
        return $associations;
    }
}