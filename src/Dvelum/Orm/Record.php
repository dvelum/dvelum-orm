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

namespace Dvelum\Orm;

use Dvelum\Orm;
use Dvelum\Orm\Record\Config;
use Dvelum\Orm\Record\DataModel;
use Dvelum\Service;
use Dvelum\Utils;

/**
 * Database Object class. ORM element.
 * @author Kirill Egorov 2011-2017  DVelum project
 * @package Dvelum\Orm
 */
class Record implements RecordInterface
{
    /**
     * @var string
     */
    protected $name;

    /**
     * @var Record\Config
     */
    protected $config;
    /**
     * @var null|int
     */
    protected ?int $id;
    protected string $primaryKey;
    /**
     * @var array<string,mixed>
     */
    protected array $data = [];
    /**
     * @var array<string,mixed>
     */
    protected array $updates = [];
    /**
     * @var array<int,string>
     */
    protected array $errors = [];

    /**
     * Insert ID
     * @var int|null
     */
    protected ?int $insertId = null;

    /**
     * @var Model
     */
    protected Model $model;

    /**
     * Loaded version of VC object
     * @var int
     */
    protected int $version = 0;

    /**
     * @var DataModel|null
     */
    protected ?DataModel $dataModel = null;

    protected Orm\Orm $orm;


    /**
     * The object constructor takes its name and identifier,
     * (the parameter is not required), if absent,
     * there will be created a new object. If ORM lacks the object with the specified
     * identifier, an Exception will show up
     * Using this method is highly undesirable,
     * the $orm->record($name, $id) is more advisable to use
     * @param Config $config
     * @param null|int $id - optional
     * @throws Exception | \Exception
     */
    public function __construct(Orm\Orm $orm, Config $config, ?int $id = null)
    {
        $this->config = $config;
        $this->name = $config->getName();
        $this->id = $id;
        $this->primaryKey = $config->getPrimaryKey();
        $this->orm = $orm;

        if ($this->id) {
            $this->loadData();
        }
    }

    /**
     * @return DataModel
     */
    public function getDataModel(): DataModel
    {
        if (empty($this->dataModel)) {
            $this->dataModel = new DataModel($this->orm);
        }
        return $this->dataModel;
    }

    /**
     * Load object data
     * @return void
     * @throws \Exception
     */
    protected function loadData(): void
    {
        $dataModel = $this->getDataModel();
        /**
         * @var array<string,mixed> $data
         */
        $data = $dataModel->load($this);
        $this->setRawData($data);
    }

    /**
     * Set raw data from storage
     * @param array<string,mixed> $data
     * @return void
     * @throws \Exception
     */
    public function setRawData(array $data): void
    {
        unset($data[$this->primaryKey]);
        $iv = false;

        if ($this->config->hasEncrypted()) {
            $ivField = $this->config->getIvField();
            if (isset($data[$ivField]) && !empty($data[$ivField])) {
                $iv = $data[$ivField];
            }
        }

        foreach ($data as $field => &$value) {
            $fieldObject = $this->getConfig()->getField((string)$field);

            if ($fieldObject->isBoolean()) {
                if ($value) {
                    $value = true;
                } else {
                    $value = false;
                }
            }

            if ($fieldObject->isEncrypted()) {
                $value = (string)$value;
                if (is_string($iv) && strlen($value) && strlen($iv)) {
                    $value = $this->config->getCryptService()->decrypt($value, $iv);
                }
            }
        }
        unset($value);
        $this->data = $data;
    }

    /**
     * Get object fields
     * @return array<string|int>
     */
    public function getFields(): array
    {
        return array_keys($this->config->get('fields'));
    }

    /**
     * Get the object data, returns the associative array ‘field name’
     * @param bool $withUpdates , optional default true
     * @return array<string,mixed>
     */
    public function getData(bool $withUpdates = true): array
    {
        $data = $this->data;
        $data[$this->primaryKey] = $this->id;

        if ($withUpdates) {
            foreach ($this->updates as $k => $v) {
                $data[$k] = $v;
            }
        }

        return $data;
    }

    /**
     * Get object name
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get object identifier
     * @return int|null
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Check if there are object property changes
     * not saved in the database
     * @return bool
     */
    public function hasUpdates(): bool
    {
        return !empty($this->updates);
    }

    /**
     * Get ORM configuration object (data structure helper)
     * @return Record\Config
     */
    public function getConfig(): Record\Config
    {
        return $this->config;
    }

    /**
     * Get updated, but not saved object data
     * @return array<string,mixed>
     * @throws Exception
     */
    public function getUpdates(): array
    {
        return $this->updates;
    }

    /**
     * Set the object identifier (existing DB ID)
     * @param int $id
     * @return void
     */
    public function setId(int $id): void
    {
        $this->id = $id;
    }

    /**
     * Commit the object data changes (without saving)
     * @return void
     */
    public function commitChanges(): void
    {
        if (empty($this->updates)) {
            return;
        }

        foreach ($this->updates as $k => $v) {
            $this->data[$k] = $v;
        }

        $this->updates = [];
    }

    /**
     * Check if the object field exists
     * @param string $name
     * @return bool
     */
    public function fieldExists(string $name): bool
    {
        return $this->config->fieldExists($name);
    }

    /**
     * Get the related object name for the field
     * (available if the object field is a link to another object)
     * @param string $field - field name
     * @return string|null
     */
    public function getLinkedObject(string $field): ?string
    {
        $link = $this->config->getField($field)->getLinkedObject();
        if (empty($link)) {
            return null;
        }
        return $link;
    }

    /**
     * Set the object properties using the associative array of fields and values
     * @param array<string,mixed> $values
     * @return void
     * @throws Exception
     */
    public function setValues(array $values): void
    {
        if (!empty($values)) {
            foreach ($values as $k => $v) {
                $this->set($k, $v);
            }
        }
    }

    /**
     * Set the object field val
     * @param string $name
     * @param mixed $value
     * @return void
     * @throws Exception
     */
    public function set(string $name, $value): void
    {
        $propConf = $this->config->getFieldConfig($name);
        $validator = $this->getConfig()->getValidator($name);

        $field = $this->getConfig()->getField($name);

        // set null for empty links
        if ($field->isObjectLink() && empty($value)) {
            $value = null;
        }

        // Validate value using special validator
        // Skip validation if value is null and object field can be null
        if (
            $validator &&
            (!$field->isNull() || !is_null($value)) &&
            !call_user_func_array([$validator, 'validate'], [$value])
        ) {
            throw new Exception('Invalid value for field ' . $name . ' (' . $this->getName() . ')');
        }

        $value = $field->filter($value);
        if (!$field->validate($value)) {
            throw new Exception(
                'Invalid value for field ' . $name . '. ' . $field->getValidationError() . ' (' . $this->getName() . ')'
            );
        }

        if (isset($propConf['db_len']) && $propConf['db_len']) {
            if (
                $propConf['db_type'] === 'bit' &&
                (strlen($value) > $propConf['db_len'] || strlen($value) < $propConf['db_len'])
            ) {
                throw new Exception('Invalid length for bit value [' . $name . ']  (' . $this->getName() . ')');
            }
        }

        if (array_key_exists($name, $this->data)) {
            if ($field->isBoolean() && ((int)$this->data[$name]) === ((int)$value)) {
                unset($this->updates[$name]);
                return;
            }

            if ($this->data[$name] === $value) {
                unset($this->updates[$name]);
                return;
            }
        }

        $this->updates[$name] = $value;
    }

    /**
     * @param string $key
     * @param mixed $value
     * @return void
     * @throws Exception
     */
    public function __set(string $key, $value): void
    {
        if ($key === $this->primaryKey) {
            $this->setId($value);
        } else {
            $this->set($key, $value);
        }
    }

    public function __isset(string $key): bool
    {
        if ($key === $this->primaryKey) {
            return isset($this->id);
        }

        if (!isset($this->data[$key]) && !isset($this->updates[$key])) {
            return false;
        }

        return true;
    }

    /**
     * @param string $key
     * @return mixed
     * @throws Exception
     */
    public function __get($key)
    {
        if ($key === $this->primaryKey) {
            return $this->getId();
        }

        return $this->get($key);
    }

    /**
     * Get the object field value
     * If field value was updated method returns new value
     * otherwise returns old value
     * @param string $name - field name
     * @return mixed
     * @throws Exception
     */
    public function get(string $name)
    {
        if ($name === $this->primaryKey) {
            return $this->getId();
        }

        if (!$this->fieldExists($name)) {
            throw new Exception('Invalid property requested [' . $name . ']');
        }

        $value = null;

        if (isset($this->data[$name])) {
            $value = $this->data[$name];
        }

        if (isset($this->updates[$name])) {
            $value = $this->updates[$name];
        }

        return $value;
    }

    /**
     * Get the initial object field value (received from the database)
     * whether the field value was updated or not
     * @param string $name - field name
     * @return mixed
     * @throws Exception
     */
    public function getOld(string $name)
    {
        if (!$this->fieldExists($name)) {
            throw new Exception('Invalid property requested [' . $name . ']');
        }
        return $this->data[$name];
    }

    /**
     * Add object error message
     * @param string $message
     */
    public function addErrorMessage(string $message): void
    {
        $this->errors[] = $message;
    }

    /**
     * Save changes
     * @param bool $useTransaction — using a transaction when changing data is optional.
     * If data update in your code is carried out within an external transaction
     * set the value to  false,
     * otherwise, the first update will lead to saving the changes
     * @return bool;
     * @throws Exception
     */
    public function save(bool $useTransaction = true): bool
    {
        $dataModel = $this->getDataModel();
        if ($dataModel->save($this, $useTransaction)) {
            return true;
        }
        return false;
    }

    /**
     * Deleting an object
     * @param bool $useTransaction — using a transaction when changing data is optional.
     * If data update in your code is carried out within an external transaction
     * set the value to  false,
     * otherwise, the first update will lead to saving the changes
     * @return bool - success flag
     */
    public function delete(bool $useTransaction = true): bool
    {
        $dataModel = $this->getDataModel();
        return $dataModel->delete($this, $useTransaction);
    }

    /**
     * Serialize Object List properties
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function serializeLinks(array $data): array
    {
        foreach ($data as $k => $v) {
            if ($this->config->getField($k)->isMultiLink()) {
                unset($data[$k]);
            }
        }
        return $data;
    }

    /**
     * Validate unique fields, object field groups
     * Returns array of errors or null .
     * @return  array<string,mixed> | null
     * @throws \Exception
     */
    public function validateUniqueValues(): ?array
    {
        $uniqGroups = [];

        foreach ($this->config->get('fields') as $k => $v) {
            if ($k === $this->primaryKey) {
                continue;
            }

            if (!$this->config->getField($k)->isUnique()) {
                continue;
            }

            $value = $this->get($k);
            if (is_array($value)) {
                $value = serialize($value);
            }

            if (is_array($v['unique'])) {
                foreach ($v['unique'] as $val) {
                    if (!isset($uniqGroups[$val])) {
                        $uniqGroups[(string)$val] = [];
                    }

                    $uniqGroups[(string)$val][$k] = $value;
                }
            } else {
                $v['unique'] = (string)$v['unique'];

                if (!isset($uniqGroups[$v['unique']])) {
                    $uniqGroups[$v['unique']] = [];
                }
                $uniqGroups[$v['unique']][$k] = $value;
            }
        }

        if (empty($uniqGroups)) {
            return null;
        }

        return $this->getDataModel()->validateUniqueValues($this, $uniqGroups);
    }

    /**
     * Convert object into string representation
     * @return string
     */
    public function __toString(): string
    {
        return (string)($this->getId());
    }

    /**
     * Get object title
     * @return string
     * @throws \Exception
     */
    public function getTitle(): string
    {
        return $this->orm->model($this->getName())->getTitle($this);
    }

    /**
     * Get errors
     * @return array<string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Unpublish VC object
     * @param bool $useTransaction — using a transaction when changing data is optional.
     * @return bool
     */
    public function unpublish(bool $useTransaction = true): bool
    {
        $dataModel = $this->getDataModel();
        return $dataModel->unpublish($this, $useTransaction);
    }

    /**
     * Publish VC object
     * @param int|null $version - optional, default current version
     * @param bool $useTransaction — using a transaction when changing data is optional.
     * @return bool
     * @throws \Exception
     */
    public function publish(?int $version = null, bool $useTransaction = true): bool
    {
        $dataModel = $this->getDataModel();
        return $dataModel->publish($this, $version, $useTransaction);
    }

    /**
     * Get loaded version
     * @return int
     */
    public function getVersion(): int
    {
        return $this->version;
    }

    /**
     * Load version
     * @param int $version
     * @return bool
     * @throws \Exception
     */
    public function loadVersion(int $version): bool
    {
        $dataModel = $this->getDataModel();
        return $dataModel->loadVersion($this, $version);
    }

    /**
     * Reject changes
     */
    public function rejectChanges(): void
    {
        $this->updates = [];
    }

    /**
     * Save object as new version
     * @param bool $useTransaction — using a transaction when changing data is optional.
     * @return bool
     * @throws \Exception
     */
    public function saveVersion(bool $useTransaction = true): bool
    {
        if (!$this->config->isRevControl()) {
            return (bool)$this->save($useTransaction);
        }
        $dataModel = $this->getDataModel();
        return $dataModel->saveVersion($this, $useTransaction);
    }

    /**
     * Set insert id for object (Should not exist in the database)
     * @param int $id
     */
    public function setInsertId(int $id): void
    {
        $this->insertId = $id;
    }

    /**
     * Get insert ID
     * @return int|null
     */
    public function getInsertId(): ?int
    {
        return $this->insertId;
    }

    /**
     * Check DB object class
     * @param string $name
     * @return bool
     */
    public function isInstanceOf(string $name): bool
    {
        $name = strtolower($name);
        return $name === $this->getName();
    }

    /**
     * Set data version
     * @param int $version
     */
    public function setVersion(int $version): void
    {
        $this->version = $version;
    }
}
