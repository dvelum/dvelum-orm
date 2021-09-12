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
use Dvelum\Orm;
use Dvelum\Config;

class Build extends Console\Action
{
    public function action(): bool
    {
        $ormConfig = $this->diContainer->get(Config\Storage\StorageInterface::class)->get('orm.php');
        /**
         * @var Orm\Orm $orm
         */
        $orm = $this->diContainer->get(Orm\Orm::class);
        $dbObjectManager = $orm->getRecordManager();
        $success = true;

        echo "BUILD OBJECTS " . PHP_EOL;

        $registeredObjects = $dbObjectManager->getRegisteredObjects();
        // build object
        if (!empty($registeredObjects)) {
            foreach ($registeredObjects as $object) {
                $cfg = $orm->config($object);
                if ($cfg->isDistributed()) {
                    echo "\t " . $object . ' :  is distributed, skip' . PHP_EOL;
                    continue;
                }
                if ($cfg->isLocked() || $cfg->isReadOnly()) {
                    echo "\t " . $object . ' :  is locked or readonly, skip' . PHP_EOL;
                    continue;
                }

                echo "\t " . $object . ' : ';
                $builder = $orm->getBuilder($object);
                if ($builder->build(false)) {
                    echo 'OK' . PHP_EOL;
                } else {
                    $success = false;
                    echo 'Error! ' . strip_tags(implode(', ', $builder->getErrors())) . PHP_EOL;
                }
            }
        }
        //build foreign keys
        if ($ormConfig->get('foreign_keys')) {
            echo PHP_EOL . "\t BUILD FOREIGN KEYS" . PHP_EOL . PHP_EOL;
            if (!empty($registeredObjects)) {
                foreach ($registeredObjects as $object) {
                    $cfg = $orm->config($object);

                    if ($cfg->isDistributed()) {
                        echo "\t " . $object . ' :  is distributed, skip' . PHP_EOL;
                        continue;
                    }

                    if ($cfg->isLocked() || $cfg->isReadOnly()) {
                        echo "\t " . $object . ' :  is locked or readonly, skip' . PHP_EOL;
                        continue;
                    }

                    echo "\t " . $object . ' : ';
                    $builder = $orm->getBuilder($object);
                    if ($builder->build(true)) {
                        echo 'OK' . PHP_EOL;
                    } else {
                        $success = false;
                        echo 'Error! ' . strip_tags(implode(', ', $builder->getErrors())) . PHP_EOL;
                    }
                }
            }
        }
        return $success;
    }
}
