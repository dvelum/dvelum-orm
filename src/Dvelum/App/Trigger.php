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

    public function setCache(?CacheInterface $cache): void
    {
        $this->cache = $cache;
    }

    protected function getItemCacheKey(Orm\RecordInterface $object): string
    {
        $objectModel = $this->orm->model($object->getName());
        return $objectModel->getCacheKey(array('item', $object->getId()));
    }

    public function onBeforeAdd(Orm\RecordInterface $object): void
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
                    Historylog::CREATE,
                    $object->getName(),
                    $object->getId(),
                    User::factory()->getId(),
                    date('Y-m-d H:i:s'),
                    null,
                    (string)json_encode($object->getData())
                );
            } else {
                $model->log(
                    User::factory()->getId(),
                    $object->getId(),
                    Historylog::CREATE,
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
                    Historylog::DELETE,
                    $object->getName(),
                    $object->getId(),
                    User::factory()->getId(),
                    date('Y-m-d H:i:s'),
                    (string)json_encode($object->getData()),
                    null
                );
            } else {
                $model->log(
                    User::factory()->getId(),
                    $object->getId(),
                    Historylog::DELETE,
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
                    Historylog::UPDATE,
                    $object->getName(),
                    $object->getId(),
                    User::factory()->getId(),
                    date('Y-m-d H:i:s'),
                    (string)json_encode($before),
                    (string)json_encode($after)
                );
            } else {
                $model->log(
                    User::factory()->getId(),
                    $object->getId(),
                    Historylog::UPDATE,
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
                Historylog::PUBLISH,
                $object->getName()
            );
        }
    }

    public function onAfterUnpublish(Orm\RecordInterface $object): void
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
            Historylog::UNPUBLISH,
            $object->getName()
        );
    }

    public function onAfterAddVersion(Orm\RecordInterface $object): void
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
            Historylog::NEW_VERSION,
            $object->getName()
        );
    }

    public function onAfterInsertBeforeCommit(Orm\RecordInterface $object): void
    {
    }

    public function onAfterDeleteBeforeCommit(Orm\RecordInterface $object): void
    {
    }
}
