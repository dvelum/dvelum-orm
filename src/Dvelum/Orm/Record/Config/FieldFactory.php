<?php

/*
 * DVelum project https://github.com/dvelum/dvelum , https://github.com/k-samuel/dvelum , http://dvelum.net
 * Copyright (C) 2011-2020  Kirill Yegorov
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */
declare(strict_types=1);

namespace Dvelum\Orm\Record\Config;

use Dvelum\App\Dictionary\Service;
use Dvelum\Orm;
use Dvelum\Orm\Record\Config;

/**
 * Class Field
 * @package Dvelum\Orm\Record\Config
 */
class FieldFactory
{
    protected Orm\Orm $orm;
    protected Service $dictionary;

    public function __construct(Orm\Orm $orm, Service $dictionary)
    {
        $this->orm = $orm;
        $this->dictionary = $dictionary;
    }

    /**
     * @param Config $config
     * @param string $fieldName
     * @return Field
     * @throws Orm\Exception
     */
    public function getField(Config $config, string $fieldName): Field
    {
        $fields = $config->getConfig()->get('fields');

        if (!isset($fields[$fieldName])) {
            throw new Orm\Exception('Undefined field ' . $config->getName() . '.' . $fieldName);
        }

        $configData = $fields[$fieldName];
        $configData['name'] = $fieldName;
        $fieldClass = 'Field';

        //detect field type
        if (!isset($configData['db_type'])) {
            throw new Orm\Exception('Undefined db_type for field ' . $config->getName() . '.' . $fieldName);
        }
        $dbType = $configData['db_type'];

        if (isset($configData['type']) && $configData['type'] === 'link' && isset($configData['link_config']) && isset($configData['link_config']['link_type'])) {
            switch ($configData['link_config']['link_type']) {
                case Orm\Record\Config::LINK_OBJECT;
                    $class = Config\Field\ObjectItem::class;
                    return new $class($this->orm, $configData);
                case Orm\Record\Config::LINK_OBJECT_LIST;
                    $class = Config\Field\ObjectList::class;
                    return new $class($this->orm, $configData);
                case 'dictionary';
                    $class = Config\Field\Dictionary::class;
                    return new $class($this->dictionary, $configData);
            }
        } else {
            if (in_array($dbType, Orm\Record\BuilderFactory::$intTypes, true)) {
                $fieldClass = 'Integer';
            } elseif (in_array($dbType, Orm\Record\BuilderFactory::$charTypes, true)) {
                $fieldClass = 'Varchar';
            } elseif (in_array($dbType, Orm\Record\BuilderFactory::$textTypes, true)) {
                $fieldClass = 'Text';
            } elseif (in_array($dbType, Orm\Record\BuilderFactory::$floatTypes, true)) {
                $fieldClass = 'Floating';
            } else {
                $fieldClass = $dbType;
            }
        }
        $fieldClass = 'Dvelum\\Orm\\Record\\Config\\Field\\' . ucfirst((string)$fieldClass);

        if (class_exists($fieldClass)) {
            return new $fieldClass($configData);
        }

        return new Config\Field($configData);
    }
}