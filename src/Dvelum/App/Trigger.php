<?php

/**
 *  DVelum project https://github.com/dvelum/dvelum , https://github.com/k-samuel/dvelum , http://dvelum.net
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
 *
 */

declare(strict_types=1);

namespace Dvelum\App;

use Dvelum\App\Model\Historylog;
use Dvelum\Config;
use Dvelum\Orm;
use Dvelum\App\Session\User;
use Dvelum\Cache\CacheInterface;

/**
 * Default Trigger
 * Handle ORM Record Events
 */
class Trigger
{
    /**
     * @var Config\ConfigInterface<string,mixed> $ormConfig
     */
    protected Config\ConfigInterface $ormConfig;

    /**
     * @var Config\ConfigInterface<string,mixed> $appConfig
     */
    protected Config\ConfigInterface $appConfig;

    protected Orm\Orm $orm;
    protected Config\Storage\StorageInterface $storage;

    public function __construct(Orm\Orm $orm, Config\Storage\StorageInterface $storage)
    {
        $this->storage = $storage;
        $this->orm = $orm;
        $this->appConfig = $storage->get('main.php');
        $this->ormConfig = $storage->get('orm.php');
    }

    /**
     * @var CacheInterface | null
     */
    protected ?CacheInterface $cache = null;

    public function setCache(?CacheInterface $cache) : void
    {
        $this->cache = $cache;
    }

    protected function getItemCacheKey(Orm\RecordInterface $object) : string
    {
        $objectModel = $this->orm->model($object->getName());
        return $objectModel->getCacheKey(array('item', $object->getId()));
    }

    public function onBeforeAdd(Orm\RecordInterface $object) : void
    {
    }

    public function onBeforeUpdate(Orm\RecordInterface $object): void
    {
    }

    public function onBeforeDelete(Orm\RecordInterface $object): void
    {
    }

    public function onAfterAdd(Orm\RecordInterface $object): void
    {
        $config = $object->getConfig();
        $logObject = $this->ormConfig->get('history_object');
        /**
         * @var Historylog $model
         */
        $model = $this->orm->model($logObject);

        if ($config->hasHistory()) {
            if ($config->hasExtendedHistory()) {
                $model->saveState(
                    Historylog::Create,
                    $object->getName(),
                    $object->getId(),
                    User::factory()->getId(),
                    date('Y-m-d H:i:s'),
                    null,
                    json_encode($object->getData())
                );
            } else {
                $model->log(
                    User::factory()->getId(),
                    $object->getId(),
                    Historylog::Create,
                    $object->getName()
                );
            }
        }

        if (!$this->cache) {
            return;
        }

        $this->cache->remove($this->getItemCacheKey($object));
    }

    public function onAfterUpdate(Orm\RecordInterface $object): void
    {
        if (!$this->cache) {
            return;
        }

        $this->cache->remove($this->getItemCacheKey($object));
    }

    public function onAfterDelete(Orm\RecordInterface $object): void
    {
        $config = $object->getConfig();
        $logObject = $this->ormConfig->get('history_object');
        /**
         * @var Historylog $model
         */
        $model = $this->orm->model($logObject);

        if ($object->getConfig()->hasHistory()) {
            if ($config->hasExtendedHistory()) {
                $model->saveState(
                    Historylog::Delete,
                    $object->getName(),
                    $object->getId(),
                    User::factory()->getId(),
                    date('Y-m-d H:i:s'),
                    json_encode($object->getData()),
                    null
                );
            } else {
                $model->log(
                    User::factory()->getId(),
                    $object->getId(),
                    Historylog::Delete,
                    $object->getName()
                );
            }
        }

        if (!$this->cache) {
            return;
        }

        $this->cache->remove($this->getItemCacheKey($object));
    }

    public function onAfterUpdateBeforeCommit(Orm\RecordInterface $object): void
    {
        $config = $object->getConfig();
        $logObject = $this->ormConfig->get('history_object');
        /**
         * @var Historylog $model
         */
        $model = $this->orm->model($logObject);
        if ($object->getConfig()->hasHistory() && $object->hasUpdates()) {
            $before = $object->getData(false);
            $after = $object->getUpdates();

            foreach ($before as $field => $value) {
                if (!array_key_exists($field, $after)) {
                    unset($before[$field]);
                }
            }

            if ($config->hasExtendedHistory()) {
                $model->saveState(
                    Historylog::Update,
                    $object->getName(),
                    $object->getId(),
                    User::factory()->getId(),
                    date('Y-m-d H:i:s'),
                    json_encode($before),
                    json_encode($after)
                );
            } else {
                $model->log(
                    User::factory()->getId(),
                    $object->getId(),
                    Historylog::Update,
                    $object->getName()
                );
            }
        }
    }

    public function onAfterPublish(Orm\RecordInterface $object): void
    {
        $logObject = $this->ormConfig->get('history_object');

        if ($object->getConfig()->hasHistory()) {
            /**
             * @var Historylog $model
             */
            $model = $this->orm->model($logObject);
            $model->log(
                User::factory()->getId(),
                $object->getId(),
                Historylog::Publish,
                $object->getName()
            );
        }
    }

    public function onAfterUnpublish(Orm\RecordInterface $object):void
    {
        if (!$object->getConfig()->hasHistory()) {
            return;
        }

        $logObject = $this->ormConfig->get('history_object');
        /**
         * @var Historylog $model
         */
        $model = $this->orm->model($logObject);
        $model->log(
            User::factory()->getId(),
            $object->getId(),
            Historylog::Unpublish,
            $object->getName()
        );
    }

    public function onAfterAddVersion(Orm\RecordInterface $object):void
    {
        if (!$object->getConfig()->hasHistory()) {
            return;
        }

        $logObject = $this->ormConfig->get('history_object');
        /**
         * @var Historylog $model
         */
        $model = $this->orm->model($logObject);
        $model->log(
            User::factory()->getId(),
            $object->getId(),
            Historylog::NewVersion,
            $object->getName()
        );
    }

    public function onAfterInsertBeforeCommit(Orm\RecordInterface $object):void
    {
    }

    public function onAfterDeleteBeforeCommit(Orm\RecordInterface $object):void
    {
    }
}