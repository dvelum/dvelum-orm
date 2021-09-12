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

use Dvelum\App\Session\User;
use Dvelum\Orm\Model;
use Dvelum\Orm\Orm;
use Dvelum\Orm\RecordInterface;
use Dvelum\Orm\Exception;
use Dvelum\Utils;
use Psr\Log\LogLevel;

class DataModel
{
    private Orm $orm;

    public function __construct(Orm $orm)
    {
        $this->orm = $orm;
    }

    /**
     * @param RecordInterface $record
     * @return array<int|string,mixed>
     * @throws Exception
     */
    public function load(RecordInterface $record): array
    {
        $recordName = $record->getName();
        $recordId = $record->getId();
        $recordConfig = $record->getConfig();
        $model = $this->orm->model($recordName);

        $store = $model->getStore();
        if (empty($recordId)) {
            throw new Exception('Undefined object id for ' . $recordName);
        }

        $data = $store->load($recordName, $recordId);

        if (empty($data)) {
            throw new Exception('Cannot find object ' . $recordName . ':' . $recordId);
        }

        $links = $recordConfig->getLinks([Config::LINK_OBJECT_LIST]);

        if (!empty($links)) {
            foreach ($links as $fields) {
                foreach ($fields as $field => $linkType) {
                    if ($recordConfig->getField($field)->isManyToManyLink()) {
                        $relationsObject = $recordConfig->getRelationsObject($field);
                        $relationsData = $this->orm->model((string)$relationsObject)->query()
                            ->params(['sort' => 'order_no', 'dir' => 'ASC'])
                            ->filters(['source_id' => $recordId])
                            ->fields(['target_id'])->fetchAll();
                    } else {
                        $linkedObject = $recordConfig->getField($field)->getLinkedObject();
                        $linksObject = $this->orm->model((string)$linkedObject)->getStore()->getLinksObjectName();
                        $linksModel = $this->orm->model($linksObject);
                        $relationsData = $linksModel->query()
                            ->params(['sort' => 'order', 'dir' => 'ASC'])
                            ->filters([
                                          'src' => $recordName,
                                          'src_id' => $recordId,
                                          'src_field' => $field,
                                          'target' => $linkedObject
                                      ])
                            ->fields(['target_id'])
                            ->fetchAll();
                    }
                    if (!empty($relationsData)) {
                        $data[$field] = Utils::fetchCol('target_id', $relationsData);
                    }
                }
            }
        }
        return $data;
    }

    /**
     * @param RecordInterface $record
     * @param bool $useTransaction
     * @return bool
     * @throws Exception
     */
    public function save(RecordInterface $record, bool $useTransaction): bool
    {
        $recordName = $record->getName();
        $recordConfig = $record->getConfig();
        $model = $this->orm->model($recordName);
        $log = $model->getLogsAdapter();
        $store = $model->getStore();

        if ($log) {
            $store->setLog($log);
        }

        if ($recordConfig->isReadOnly()) {
            $message = (new ErrorMessage())->readOnly($record);
            $record->addErrorMessage($message);
            if ($log) {
                $log->log(LogLevel::ERROR, $message);
            }
            return false;
        }

        if ($recordConfig->hasEncrypted()) {
            $ivField = $recordConfig->getIvField();
            $ivData = $record->get($ivField);
            if (empty($ivData)) {
                $record->set($ivField, $recordConfig->getCryptService()->createVector());
            }
        }

        /**
         * @todo Remove dependency on user object
         */
        if ($recordConfig->isRevControl()) {
            if (!$record->getId()) {
                $record->set('date_created', date('Y-m-d H:i:s'));
                $record->set('date_updated', date('Y-m-d H:i:s'));
                $record->set('published', false);
                $record->set('author_id', User::factory()->getId());
            } else {
                $record->set('date_updated', date('Y-m-d H:i:s'));
                $record->set('editor_id', User::factory()->getId());
            }
        }

        $emptyFields = $this->getEmptyRequired($record);

        if (!empty($emptyFields)) {
            $message = (new ErrorMessage())->emptyFields($record, $emptyFields);
            $record->addErrorMessage($message);
            if ($log) {
                $log->log(LogLevel::ERROR, $message);
            }
            return false;
        }

        $values = $record->validateUniqueValues();

        if (!empty($values)) {
            foreach ($values as $field => $value) {
                $message = (new ErrorMessage())->uniqueValue((string)$field, $record->get((string)$field));
                $record->addErrorMessage($message);
            }

            if ($log) {
                $log->log(LogLevel::ERROR, implode(', ', $record->getErrors()));
            }
            return false;
        }

        try {
            if (!$record->getId()) {
                if (!$store->insert($record, $useTransaction)) {
                    return false;
                }
            } else {
                if (!$store->update($record, $useTransaction)) {
                    return false;
                }
            }
            $record->commitChanges();
        } catch (\Exception $e) {
            $message = $e->getMessage();
            $record->addErrorMessage($message);
            if ($log) {
                $log->log(LogLevel::ERROR, $message);
            }
            return false;
        }
        return true;
    }

    /**
     * Check for empty required fields
     * @param RecordInterface $record
     * @return array<string>
     * @throws \Exception
     */
    protected function getEmptyRequired(RecordInterface $record): array
    {
        $emptyFields = [];
        $fields = $record->getFields();
        $config = $record->getConfig();

        foreach ($fields as $name) {
            $field = $config->getField($name);
            if (!$field->isRequired() || $field->isSystem()) {
                continue;
            }

            $val = $record->get($name);
            if (!strlen((string)$val)) {
                $emptyFields[] = $name;
            }
        }

        if (empty($emptyFields)) {
            return [];
        }
        return $emptyFields;
    }

    /**
     * @param RecordInterface $record
     * @param bool $useTransaction
     * @return bool
     * @throws Exception
     */
    public function saveVersion(RecordInterface $record, bool $useTransaction = true): bool
    {
        $recordName = $record->getName();
        $recordConfig = $record->getConfig();
        $model = $this->orm->model($recordName);
        $log = $model->getLogsAdapter();
        $store = $model->getStore();


        if ($recordConfig->hasEncrypted()) {
            $ivField = $recordConfig->getIvField();
            $ivData = $record->get($ivField);
            if (empty($ivData)) {
                $record->set($ivField, $recordConfig->getCryptService()->createVector());
            }
        }

        if (!$record->getId()) {
            if (!$this->save($record, $useTransaction)) {
                return false;
            }
        }

        $record->set('date_updated', date('Y-m-d H:i:s'));
        $record->set('editor_id', User::factory()->getId());

        if ($log) {
            $store->setLog($log);
        }

        $version = $store->addVersion($record, $useTransaction);
        if ($version) {
            $record->setVersion($version);
            $record->commitChanges();
            return true;
        }
        return false;
    }


    public function unpublish(RecordInterface $record, bool $useTransaction): bool
    {
        $recordName = $record->getName();
        $model = $this->orm->model($recordName);
        $log = $model->getLogsAdapter();
        $store = $model->getStore();

        if ($log) {
            $store->setLog($log);
        }

        /**
         * @todo refactor
         */
        $record->setValues(
            [
                'published_version' => 0,
                'published' => false,
                'date_updated' => date('Y-m-d H:i:s'),
                'editor_id' => User::factory()->getId()
            ]
        );

        return $store->unpublish($record, $useTransaction);
    }

    /**
     * Load record version
     * @param RecordInterface $record
     * @param int $vers
     * @return bool
     * @throws \Exception
     */
    public function loadVersion(RecordInterface $record, int $vers): bool
    {
        $recordName = $record->getName();
        $model = $this->orm->model($recordName);
        $recordConfig = $record->getConfig();
        $log = $model->getLogsAdapter();

        $record->rejectChanges();
        $versionObject = $model->getStore()->getVersionObjectName();

        $recordId = $record->getId();
        if (!$recordId) {
            return false;
        }
        /**
         * @var int $recordId
         */

        /**
         * @var \Dvelum\App\Model\Vc $vc
         */
        $vc = $this->orm->model($versionObject);
        $data = $vc->getData($record->getName(), $recordId, $vers);
        $pKey = $recordConfig->getPrimaryKey();

        if (isset($data[$pKey])) {
            unset($data[$pKey]);
        }

        if (empty($data)) {
            $message = (new ErrorMessage())->cantLoadVersion($record, $vers);
            $record->addErrorMessage($message);
            if ($log) {
                $log->log(LogLevel::ERROR, $message);
            }
            return false;
        }

        $iv = false;
        if ($recordConfig->hasEncrypted()) {
            $ivField = $recordConfig->getIvField();
            if (isset($data[$ivField]) && !empty($data[$ivField])) {
                $iv = $data[$ivField];
            }
        }

        foreach ($data as $k => $v) {
            if ($record->fieldExists((string)$k)) {
                try {
                    if ($recordConfig->getField((string)$k)->isEncrypted()) {
                        $v = (string)$v;
                        if (is_string($iv) && strlen($v) && strlen($iv)) {
                            $v = $recordConfig->getCryptService()->decrypt($v, $iv);
                        }
                    }

                    if ($k !== $recordConfig->getPrimaryKey() && !$recordConfig->isVcField((string)$k)) {
                        $record->set((string)$k, $v);
                    }
                } catch (Exception $e) {
                    $message = (new ErrorMessage())->cantLoadVersionIncompatible($record, $vers, $e->getMessage());
                    $record->addErrorMessage($message);
                    if ($log) {
                        $log->log(LogLevel::ERROR, $message);
                    }
                    return false;
                }
            }
        }
        $record->setVersion($vers);
        return true;
    }

    /**
     * Publish record version
     * @param RecordInterface $record
     * @param int|null $version
     * @param bool $useTransaction
     * @return bool
     * @throws \Exception
     */
    public function publish(RecordInterface $record, ?int $version, bool $useTransaction): bool
    {
        $recordName = $record->getName();
        $model = $this->orm->model($recordName);
        $log = $model->getLogsAdapter();
        $store = $model->getStore();


        if ($log) {
            $store->setLog($log);
        }

        if (!empty($version) && $version !== $record->getVersion()) {
            if (!$this->loadVersion($record, $version)) {
                return false;
            }
        }
        /**
         * @todo refactor
         */
        $record->setValues(
            [
                'published' => true,
                'date_updated' => date('Y-m-d H:i:s'),
                'editor_id' => User::factory()->getId(),
                'published_version' => $record->getVersion()
            ]
        );

        if (empty($record->get('date_published'))) {
            $record->set('date_published', date('Y-m-d H:i:s'));
        }

        return $store->publish($record, $useTransaction);
    }

    /**
     * Delete record
     * @param RecordInterface $record
     * @param bool $useTransaction
     * @return bool
     * @throws \Exception
     */
    public function delete(RecordInterface $record, bool $useTransaction): bool
    {
        $recordName = $record->getName();
        $model = $this->orm->model($recordName);
        $log = $model->getLogsAdapter();
        $store = $model->getStore();

        if ($log) {
            $store->setLog($log);
        }

        return $store->delete($record, $useTransaction);
    }

    /**
     * @param RecordInterface $record
     * @param array<string,mixed> $uniqGroups
     * @return array<string,mixed>|null
     * @throws Exception
     */
    public function validateUniqueValues(RecordInterface $record, array $uniqGroups): ?array
    {
        $recordName = $record->getName();
        $model = $this->orm->model($recordName);
        $store = $model->getStore();
        return $store->validateUniqueValues($record->getName(), $record->getId(), $uniqGroups);
    }
}
