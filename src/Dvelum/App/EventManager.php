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

use Dvelum\Config\Storage\StorageInterface;
use Dvelum\Orm;
use Dvelum\Cache\CacheInterface;
use Dvelum\Utils\Strings;
use Dvelum\App\Trigger;

/**
 * Manager for Db_Object events
 * @author Kirill A Egorov kirill.a.egorov@gmail.com
 * @copyright Copyright (C) 2012  Kirill A Egorov,
 * DVelum project https://github.com/dvelum/dvelum , http://dvelum.net
 * @license General Public License version 3
 */
class EventManager extends Orm\Record\Event\Manager
{
    protected ?CacheInterface $cache;
    protected Orm\Orm $orm;
    protected StorageInterface $configStorage;

    public function __construct(Orm\Orm $orm, StorageInterface $configStorage, ?CacheInterface $cache = null)
    {
        $this->cache = $cache;
        $this->configStorage = $configStorage;
        $this->orm = $orm;
    }

    /**
     * Set cache adapter
     * @param CacheInterface $cache
     */
    public function setCache(CacheInterface $cache): void
    {
        $this->cache = $cache;
    }

    /**
     * (non-PHPdoc)
     * @see Db_Object_Event_Manager::fireEvent()
     */
    public function fireEvent(string $code, Orm\RecordInterface $object): void
    {
        $objectName = ucfirst($object->getName());

        $name = explode('_', $objectName);
        $name = array_map('ucfirst', $name);

        $triggerClass = Strings::classFromString('\\Dvelum\\App\\Trigger\\' . implode('\\', $name));
        $namespacedClass = Strings::classFromString('\\App\\Trigger\\' . implode('\\', $name));

        if (class_exists($triggerClass) && method_exists($triggerClass, $code)) {
            $trigger = new $triggerClass();
            if ($this->cache) {
                $trigger->setCache($this->cache);
            }

            $trigger->$code($object);
        } elseif (class_exists($namespacedClass) && method_exists($namespacedClass, $code)) {
            $trigger = new $namespacedClass();
            if ($this->cache) {
                $trigger->setCache($this->cache);
            }
            $trigger->$code($object);
        } elseif (method_exists('\\Dvelum\\App\\Trigger', $code)) {
            $trigger = new Trigger($this->orm, $this->configStorage);
            if ($this->cache) {
                $trigger->setCache($this->cache);
            }
            $trigger->$code($object);
        }
    }
}
