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

use Dvelum\Orm;
use Dvelum\Orm\Exception;
use Dvelum\Orm\Record\Config;

/**
 * Class Field
 * @package Dvelum\Orm\Record\Config
 */
class Relation
{
    /**
     * Check if object has ManyToMany relations
     * @param Config $config
     * @return bool
     * @throws Exception
     */
    public function hasManyToMany(Config $config): bool
    {
        $relations = $this->getManyToMany($config);
        if (!empty($relations)) {
            return true;
        }
        return false;
    }

    /**
     * Get manyToMany relations
     * @param Config $config
     * @return array<string,array<string,string>>
     * @throws \Exception
     */
    public function getManyToMany(Config $config): array
    {
        $result = [];
        $fieldConfigs = $config->getFieldsConfig();
        foreach ($fieldConfigs as $field => $cfg) {
            if (
                isset($cfg['type'])
                && $cfg['type'] === 'link'
                && isset($cfg['link_config']['link_type'])
                && $cfg['link_config']['link_type'] == Config::LINK_OBJECT_LIST
                && isset($cfg['link_config']['object'])
                && isset($cfg['link_config']['relations_type'])
                && $cfg['link_config']['relations_type'] == Config::RELATION_MANY_TO_MANY
            ) {
                $result[(string)$cfg['link_config']['object']][(string)$field] = Config::RELATION_MANY_TO_MANY;
            }
        }
        return $result;
    }

    /**
     * Get name of relations Record
     * @param Config $config
     * @param string $field
     * @return bool|string
     * @throws Exception
     */
    public function getRelationsObject(Config $config, string $field)
    {
        $cfg = $config->getFieldConfig($field);

        if (
            isset($cfg['type'])
            && $cfg['type'] === 'link'
            && isset($cfg['link_config']['link_type'])
            && $cfg['link_config']['link_type'] == Config::LINK_OBJECT_LIST
            && isset($cfg['link_config']['object'])
            && isset($cfg['link_config']['relations_type'])
            && $cfg['link_config']['relations_type'] == Config::RELATION_MANY_TO_MANY
        ) {
            return $config->getName() . '_' . $field . '_to_' . $cfg['link_config']['object'];
        }
        return false;
    }

    /**
     * Get a list of fields linking to external objects
     * @param Config $config
     * @param array<string> $linkTypes - optional link type filter
     * @param bool $groupByObject - group field by linked object, default true
     * @return array<string,array>
     * [objectName=>[field => link_type]] | [field =>["object"=>objectName,"link_type"=>link_type]]
     * @throws \Exception
     */
    public function getLinks(
        Config $config,
        $linkTypes = [Orm\Record\Config::LINK_OBJECT, Orm\Record\Config::LINK_OBJECT_LIST],
        $groupByObject = true
    ): array {
        $data = [];
        /**
         * @var array<string,array<string,mixed>> $fields
         */
        $fields = $config->getFieldsConfig(true);
        foreach ($fields as $name => $cfg) {
            if (
                isset($cfg['type'])
                && $cfg['type'] === 'link'
                && isset($cfg['link_config']['link_type'])
                && in_array($cfg['link_config']['link_type'], $linkTypes, true)
                && isset($cfg['link_config']['object'])
            ) {
                if ($groupByObject) {
                    $data[(string)$cfg['link_config']['object']][$name] = $cfg['link_config']['link_type'];
                } else {
                    $data[(string)$name] = [
                        'object' => $cfg['link_config']['object'],
                        'link_type' => $cfg['link_config']['link_type']
                    ];
                }
            }
        }
        return $data;
    }
}
