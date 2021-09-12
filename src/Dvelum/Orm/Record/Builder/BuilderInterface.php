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

namespace Dvelum\Orm\Record\Builder;

use Dvelum\Config\ConfigInterface;
use Dvelum\Config\Storage\StorageInterface;
use Dvelum\Lang\Dictionary;
use Dvelum\Orm\Orm;

/**
 * interface BuilderInterface
 * @package Dvelum\Orm\Record\Builder
 */
interface BuilderInterface
{
    /**
     * @param ConfigInterface<int|string,mixed> $config
     * @param Orm $orm
     * @param StorageInterface $configStorage
     * @param Dictionary $lang
     */
    public function __construct(ConfigInterface $config, Orm $orm, StorageInterface $configStorage, Dictionary $lang);

    /**
     * Get error messages
     * @return array<string>
     */
    public function getErrors(): array;

    /**
     * Check for broken object links
     * @return array<mixed>
     */
    public function getBrokenLinks(): array;

    /**
     * Check if DB table has correct structure
     * @return bool
     */
    public function validate(): bool;

    /**
     * Get object foreign keys
     * @return array<string,mixed>
     */
    public function getOrmForeignKeys(): array;

    /**
     * Get updates information
     * @return array<string,array<string,string>>
     */
    public function getRelationUpdates(): array;

    /**
     * Create / alter db table
     * @param bool $buildForeignKeys
     * @param bool $buildShards
     * @return bool
     */
    public function build(bool $buildForeignKeys = true, bool $buildShards = false): bool;

    /**
     * Build Foreign Keys
     * @param bool $remove - remove keys
     * @param bool $create - create keys
     * @return bool
     */
    public function buildForeignKeys(bool $remove = true, bool $create = true): bool;

    /**
     * Remove object
     * @return bool
     */
    public function remove(): bool;

    /**
     * Check if table exists
     * @param string $name - optional, table name,
     * @param bool $addPrefix - optional append prefix, default false
     * @return bool
     */
    public function tableExists(string $name = '', bool $addPrefix = false): bool;
}
