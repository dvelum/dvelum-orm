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

use Dvelum\Config\Storage\StorageInterface;
use Dvelum\File;
use Dvelum\Orm\Orm;
use Dvelum\Service;

/**
 * Db_Object Manager class
 * @package Db
 * @subpackage Db_Object
 * @author Kirill A Egorov kirill.a.egorov@gmail.com
 * @copyright Copyright (C) 2011-2012  Kirill A Egorov,
 * DVelum project https://github.com/dvelum/dvelum , http://dvelum.net
 * @license General Public License version 3
 */
class Manager
{
    /**
     * @var array<int,string>|null
     */
    protected static ?array $objects = null;

    private StorageInterface $configStorage;
    private Orm $orm;

    public function __construct(StorageInterface $configStorage, Orm $orm)
    {
        $this->configStorage = $configStorage;
        $this->orm = $orm;
    }

    /**
     * Get list of registered objects (names only)
     * @return array<int,string>
     */
    public function getRegisteredObjects(): ?array
    {
        if (is_null(self::$objects)) {
            self::$objects = [];
            $paths = $this->configStorage->getPaths();

            $list = [];
            $cfgPath = $this->orm->getConfigSettings()->get('configPath');

            foreach ($paths as $path) {
                if (!file_exists($path . $cfgPath)) {
                    continue;
                }

                $items = File::scanFiles($path . $cfgPath, array('.php'), false, File::FILES_ONLY);

                if (!empty($items)) {
                    foreach ($items as $o) {
                        $baseName = substr(basename($o), 0, -4);
                        if (!isset($list[$baseName])) {
                            self::$objects[] = $baseName;
                            $list[$baseName] = true;
                        }
                    }
                }
            }
        }
        return self::$objects;
    }

    /**
     * Check if object exists
     * @param string $name
     * @return bool
     */
    public function objectExists(string $name): bool
    {
        $list = $this->getRegisteredObjects();
        if (empty($list)) {
            return false;
        }
        return in_array(strtolower($name), $list, true);
    }
}
