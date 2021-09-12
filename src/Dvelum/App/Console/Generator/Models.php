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

namespace Dvelum\App\Console\Generator;

use Dvelum\App\Console;
use Dvelum\Orm;
use Dvelum\Config;
use Dvelum\Lang;

class Models extends Console\Action
{
    public function action(): bool
    {
        /**
         * @var Orm\Orm $orm
         */
        $orm = $this->diContainer->get(Orm\Orm::class);
        $dbObjectManager = $orm->getRecordManager();
        $modelPath = $this->diContainer->get(Config\Storage\StorageInterface::class)->get('main.php')->get(
            'local_models'
        );

        echo 'GENERATE MODELS' . PHP_EOL;
        /**
         * @var array<string> $registeredObjects
         */
        $registeredObjects = $dbObjectManager->getRegisteredObjects();
        foreach ($registeredObjects as $object) {
            $list = explode('_', $object);
            $list = array_map('ucfirst', $list);
            $class = 'Model_' . implode('_', $list);

            $path = str_replace(['_', '\\'], '/', $class);
            $namespace = str_replace('/', '\\', dirname($path));
            $fileName = basename($path);

            $path = $modelPath . $path . '.php';

            if (!class_exists($class)) {
                echo $namespace . '\\' . $fileName . "\n";
                $dir = dirname($path);

                if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
                    echo $this->diContainer->get(Lang::class)->getDictionary()->get('CANT_WRITE_FS') . ' ' . $dir;
                    return false;
                }

                $data = '<?php ' . PHP_EOL
                    . 'namespace ' . $namespace . ';' . PHP_EOL . PHP_EOL
                    . 'use Dvelum\\Orm\\Model;' . PHP_EOL . PHP_EOL
                    . 'class ' . $fileName . ' extends Model {}';

                if (!file_put_contents($path, $data)) {
                    echo $this->diContainer->get(Lang::class)->getDictionary()->get('CANT_WRITE_FS') . ' ' . $path;
                    return false;
                }
            }
        }
        return true;
    }
}
