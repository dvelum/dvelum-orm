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

namespace Dvelum\Orm\Record\Config;

use Dvelum\Orm\Record\Config;

class IndexManager
{
    /**
     * Check if Index exists
     * @param Config $config
     * @param string $index
     * @return bool
     */
    public function indexExists(Config $config, string $index): bool
    {
        return isset($config->getConfig()['indexes'][$index]);
    }

    /**
     * Remove indexes for field
     * @param Config $config
     * @param string $fieldName
     * @throws \Exception
     */
    public function removeFieldIndexes(Config $config, string $fieldName): void
    {
        $indexes = $config->getIndexesConfig();
        /**
         * Check for indexes for field
         */
        foreach ($indexes as $index => &$item) {
            if (isset($item['columns']) && !empty($item['columns'])) {
                /*
                 * Remove field from index
                 */
                foreach ($item['columns'] as $id => $value) {
                    if ($value === $fieldName) {
                        unset($item['columns'][$id]);
                    }
                }
                /*
                 * Remove empty index
                 */
                if (empty($item['columns'])) {
                    unset($indexes[$index]);
                }
            }
        }
        $config->getConfig()->set('indexes', $indexes);
    }

    /**
     * Remove index from record configuration
     * @param Config $config
     * @param string $indexName
     * @throws \Exception
     */
    public function removeIndex(Config $config, string $indexName): void
    {
        $indexes = $config->getIndexesConfig();

        if (!isset($indexes[$indexName])) {
            return;
        }

        unset($indexes[$indexName]);

        $config->getConfig()->set('indexes', $indexes);
    }

    /**
     * Rename field in index configuration
     * @param Config $config
     * @param string $oldName
     * @param string $newName
     * @throws \Exception
     */
    public function renameFieldInIndex(Config $config, string $oldName, string $newName): void
    {
        $indexes = $config->getIndexesConfig();
        /**
         * Check for indexes for field
         */
        foreach ($indexes as $index => &$item) {
            if (isset($item['columns']) && !empty($item['columns'])) {
                /*
                 * Rename index link
                 */
                foreach ($item['columns'] as $id => &$value) {
                    if ($value === $oldName) {
                        $value = $newName;
                    }
                }
                unset($value);
            }
        }
        $config->getConfig()->set('indexes', $indexes);
    }

    /**
     * Delete distributed index
     * @param Config $config
     * @param string $name
     * @return bool
     * @throws \Exception
     */
    public function removeDistributedIndex(Config $config, string $name): bool
    {
        $indexes = $config->getDistributedIndexesConfig();

        if (!isset($indexes[$name]) || $indexes[$name]['is_system']) {
            return false;
        }

        unset($indexes[$name]);

        $config->getConfig()->set('distributed_indexes', $indexes);

        return true;
    }

    /**
     * Configure the index
     * @param Config $config
     * @param string $index
     * @param array<string,mixed> $data
     * @return void
     * @throws \Exception
     */
    public function setIndexConfig(Config $config, string $index, array $data): void
    {
        $indexes = $config->getIndexesConfig();
        $indexes[$index] = $data;
        $config->getConfig()->set('indexes', $indexes);
    }

    /**
     * Configure distributed index
     * @param Config $config
     * @param string $index
     * @param array<string,mixed> $data
     * @throws \Exception
     */
    public function setDistributedIndexConfig(Config $config, string $index, array $data): void
    {
        $indexes = $config->getDistributedIndexesConfig();
        $indexes[$index] = $data;
        $config->getConfig()->set('distributed_indexes', $indexes);
    }
}
