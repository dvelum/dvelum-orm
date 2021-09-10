<?php
/**
 *  DVelum project https://github.com/dvelum/dvelum
 *  Copyright (C) 2011-2017  Kirill Yegorov
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
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
    public function getErrors():array;

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
     * @return array<string>
     */
    public function getRelationUpdates(): array;

    /**
     * Check for broken object links
     * @return array<string>|false
     */
    public function hasBrokenLinks();

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