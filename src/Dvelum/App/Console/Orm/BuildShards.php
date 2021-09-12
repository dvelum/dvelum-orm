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

namespace Dvelum\App\Console\Orm;

use Dvelum\App\Console;
use Dvelum\Config;
use Dvelum\Orm;

class BuildShards extends Console\Action
{
    public function action(): bool
    {
        $configStorage = $this->diContainer->get(Config\Storage\StorageInterface::class);
        $ormConfig = $configStorage->get('orm.php');
        /**
         * @var Orm\Orm $orm
         */
        $orm = $this->diContainer->get(Orm\Orm::class);
        $dbObjectManager = $orm->getRecordManager();
        $success = true;

        echo 'BUILD SHARDS ' . PHP_EOL;

        $sharding = $configStorage->get('sharding.php');
        $shardsFile = $sharding->get('shards');
        $shardsConfig = $configStorage->get($shardsFile);
        $registeredObjects = $dbObjectManager->getRegisteredObjects();

        foreach ($shardsConfig as $item) {
            $shardId = $item['id'];
            echo $shardId . ' ' . PHP_EOL;
            echo "\t Tables" . PHP_EOL;
            //build objects
            if (!empty($registeredObjects)) {
                foreach ($registeredObjects as $index => $object) {
                    if (!$orm->config($object)->isDistributed()) {
                        unset($registeredObjects[$index]);
                        continue;
                    }
                    echo "\t\t" . $object . ' : ';

                    $builder = $orm->getBuilder($object);
                    $builder->setConnection($orm->model($object)->getDbShardConnection($shardId));
                    if ($builder->build(false, true)) {
                        echo 'OK' . PHP_EOL;
                    } else {
                        $success = false;
                        echo 'Error! ' . strip_tags(implode(', ', $builder->getErrors())) . PHP_EOL;
                    }
                }
            }

            //build foreign keys
            if ($ormConfig->get('foreign_keys')) {
                echo "\t Foreign Keys " . PHP_EOL;
                if (!empty($registeredObjects)) {
                    foreach ($registeredObjects as $index => $object) {
                        echo "\t\t" . $object . ' : ';

                        $builder = $orm->getBuilder($object);
                        $builder->setConnection($orm->model($object)->getDbShardConnection($shardId));
                        if ($builder->build(true, true)) {
                            echo 'OK' . PHP_EOL;
                        } else {
                            $success = false;
                            echo 'Error! ' . strip_tags(implode(', ', $builder->getErrors())) . PHP_EOL;
                        }
                    }
                }
            }
        }
        return $success;
    }
}
