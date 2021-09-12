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

use Dvelum\Orm\Exception;
use Dvelum\Orm\Record\Config;

class FieldManager
{
    /**
     * Remove field from configuration object
     * @param Config $config
     * @param string $name
     * @throws \Exception
     */
    public function removeField(Config $config, string $name): void
    {
        $fields = $config->getFieldsConfig();

        if (!isset($fields[$name])) {
            return;
        }

        unset($fields[$name]);

        $config->getConfig()->set('fields', $fields);

        $indexManager = new IndexManager();
        $indexManager->removeFieldIndexes($config, $name);
    }

    /**
     * Rename field
     * @param Config $config
     * @param string $oldName
     * @param string $newName
     * @return void
     * @throws \Exception
     */
    public function renameField(Config $config, string $oldName, string $newName): void
    {
        $fields = $config->getFieldsConfig();

        if (!isset($fields[$oldName])) {
            throw new Exception('Undefined field ' . $config->getName() . '.' . $oldName);
        }

        $fields[$newName] = $fields[$oldName];
        unset($fields[$oldName]);

        $config->getConfig()->set('fields', $fields);

        $indexManager = new IndexManager();
        $indexManager->renameFieldInIndex($config, $oldName, $newName);
    }

    /**
     * Configure the field
     * @param Config $config
     * @param string $field
     * @param array<string,mixed> $data
     */
    public function setFieldConfig(Config $config, string $field, array $data): void
    {
        $cfg = &$config->getConfig()->dataLink();
        $cfg['fields'][$field] = $data;
    }

    /**
     * Update field link, set linked object name
     * @param Config $config
     * @param string $field
     * @param string $linkedObject
     * @return bool
     * @throws \Exception
     */
    public function setFieldLink(Config $config, string $field, string $linkedObject): bool
    {
        if (!$config->getField($field)->isLink()) {
            return false;
        }

        $cfg = &$config->getConfig()->dataLink();
        $cfg['fields'][$field]['link_config']['object'] = $linkedObject;
        return true;
    }
}
