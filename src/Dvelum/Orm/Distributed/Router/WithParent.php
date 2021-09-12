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

namespace Dvelum\Orm\Distributed\Router;

use Dvelum\Config\ConfigInterface;
use Dvelum\Orm\Distributed;
use Dvelum\Orm\Orm;
use Dvelum\Orm\RecordInterface;

class WithParent implements RouteInterface
{
    /**
     * @var ConfigInterface<int|string,mixed>
     */
    protected $config;

    protected Orm $orm;

    /**
     * @param Orm $orm
     * @param ConfigInterface<int|string,mixed> $config
     */
    public function __construct(Orm $orm, ConfigInterface $config)
    {
        $this->config = $config;
        $this->orm = $orm;
    }

    /**
     * Find shard for object
     * @param RecordInterface $record
     * @return null|string
     */
    public function getShard(RecordInterface $record): ?string
    {
        $parentObject = $this->config->get('parent');
        $parentField = $this->config->get('parent_field');
        $parentId = $record->get($parentField);
        $objectShard = '';

        if (!empty($parentId)) {
            $objectShard = $this->orm->distributed()->findObjectShard($this->orm->config($parentObject), $parentId);
        }

        if (empty($objectShard)) {
            $objectShard = null;
        }

        return $objectShard;
    }
}
