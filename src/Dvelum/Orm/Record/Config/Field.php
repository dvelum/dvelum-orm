<?php

/*
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

use Dvelum\Orm\Orm;
use Dvelum\Orm\Record\BuilderFactory;
use Dvelum\Orm\Record\Config;

/**
 * Class Field
 * @package Dvelum\Orm\Record\Config
 * @implements \ArrayAccess<string,mixed>
 */
class Field implements \ArrayAccess
{
    /**
     * @var array<string,mixed>
     */
    protected array $config;
    protected string $validationError = '';


    /**
     * @param array<string,mixed> $config
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Get field config
     * @return array<string,mixed>
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Get field type
     * @return string
     */
    public function getType(): string
    {
        if (!isset($this->config['type'])) {
            return '';
        }

        return (string)$this->config['type'];
    }

    /**
     * Check whether the field is a boolean field
     * @return bool
     */
    public function isBoolean(): bool
    {
        return (isset($this->config['db_type']) && $this->config['db_type'] === 'boolean');
    }

    /**
     * Check whether the field is a numeric field
     * @return bool
     */
    public function isNumeric(): bool
    {
        return (isset($this->config['db_type']) && in_array(
            $this->config['db_type'],
            BuilderFactory::$numTypes,
            true
        ));
    }

    /**
     * Check whether the field is a integer field
     * @return bool
     */
    public function isInteger(): bool
    {
        return (isset($this->config['db_type']) && in_array(
            $this->config['db_type'],
            BuilderFactory::$intTypes,
            true
        ));
    }

    /**
     * Check whether the field is a float field
     * @return boolean
     */
    public function isFloat(): bool
    {
        return (isset($this->config['db_type']) && in_array(
            $this->config['db_type'],
            BuilderFactory::$floatTypes,
            true
        ));
    }

    /**
     * Check whether the field is a text field
     * @param mixed $charTypes optional
     * @return boolean
     */
    public function isText($charTypes = false): bool
    {
        if (!isset($this->config['db_type'])) {
            return false;
        }

        $isText = (in_array($this->config['db_type'], BuilderFactory::$textTypes, true));

        if ($charTypes && !$isText) {
            $isText = (in_array($this->config['db_type'], BuilderFactory::$charTypes, true));
        }

        return $isText;
    }

    /**
     * Check whether the field is a date field
     */
    public function isDateField(): bool
    {
        return (isset($this->config['db_type']) && in_array(
            $this->config['db_type'],
            BuilderFactory::$dateTypes,
            true
        ));
    }

    /**
     * Check if the field value is required
     * @return boolean
     */
    public function isRequired(): bool
    {
        if (isset($this->config['required']) && $this->config['required']) {
            return true;
        }

        return false;
    }

    /**
     * Check if field can be used for search
     * @return bool
     */
    public function isSearch(): bool
    {
        if (isset($this->config['is_search']) && $this->config['is_search']) {
            return true;
        }
        return false;
    }

    /**
     * Check if field is encrypted
     * @return boolean
     */
    public function isEncrypted(): bool
    {
        if (isset($this->config['type']) && $this->config['type'] === 'encrypted') {
            return true;
        }
        return false;
    }

    /**
     * Check if the field is a link
     * @return bool
     */
    public function isLink(): bool
    {
        if (isset($this->config['type']) && $this->config['type'] === 'link') {
            return true;
        }

        return false;
    }

    /**
     * Check if the field is a link to the dictionary
     * @return bool
     */
    public function isDictionaryLink(): bool
    {
        if (
            isset($this->config['type']) &&
            $this->config['type'] === 'link' &&
            isset($this->config['link_config']) &&
            is_array($this->config['link_config']) &&
            $this->config['link_config']['link_type'] === 'dictionary'
        ) {
            return true;
        }
        return false;
    }

    /**
     * Check if html is allowed\
     * @return bool
     */
    public function isHtml(): bool
    {
        if (isset($this->config['allow_html']) && $this->config['allow_html']) {
            return true;
        }
        return false;
    }

    /**
     * Get the database type for the field
     * @return string
     */
    public function getDbType(): string
    {
        return $this->config['db_type'];
    }

    /**
     * Check whether the field should be unique
     * @return bool
     */
    public function isUnique(): bool
    {
        if (!isset($this->config['unique'])) {
            return false;
        }

        if (is_string($this->config['unique']) && strlen($this->config['unique'])) {
            return true;
        }

        return (bool)$this->config['unique'];
    }

    /**
     * Check if a field is a object link
     * @return bool
     */
    public function isObjectLink(): bool
    {
        if (
            isset($this->config['type']) &&
            $this->config['type'] === 'link' &&
            isset($this->config['link_config']) &&
            is_array($this->config['link_config']) &&
            $this->config['link_config']['link_type'] === Config::LINK_OBJECT
        ) {
            return true;
        }
        return false;
    }

    /**
     * Check if a field is a MultiLink (a list of links to objects of the same type)
     * @return bool
     */
    public function isMultiLink(): bool
    {
        if (
            isset($this->config['type']) &&
            $this->config['type'] === 'link' &&
            isset($this->config['link_config']) &&
            is_array($this->config['link_config']) &&
            $this->config['link_config']['link_type'] === Config::LINK_OBJECT_LIST
        ) {
            return true;
        }
        return false;
    }

    /**
     * Check if field is ManyToMany relation
     * @return bool
     */
    public function isManyToManyLink(): bool
    {
        if (
            isset($this->config['type'])
            && $this->config['type'] === 'link'
            && is_array($this->config['link_config'])
            && $this->config['link_config']['link_type'] === Config::LINK_OBJECT_LIST
            && isset($this->config['link_config']['relations_type'])
            && $this->config['link_config']['relations_type'] === Config::RELATION_MANY_TO_MANY
        ) {
            return true;
        }
        return false;
    }

    /**
     * Get the name of the object referenced by the field
     * @return string | false on error
     */
    public function getLinkedObject()
    {
        if (!$this->isLink()) {
            return false;
        }

        return $this->config['link_config']['object'];
    }

    /**
     * Get field default value. Note! Method return false if value not specified
     * @return string | false
     */
    public function getDefault()
    {
        if (isset($this->config['db_default'])) {
            return $this->config['db_default'];
        }
        return false;
    }

    /**
     * Check if field has default value
     * @return bool
     */
    public function hasDefault(): bool
    {
        if (isset($this->config['db_default']) && $this->config['db_default'] !== false) {
            return true;
        }
        return false;
    }

    /**
     * Check if field is numeric and unsigned
     * @return bool
     */
    public function isUnsigned(): bool
    {
        if (!$this->isNumeric()) {
            return false;
        }

        if (isset($this->config['db_unsigned']) && $this->config['db_unsigned']) {
            return true;
        }
        return false;
    }

    /**
     * Check if field can be null
     * @return bool
     */
    public function isNull(): bool
    {
        if (isset($this->config['db_isNull']) && $this->config['db_isNull']) {
            return true;
        }
        return false;
    }

    /**
     * Get the name of the dictionary that is referenced by the field
     * @return string | bool on error
     */
    public function getLinkedDictionary()
    {
        if (!$this->isDictionaryLink()) {
            return false;
        }

        return $this->config['link_config']['object'];
    }

    /**
     * Check if field is virtual (no database representation)
     * @return bool
     */
    public function isVirtual(): bool
    {
        return $this->isMultiLink();
    }


    //==== Start of ArrayAccess implementation ===
    public function offsetSet($offset, $value)
    {
        $this->config[$offset] = $value;
    }

    public function offsetExists($offset)
    {
        return isset($this->config[$offset]);
    }

    public function offsetUnset($offset)
    {
        unset($this->config[$offset]);
    }

    public function offsetGet($offset)
    {
        return isset($this->config[$offset]) ? $this->config[$offset] : null;
    }

    //====End of ArrayAccess implementation ====

    /**
     * @return array<string,mixed>
     */
    public function __toArray(): array
    {
        return $this->config;
    }

    public function __isset(string $name): bool
    {
        return isset($this->config[$name]);
    }

    /**
     * Apply value filter
     * @param mixed $value
     * @return mixed
     */
    public function filter($value)
    {
        return $value;
    }

    /**
     * Validate value
     * @param mixed $value
     * @return bool
     */
    public function validate($value): bool
    {
        if ($this->isRequired() && !strlen((string)$value)) {
            $this->validationError = 'Field ' . $this->getName() . ' cannot be empty';
            return false;
        }
        return true;
    }

    /**
     * Get field name
     * @return string
     */
    public function getName(): string
    {
        return $this->config['name'];
    }

    /**
     * Get last validation error
     * @return string
     */
    public function getValidationError(): string
    {
        return $this->validationError;
    }

    /**
     * Get field title
     * @return string
     */
    public function getTitle(): string
    {
        return $this->config['title'];
    }

    /**
     * Check if fieldIs System
     * @return bool
     */
    public function isSystem(): bool
    {
        if (isset($this->config['system']) && $this->config['system']) {
            return true;
        }
        return false;
    }
}
