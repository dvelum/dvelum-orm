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

namespace Dvelum\Orm\Distributed\Key;

use Dvelum\Config\ConfigInterface;
use Dvelum\Orm\Orm;
use Dvelum\Orm\Record;
use Dvelum\Orm\RecordInterface;

interface GeneratorInterface
{
    /**
     * @param Orm $orm
     * @param ConfigInterface<int|string,mixed> $config
     */
    public function __construct(Orm $orm, ConfigInterface $config);

    /**
     * Reserve object id, save route
     * @param RecordInterface $object
     * @param string $shard
     * @return null|Reserved
     */
    public function reserveIndex(RecordInterface $object, string $shard): ?Reserved;

    /**
     * Delete reserved index
     * @param RecordInterface $record
     * @param mixed $distributedKey
     * @return bool
     */
    public function deleteIndex(RecordInterface $record, $distributedKey): bool;

    /**
     * Get object shard id
     * @param string $objectName
     * @param mixed $distributedKey
     * @return mixed
     */
    public function findObjectShard(string $objectName, $distributedKey);

    /**
     * Get shards for list of objects
     * @param string $objectName
     * @param array<mixed> $distributedKeys
     * @return array<string,array<int,string>>  [shard_id=>[key1,key2,key3], shard_id2=>[...]]
     */
    public function findObjectsShards(string $objectName, array $distributedKeys): array;

    /**
     * Detect object shard by own rules
     * @param RecordInterface $record
     * @return null|string
     */
    public function detectShard(RecordInterface $record): ?string;
}
