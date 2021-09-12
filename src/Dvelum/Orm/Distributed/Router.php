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

namespace Dvelum\Orm\Distributed;

use Dvelum\Config;
use Dvelum\Config\ConfigInterface;
use Dvelum\Orm\Distributed;
use Dvelum\Orm\Orm;
use Dvelum\Orm\RecordInterface;
use Dvelum\Service;

class Router implements RouterInterface
{
    /**
     * @var ConfigInterface<int|string,mixed> $config
     */
    protected ConfigInterface $config;

    /**
     * @var array<mixed>
     */
    protected array $routes = [];
    /**
     * @var array<string,mixed>
     */
    protected array $objectToRoute;

    protected Orm $orm;

    /**
     * @param ConfigInterface<int|string,mixed> $routes
     */
    public function __construct(Orm $orm, ConfigInterface $routes)
    {
        $this->orm = $orm;
        $this->config = $routes;

        foreach ($this->config as $route) {
            if (!$route['enabled']) {
                continue;
            }
            $this->routes[$route['id']] = $route;
            if (isset($route['objects']) && !empty($route['objects'])) {
                foreach ($route['objects'] as $object) {
                    $this->objectToRoute[$object] = $route['id'];
                }
            }
        }
    }

    /**
     * Check routes for ORM object
     * @param string $objectName
     * @return bool
     */
    public function hasRoutes(string $objectName): bool
    {
        if (!count($this->routes)) {
            return false;
        }
        if (isset($this->objectToRoute[$objectName]) && !empty($this->objectToRoute[$objectName])) {
            return true;
        }

        return false;
    }

    /**
     * Find shard for ORM\Record
     * @param RecordInterface $record
     * @return null|string
     */
    public function findShard(RecordInterface $record): ?string
    {
        $objectName = $record->getName();
        if (isset($this->objectToRoute[$objectName])) {
            $config = $this->routes[$this->objectToRoute[$objectName]];
            $adapterClass = $config['adapter'];
            $adapterConfig = Config\Factory::create(
                $config['config'][$objectName],
                'ROUTER_' . $config['id'] . '_' . $objectName
            );
            $adapter = new $adapterClass($this->orm, $adapterConfig);
            return $adapter->getShard($record);
        }

        return null;
    }
}
