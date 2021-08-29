<?php
/**
 *  DVelum project https://github.com/dvelum/dvelum
 *  Copyright (C) 2011-2017  Kirill Yegorov
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

declare(strict_types=1);

namespace Dvelum\App\Orm\Data\Api;

use Dvelum\App\Router\RouterInterface;
use Dvelum\Config\ConfigInterface;
use Dvelum\Config\Storage\StorageInterface;
use Dvelum\Designer\Manager;
use Dvelum\Lang;
use Dvelum\Request;
use Dvelum\Response;
use Dvelum\Config;
use Dvelum\App;
use Dvelum\Orm;
use Dvelum\Service;
use Dvelum\Utils;
use Psr\Container\ContainerInterface;
use Dvelum\Orm\RecordInterface;
use Dvelum\Orm\Model;
use Dvelum\Orm\Record;
use Dvelum\App\Orm\Data;
use Dvelum\App\Dictionary;
use Dvelum\App\Controller\EventManager;

use Exception;

abstract class Controller
{
    protected Request $request;
    protected Response $response;
    protected ContainerInterface $container;
    protected RouterInterface $router;
    protected \Dvelum\Orm\Orm $orm;
    protected \Dvelum\Lang\Dictionary $lang;
    protected bool $canEdit;
    protected bool $canDelete;
    protected StorageInterface $configStorage;


    /**
     * List of ORM object field names displayed in the main list (listAction)
     * They may be assigned a value, as well as an array
     * Empty value means all fields will be fetched from DB, except long text fields
     */
    protected $listFields = [];
    /**
     * List of ORM objects accepted via linkedListAction and otitleAction
     * @var array
     */
    protected $canViewObjects = [];
    /**
     * List of ORM object link fields displayed with related values in the main list (listAction)
     * (dictionary, object link, object list) key - result field, value - object field
     * object field will be used as result field for numeric keys
     * Requires primary key in result set
     * @var array
     */
    protected $listLinks = [];
    /**
     * Controller events manager
     * @var App\Controller\EventManager
     */
    protected $eventManager;

    /**
     * API Request object
     * @var \Dvelum\App\Orm\Data\Api\Request
     */
    protected $apiRequest;

    /**
     * Object titles separator
     * @var string $linkedInfoSeparator
     */
    protected $linkedInfoSeparator = '; ';


    protected string $objectName;

    public function __construct(
        Request $request,
        Response $response,
        ContainerInterface $container,
        bool $canEdit = true,
        bool $canDelete = true
    ) {
        $this->request = $request;
        $this->response = $response;
        $this->container = $container;
        $this->orm = $container->get(Orm\Orm::class);
        $this->lang = $container->get(Lang::class)->getDictionary();
        $this->canEdit = $canEdit;
        $this->canDelete = $canDelete;
        $this->configStorage = $container->get(StorageInterface::class);

        $this->apiRequest = $this->getApiRequest($this->request);
        $this->eventManager = new App\Controller\EventManager();
        $this->canViewObjects = \array_map('strtolower', $this->canViewObjects);

        $this->objectName = $this->getObjectName();

        /*
         * @todo remove bakward compat
         */
        \Dvelum\Orm::setContainer($container);

        $this->initListeners();
    }

    abstract public function getObjectName() : string;

    public function setRouter(RouterInterface $router): void
    {
        $this->router = $router;
    }

    public function checkCanEdit(): bool
    {
        return $this->canEdit;
    }

    public function checkCanDelete(): bool
    {
        return $this->canDelete;
    }

    public function checkCanPublish(): bool
    {
        // simplified for development mode
        return $this->canEdit;
    }

    /**
     *  Event listeners can be defined here
     */
    public function initListeners()
    {
    }


    protected function getApi(Data\Api\Request $request): Data\Api
    {
        $api = new App\Orm\Data\Api($request, $this->orm);
        if (!empty($this->listFields)) {
            $api->setFields($this->listFields);
        }
        return $api;
    }

    protected function getApiRequest(Request $request): Data\Api\Request
    {
        $request = new \Dvelum\App\Orm\Data\Api\Request($request);
        $request->setObjectName($this->getObjectName());
        return $request;
    }

    /**
     * Get list of objects which can be linked
     * @throws Exception
     */
    public function linkedListAction()
    {
        if (!$this->eventManager->fireEvent(EventManager::BEFORE_LINKED_LIST, new \stdClass())) {
            $this->response->error($this->eventManager->getError());
            return;
        }

        try {
            $result = $this->getLinkedList();
        } catch (LoadException $e) {
            $this->response->error($e->getMessage());
            return;
        } catch (Exception $e) {
            $this->response->error($this->lang->get('CANT_EXEC'));
            return;
        }


        $eventData = new \stdClass();
        $eventData->data = $result['data'];
        $eventData->count = $result['count'];

        if (!$this->eventManager->fireEvent(EventManager::AFTER_LINKED_LIST, $eventData)) {
            $this->response->error($this->eventManager->getError());
            return;
        }

        $this->response->success($eventData->data, ['count' => $eventData->count]);
    }

    /**
     * @return array
     * @throws Exception|LoadException
     */
    public function getLinkedList(): array
    {
        $object = $this->request->post('object', 'string', false);
        $filter = $this->request->post('filter', 'array', []);
        $pager = $this->request->post('pager', 'array', []);
        $query = $this->request->post('search', 'string', null);

        $filter = array_merge($filter, $this->request->extFilters());

        if ($object === false || !$this->orm->configExists($object)) {
            throw new LoadException($this->lang->get('WRONG_REQUEST'));
        }

        if (!in_array(strtolower($object), $this->canViewObjects, true)) {
            throw new LoadException($this->lang->get('CANT_VIEW'));
        }

        $objectCfg = $this->orm->config($object);
        $primaryKey = $objectCfg->getPrimaryKey();

        if (isset($pager['sort'])) {
            if ($pager['sort'] === 'title') {
                $linkTitle = $objectCfg->getLinkTitle();
            } else {
                $linkTitle = $pager['sort'];
            }

            if ($objectCfg->fieldExists($linkTitle)) {
                $pager['sort'] = $linkTitle;
            } else {
                unset($pager['sort']);
            }
        }

        $objectConfig = $this->orm->config($object);

        /**
         * @var Model
         */
        $model = $this->orm->model($object);
        $rc = $objectCfg->isRevControl();

        if ($objectCfg->isRevControl()) {
            $fields = ['id' => $primaryKey, 'published'];
        } else {
            $fields = ['id' => $primaryKey];
        }


        $dataModel = $model;
        if ($objectConfig->isDistributed()) {
            $dataModel = $this->orm->model($objectConfig->getDistributedIndexObject());
        }

        $count = $dataModel->query()->search($query)->getCount();
        $data = [];

        if ($count) {
            $data = $dataModel->query()->filters($filter)->params($pager)->fields($fields)->search($query)->fetchAll();

            if (!empty($data)) {
                $objectIds = Utils::fetchCol('id', $data);

                try {
                    $objects = $this->orm->record($object, $objectIds);
                } catch (\Exception $e) {
                    $this->orm->model($object)->logError('linkedlistAction ->' . $e->getMessage());
                    throw new LoadException($this->lang->get('CANT_EXEC'));
                }

                foreach ($data as &$item) {
                    if (!$rc) {
                        $item['published'] = true;
                    }

                    $item['deleted'] = false;

                    if (isset($objects[$item['id']])) {
                        /**
                         * @var Orm\Record $o
                         */
                        $o = $objects[$item['id']];
                        $item['title'] = $o->getTitle();
                        if ($rc) {
                            $item['published'] = $o->get('published');
                        }
                    } else {
                        $item['title'] = $item['id'];
                    }
                }
                unset($item);
            }
        }
        return ['data' => $data, 'count' => $count];
    }

    /**
     * @deprecated
     */
    public function oTitleAction()
    {
        $this->objectTitleAction();
    }

    /**
     * Get object title
     * @throws Exception
     */
    public function objectTitleAction()
    {
        $object = $this->request->post('object', 'string', false);
        $id = $this->request->post('id', 'string', false);

        if (!$object || !$this->orm->configExists($object)) {
            $this->response->error($this->lang->get('WRONG_REQUEST'));
            return;
        }

        if (!in_array(strtolower($object), $this->canViewObjects, true)) {
            $this->response->error($this->lang->get('CANT_VIEW'));
            return;
        }

        try {
            /**
             * @var RecordInterface $o
             */
            $o = $this->orm->record($object, $id);
            $this->response->success(['title' => $o->getTitle()]);
        } catch (\Exception $e) {
            $this->orm->model($object)->logError('Cannot get title for ' . $object . ':' . $id);
            $this->response->error($this->lang->get('CANT_EXEC'));
            return;
        }
    }

    /**
     * Get list of items. Returns JSON reply with
     * ORM object field data or return array with data and count;
     * Filtering, pagination and search are available
     * Sends JSON reply in the result
     * and closes the application (by default).
     * @return void
     * @throws \Exception
     */
    public function listAction()
    {
        if (!$this->eventManager->fireEvent(EventManager::BEFORE_LIST, new \stdClass())) {
            $this->response->error($this->eventManager->getError());
            return;
        }

        $result = $this->getList();

        $eventData = new \stdClass();
        $eventData->data = $result['data'];
        $eventData->count = $result['count'];

        if (!$this->eventManager->fireEvent(EventManager::AFTER_LIST, $eventData)) {
            $this->response->error($this->eventManager->getError());
            return;
        }

        $this->response->success($eventData->data, ['count' => $eventData->count]);
    }

    /**
     * Prepare data for listAction
     * backward compatibility
     * @return array
     * @throws \Exception
     */
    protected function getList()
    {
        $api = $this->getApi($this->apiRequest);

        $count = $api->getCount();

        if (!$count) {
            return ['data' => [], 'count' => 0];
        }

        $data = $api->getList();

        if (!empty($this->listLinks)) {
            $objectConfig = $this->orm->config($this->objectName);
            if (empty($objectConfig->getPrimaryKey())) {
                throw new \Exception('listLinks requires primary key for object ' . $objectConfig->getName());
            }
            $this->addLinkedInfo($objectConfig, $this->listLinks, $data, $objectConfig->getPrimaryKey());
        }

        return ['data' => $data, 'count' => $count];
    }


    /**
     * Create/edit object data
     * The type of operation is defined as per the parameters being transferred
     * Sends JSON reply in the result and
     * closes the application
     */
    public function editAction()
    {
        $id = $this->request->post('id', 'integer', false);
        if (!$id) {
            $this->createAction();
        } else {
            $this->updateAction();
        }
    }

    /**
     * Create object
     * Sends JSON reply in the result and
     * closes the application
     */
    public function createAction()
    {
        if (!$this->checkCanEdit()) {
            return;
        }

        $object = $this->getPostedData($this->objectName);

        if (empty($object)) {
            return;
        }
        $this->insertObject($object);
    }

    /**
     * Update object data
     * Sends JSON reply in the result and
     * closes the application
     */
    public function updateAction()
    {
        if (!$this->checkCanEdit()) {
            return;
        }

        $object = $this->getPostedData($this->objectName);
        if (empty($object)) {
            return;
        }
        $this->updateObject($object);
    }

    /**
     * Delete object
     * Sends JSON reply in the result and
     * closes the application
     */
    public function deleteAction()
    {
        if (!$this->checkCanDelete()) {
            return;
        }
        $id = $this->request->post('id', 'integer', false);
        $shard = $this->request->post('shard', 'string', null);

        if (!$id) {
            $this->response->error($this->lang->get('WRONG_REQUEST'));
            return;
        }

        try {
            /**
             * @var Orm\RecordInterface $object
             */
            $object = $this->orm->record($this->objectName, $id, $shard);
        } catch (\Exception $e) {
            $this->response->error($this->lang->get('WRONG_REQUEST'));
            return;
        }

        if ($object->getConfig()->isRevControl() && !$this->checkOwner($object)) {
            $this->response->error($this->lang->get('CANT_DELETE'));
            return;
        }


        $ormConfig = $this->configStorage->get('orm.php');

        if ($ormConfig->get('vc_clear_on_delete')) {
            /**
             * @var App\Model\Vc $vcModel
             */
            $vcModel = $this->orm->model('Vc');
            $vcModel->removeItemVc($this->objectName, $id);
        }

        if (!$object->delete()) {
            $this->response->error($this->lang->get('CANT_EXEC'));
            return;
        }

        $this->response->success();
    }

    /**
     * Save new ORM object (insert data)
     * Sends JSON reply in the result and
     * closes the application
     * @param RecordInterface $object
     * @return void
     */
    public function insertObject(RecordInterface $object)
    {
        $objectConfig = $object->getConfig();
        $isRevControl = $objectConfig->isRevControl();

        if ($isRevControl) {
            $author = $object->get('author_id');
            if (empty($author)) {
                $object->set('author_id', $this->user->getId());
            } else {
                if (!$this->checkOwner($object)) {
                    $this->response->error($this->lang->get('CANT_ACCESS'));
                    return;
                }
            }
        }
        $objectModel = Model::factory($object->getName());
        $db = $objectModel->getDbConnection();
        $db->beginTransaction();

        $result = [];

        if ($isRevControl) {
            if (!$object->saveVersion(false)) {
                $this->response->error($this->lang->get('CANT_CREATE'));
                $db->rollback();
                return;
            }

            $stagingUrl = $this->getStagingUrl($object);

            $result = [
                'id' => $object->getId(),
                'version' => $object->getVersion(),
                'published' => $object->get('published'),
                'staging_url' => $stagingUrl
            ];
        } else {
            if (!$recId = $object->save(false)) {
                $this->response->error($this->lang->get('CANT_EXEC'));
                $db->rollback();
                return;
            }
            $result = ['id' => $recId];
        }

        if ($objectConfig->isShardRequired()) {
            /**
             * @var Orm\Distributed\Record $object
             */
            $result['shard'] = $object->getShard();
        }

        $eventData = new \stdClass();
        $eventData->object = $object;

        if (!$this->eventManager->fireEvent(EventManager::AFTER_UPDATE_BEFORE_COMMIT, $eventData)) {
            $this->response->error($this->eventManager->getError());
            $db->rollback();
            return;
        }

        $db->commit();
        $this->response->success($result);
    }

    /**
     * Update ORM object data
     * Sends JSON reply in the result and
     * closes the application
     * @param RecordInterface $object
     */
    public function updateObject(RecordInterface $object)
    {
        $objectConfig = $object->getConfig();
        $isRevControl = $objectConfig->isRevControl();

        if ($isRevControl) {
            $author = $object->get('author_id');
            if (empty($author)) {
                $object->set('author_id', $this->user->getId());
            } else {
                if (!$this->checkOwner($object)) {
                    $this->response->error($this->lang->get('CANT_ACCESS'));
                    return;
                }
            }
        }

        $objectModel = Model::factory($object->getName());
        $db = $objectModel->getDbConnection();
        $db->beginTransaction();

        $result = [];
        if ($isRevControl) {
            if (!$object->saveVersion(false)) {
                $this->response->error($this->lang->get('CANT_CREATE'));
                $db->rollback();
                return;
            }
            $result = [
                'id' => $object->getId(),
                'version' => $object->getVersion(),
                'staging_url' => '',
                'published_version' => $object->get('published_version'),
                'published' => $object->get('published')
            ];
        } else {
            if (!$object->save(false)) {
                $this->response->error($this->lang->get('CANT_EXEC'));
                $db->rollback();
                return;
            }
            $result = ['id' => $object->getId()];
        }

        if ($objectConfig->isShardRequired()) {
            $result['shard'] = $object->getShard();
        }

        $eventData = new \stdClass();
        $eventData->object = $object;

        if (!$this->eventManager->fireEvent(EventManager::AFTER_UPDATE_BEFORE_COMMIT, $eventData)) {
            $this->response->error($this->eventManager->getError());
            $db->rollback();
            return;
        }

        $db->commit();
        $this->response->success($result);
    }

    /**
     * Get controller configuration
     * @return ConfigInterface
     */
    protected function getConfig(): ConfigInterface
    {
        return $this->configStorage->get('backend/controller.php');
    }

    /**
     * Get posted data and put it into Orm\Record
     * (in case of failure, JSON error message is sent)
     * @param string $objectName
     * @return Record | null
     * @throws Exception
     */
    public function getPostedData($objectName): ?Record
    {
        $formCfg = $this->getConfig()->get('form');
        $adapterConfig = $this->configStorage->get($formCfg['config']);
        $adapterConfig->set('orm_object', $objectName);
        /**
         * @var \Dvelum\App\Form\Adapter $form
         */
        $form = new $formCfg['adapter']($this->request, $this->lang, $adapterConfig);
        if (!$form->validateRequest()) {
            $errors = $form->getErrors();
            $formMessages = [$this->lang->get('FILL_FORM')];
            $fieldMessages = [];
            /**
             * @var \Dvelum\App\Form\Error $item
             */
            foreach ($errors as $item) {
                $field = $item->getField();
                if (empty($field)) {
                    $formMessages[] = $item->getMessage();
                } else {
                    $fieldMessages[$field] = $item->getMessage();
                }
            }
            $this->response->error(implode('; <br>', $formMessages), $fieldMessages);
            return null;
        }
        return $form->getData();
    }

    /**
     * Get ORM object data
     * Sends a JSON reply in the result and
     * closes the application
     */
    public function loadDataAction()
    {
        if (!$this->eventManager->fireEvent(EventManager::BEFORE_LOAD, new \stdClass())) {
            $this->response->error($this->eventManager->getError());
            return;
        }
        try {
            $result = $this->getData();
        } catch (OwnerException $e) {
            $this->response->error($this->lang->get('CANT_ACCESS'));
            return;
        } catch (LoadException $e) {
            $this->response->error($this->lang->get('CANT_LOAD'));
            return;
        } catch (\Exception $e) {
            $this->response->error($this->lang->get('CANT_EXEC'));
            return;
        }

        $eventData = new \stdClass();
        $eventData->data = $result;


        if (!$this->eventManager->fireEvent(EventManager::AFTER_LOAD, $eventData)) {
            $this->response->error($this->eventManager->getError());
            return;
        }

        if (empty($eventData->data)) {
            $this->response->error($this->lang->get('CANT_EXEC'));
            return;
        } else {
            $this->response->success($eventData->data);
            return;
        }
    }

    /**
     * Prepare data for loadDataAction
     * @return array
     * @throws \Exception
     */
    protected function getData()
    {
        $id = $this->request->post('id', 'int', false);
        $objectName = $this->getObjectName();
        $objectConfig = $this->orm->config($objectName);
        $shard = false;
        if ($objectConfig->isShardRequired()) {
            $shard = $this->request->post('shard', 'string', '');
            if (empty($shard)) {
                return [];
            }
        }

        if (!$id) {
            return [];
        }

        try {
            /**
             * @var Orm\Record $obj
             */
            $obj = $this->orm->record($objectName, $id, $shard);
        } catch (\Exception $e) {
            $this->orm->model($objectName)->logError($e->getMessage());
            return [];
        }

        if ($objectConfig->isRevControl()) {
            if (!$this->checkOwner($obj)) {
                throw new OwnerException($this->lang->get('CANT_ACCESS'));
            }
            /**
             * @var App\Model\Vc $vc
             */
            $vc = $this->orm->model('Vc');
            $version = $this->request->post('version', 'int', 0);
            if (!$version) {
                $version = $vc->getLastVersion($objectName, $id);
            }

            try {
                $obj->loadVersion($version);
            } catch (\Exception $e) {
                $this->orm->model($objectName)->logError(
                    'Cannot load version ' . $version . ' for ' . $objectName . ':' . $obj->getId()
                );
                throw new LoadException($e->getMessage());
            }

            $data = $obj->getData();
            $data['id'] = $id;
            $data['version'] = $version;
            $data['published'] = $obj->get('published');
            $data['staging_url'] = $this->getStagingUrl($obj);
        } else {
            $data = $obj->getData();
            $data['id'] = $obj->getId();
        }

        /*
         * Prepare object list properties
         */
        $linkedObjects = $obj->getConfig()->getLinks([Orm\Record\Config::LINK_OBJECT_LIST]);

        foreach ($linkedObjects as $linkObject => $fieldCfg) {
            foreach ($fieldCfg as $field => $linkCfg) {
                $data[$field] = $this->collectLinksData($field, $obj, $linkObject);
            }
        }
        $data['id'] = $obj->getId();
        return $data;
    }

    /**
     * Add related objects info into getList results
     * @param Orm\Record\Config $cfg
     * @param array $fieldsToShow list of link fields to process ( key - result field, value - object field)
     * object field will be used as result field for numeric keys
     * @param array & $data rows from  Model::getList result
     * @param string $pKey - name of Primary Key field in $data
     * @throws \Exception
     */
    protected function addLinkedInfo(Orm\Record\Config $cfg, array $fieldsToShow, array &$data, $pKey)
    {
        $fieldsToKeys = [];
        foreach ($fieldsToShow as $key => $val) {
            if (is_numeric($key)) {
                $fieldsToKeys[$val] = $val;
            } else {
                $fieldsToKeys[$val] = $key;
            }
        }

        $links = $cfg->getLinks(
            [
                Orm\Record\Config::LINK_OBJECT,
                Orm\Record\Config::LINK_OBJECT_LIST,
                Orm\Record\Config::LINK_DICTIONARY
            ],
            false
        );

        foreach ($fieldsToShow as $objectField) {
            if (!isset($links[$objectField])) {
                throw new \Exception($objectField . ' is not Link');
            }
        }

        foreach ($links as $field => $config) {
            if (!isset($fieldsToKeys[$field])) {
                unset($links[$field]);
            }
        }

        $rowIds = Utils::fetchCol($pKey, $data);
        $rowObjects = $this->orm->records($cfg->getName(), $rowIds);
        $listedObjects = [];

        foreach ($rowObjects as $object) {
            foreach ($links as $field => $config) {
                if ($config['link_type'] === Orm\Record\Config::LINK_DICTIONARY) {
                    continue;
                }

                if (!isset($listedObjects[$config['object']])) {
                    $listedObjects[$config['object']] = [];
                }

                $oVal = $object->get($field);

                if (!empty($oVal)) {
                    if (!is_array($oVal)) {
                        $oVal = [$oVal];
                    }
                    $listedObjects[$config['object']] = array_merge(
                        $listedObjects[$config['object']],
                        array_values($oVal)
                    );
                }
            }
        }

        foreach ($listedObjects as $object => $ids) {
            $listedObjects[$object] = $this->orm->records($object, array_unique($ids));
        }

        /**
         * @var Dictionary\Service $dictionaryService
         */
        $dictionaryService = $this->container->get(Dictionary\Service::class);

        foreach ($data as &$row) {
            if (!isset($rowObjects[$row[$pKey]])) {
                continue;
            }

            foreach ($links as $field => $config) {
                $list = [];
                $rowObject = $rowObjects[$row[$pKey]];
                $value = $rowObject->get($field);

                if (!empty($value)) {
                    if ($config['link_type'] === Orm\Record\Config::LINK_DICTIONARY) {
                        $dictionary = $dictionaryService->get($config['object']);
                        if ($dictionary->isValidKey($value)) {
                            $row[$fieldsToKeys[$field]] = $dictionary->getValue($value);
                        }
                        continue;
                    }

                    if (!is_array($value)) {
                        $value = [$value];
                    }

                    foreach ($value as $oId) {
                        if (isset($listedObjects[$config['object']][$oId])) {
                            $list[] = $this->linkedInfoObjectRenderer(
                                $rowObject,
                                $field,
                                $listedObjects[$config['object']][$oId]
                            );
                        } else {
                            $list[] = '[' . $oId . '] (' . $this->lang->get('DELETED') . ')';
                        }
                    }
                }
                $row[$fieldsToKeys[$field]] = implode($this->linkedInfoSeparator, $list);
            }
        }
        unset($row);
    }

    /**
     * Get ready the data for fields of the ‘link to object list’ type;
     * Takes an array of identifiers as a parameter. expands the data adding object name,
     * status (deleted or not deleted), publication status for objects under
     * version control (used in child classes)
     * The provided data is necessary for the RelatedGridPanel component,
     * which is used for visual representation of relationship management.
     * @param string $fieldName
     * @param RecordInterface $object
     * @param string $targetObjectName
     * @return array
     */
    protected function collectLinksData($fieldName, RecordInterface $object, $targetObjectName)
    {
        $result = [];

        $data = $object->get($fieldName);

        if (!empty($data)) {
            /**
             * @var Orm\Record[] $list
             */
            $list = $this->orm->record($targetObjectName, $data);

            $isVc = $this->orm->config($targetObjectName)->isRevControl();
            foreach ($data as $id) {
                if (isset($list[$id])) {
                    $result[] = [
                        'id' => $id,
                        'deleted' => 0,
                        'title' => $list[$id]->getTitle(),
                        'published' => $isVc ? $list[$id]->get('published') : 1
                    ];
                } else {
                    $result[] = [
                        'id' => $id,
                        'deleted' => 1,
                        'title' => $id,
                        'published' => 0
                    ];
                }
            }
        }
        return $result;
    }

    /**
     * String representation of related object for addLinkedInfo method
     * @param RecordInterface $rowObject
     * @param string $field
     * @param RecordInterface $relatedObject
     * @return string
     */
    protected function linkedInfoObjectRenderer(RecordInterface $rowObject, $field, RecordInterface $relatedObject)
    {
        return $relatedObject->getTitle();
    }

    /**
     * Check object owner
     * @param RecordInterface $object
     * @return bool
     */
    protected function checkOwner(RecordInterface $object): bool
    {
        return true;
    }

    /**
     * Define the object data preview page URL
     * (needs to be redefined in the child class
     * as per the application structure)
     * @param RecordInterface $object
     * @return string
     */
    public function getStagingUrl(RecordInterface $object): string
    {
        return '';
    }

    /**
     * Publish object data changes
     * Sends JSON reply in the result
     * and closes the application.
     */
    public function publishAction()
    {
        $objectName = $this->getObjectName();
        $objectConfig = $this->orm->config($objectName);

        if (!$objectConfig->isRevControl()) {
            $this->response->error($this->lang->get('WRONG_REQUEST'));
            return;
        }

        if (!$this->checkCanPublish()) {
            return;
        }

        $id = $this->request->post('id', 'integer', false);
        $vers = $this->request->post('vers', 'integer', false);

        if (!$id || !$vers) {
            $this->response->error($this->lang->get('WRONG_REQUEST'));
            return;
        }

        try {
            /**
             * @var Orm\Record $object
             */
            $object = $this->orm->record($objectName, $id);
        } catch (\Exception $e) {
            $this->response->error($this->lang->get('CANT_EXEC' . ' ' . $e->getMessage()));
            return;
        }

        if (!$this->checkOwner($object)) {
            $this->response->error($this->lang->get('CANT_ACCESS'));
            return;
        }

        try {
            $object->loadVersion($vers);
        } catch (\Exception $e) {
            $this->response->error($this->lang->get('VERSION_INCOPATIBLE'));
            return;
        }

        if (!$object->publish()) {
            $this->response->error($this->lang->get('CANT_EXEC'));
            return;
        }
        $this->response->success();
    }

    /**
     * Unpublish object
     * Sends JSON reply in the result
     * and closes the application.
     */
    public function unpublishAction()
    {
        $objectName = $this->getObjectName();
        $objectConfig = $this->orm->config($objectName);

        if (!$objectConfig->isRevControl()) {
            $this->response->error($this->lang->get('WRONG_REQUEST'));
            return;
        }

        $id = $this->request->post('id', 'integer', false);

        if (!$id) {
            $this->response->error($this->lang->get('WRONG_REQUEST'));
            return;
        }

        if (!$this->checkCanPublish()) {
            return;
        }

        try {
            /**
             * @var Orm\RecordInterface $object
             */
            $object = $this->orm->record($objectName, $id);
        } catch (\Exception $e) {
            $this->response->error($this->lang->get('CANT_EXEC'));
            return;
        }

        if (!$this->checkOwner($object)) {
            return;
        }

        if ($this->unpublishObject($object)) {
            $this->response->success();
        }
    }

    /**
     * Unpublish object
     * Sends JSON reply in the result
     * and closes the application.
     * @param RecordInterface $object
     * @return bool
     */
    public function unpublishObject(RecordInterface $object): bool
    {
        if (!$object->get('published')) {
            $this->response->error($this->lang->get('NOT_PUBLISHED'));
            return false;
        }

        if (!$object->unpublish()) {
            $this->response->error($this->lang->get('CANT_EXEC'));
            return false;
        }

        return true;
    }


}
