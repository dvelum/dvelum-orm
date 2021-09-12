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

        if (
            isset($configData['type']) &&
            $configData['type'] === 'link' &&
            isset($configData['link_config']) &&
            isset($configData['link_config']['link_type'])
        ) {
            switch ($configData['link_config']['link_type']) {
                case Orm\Record\Config::LINK_OBJECT:
                    $class = Config\Field\ObjectItem::class;
                    return new $class($this->orm, $configData);
                case Orm\Record\Config::LINK_OBJECT_LIST:
                    $class = Config\Field\ObjectList::class;
                    return new $class($this->orm, $configData);
                case 'dictionary':
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
