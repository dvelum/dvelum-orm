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

use Dvelum\Orm\Record\Config\Field;

interface RecordInterface
{
    /**
     * Set raw data from storage
     * @param array<int|string,mixed> $data
     * @return void
     */
    public function setRawData(array $data): void;

    /**
     * Get object fields
     * @return array<string>
     */
    public function getFields(): array;

    /**
     * Get the object data, returns the associative array ‘field name’
     * @param bool $withUpdates , optional default true
     * @return array<int|string,mixed>
     */
    public function getData(bool $withUpdates = true): array;

    /**
     * Get object name
     * @return string
     */
    public function getName(): string;

    /**
     * Get object identifier
     * @return int|null
     */
    public function getId();

    /**
     * Check if there are object property changes
     * not saved in the database
     * @return bool
     */
    public function hasUpdates(): bool;

    /**
     * Get ORM configuration object (data structure helper)
     * @return Record\Config
     */
    public function getConfig(): Record\Config;

    /**
     * Get updated, but not saved object data
     * @return array<int|string,mixed>
     */
    public function getUpdates(): array;

    /**
     * Set the object identifier (existing DB ID)
     * @param int $id
     * @return void
     */
    public function setId(int $id): void;

    /**
     * Commit the object data changes (without saving)
     * @return void
     */
    public function commitChanges(): void;

    /**
     * Check if the object field exists
     * @param string $name
     * @return bool
     */
    public function fieldExists(string $name): bool;

    /**
     * Get the related object name for the field
     * (available if the object field is a link to another object)
     * @param string $field - field name
     * @return string|null
     */
    public function getLinkedObject(string $field): ?string;

    /**
     * Set the object properties using the associative array of fields and values
     * @param array<int|string,mixed> $values
     * @return void
     * @throws Exception
     */
    public function setValues(array $values): void;

    /**
     * Set the object field val
     * @param string $name
     * @param mixed $value
     * @return void
     * @throws Exception
     */
    public function set(string $name, $value): void;

    /**
     * Get the object field value
     * If field value was updated method returns new value
     * otherwise returns old value
     * @param string $name - field name
     * @return mixed
     * @throws Exception
     */
    public function get(string $name);

    /**
     * Get the initial object field value (received from the database)
     * whether the field value was updated or not
     * @param string $name - field name
     * @return mixed
     * @throws Exception
     */
    public function getOld(string $name);

    /**
     * Save changes
     * @param bool $useTransaction — using a transaction when changing data is optional.
     * If data update in your code is carried out within an external transaction
     * set the value to  false,
     * otherwise, the first update will lead to saving the changes
     * @return int | bool;
     */
    public function save(bool $useTransaction = true);

    /**
     * Deleting an object
     * @param bool $useTransaction — using a transaction when changing data is optional.
     * If data update in your code is carried out within an external transaction
     * set the value to  false,
     * otherwise, the first update will lead to saving the changes
     * @return bool - success flag
     */
    public function delete(bool $useTransaction = true): bool;

    /**
     * Serialize Object List properties
     * @param array<int|string,mixed> $data
     * @return array<int|string,mixed>
     */
    public function serializeLinks(array $data): array;

    /**
     * Validate unique fields, object field groups
     * Returns array of errors  or null .
     * @return array<int|string,mixed> | null
     */
    public function validateUniqueValues(): ?array;

    /**
     * Convert object into string representation
     * @return string
     */
    public function __toString(): string;

    /**
     * Get object title
     */
    public function getTitle(): string;

    /**
     * Get errors
     * @return array<int|string,mixed>
     */
    public function getErrors(): array;

    /**
     * Unpublish VC object
     * @param bool $useTransaction — using a transaction when changing data is optional.
     * @return bool
     */
    public function unpublish(bool $useTransaction = true): bool;

    /**
     * Publish VC object
     * @param int|null $version - optional, default current version
     * @param bool $useTransaction — using a transaction when changing data is optional.
     * @return bool
     * @throws Exception
     */
    public function publish(?int $version = null, bool $useTransaction = true): bool;

    /**
     * Get loaded version
     * @return int
     */
    public function getVersion(): int;

    /**
     * Load version
     * @param int $vers
     * @return bool
     * @throws Exception
     */
    public function loadVersion(int $vers): bool;

    /**
     * Reject changes
     */
    public function rejectChanges(): void;

    /**
     * Save object as new version
     * @param bool $useTransaction — using a transaction when changing data is optional.
     * @return bool
     */
    public function saveVersion(bool $useTransaction = true): bool;

    /**
     * Set insert id for object (Should not exist in the database)
     * @param int $id
     */
    public function setInsertId(int $id): void;

    /**
     * Get insert ID
     * @return int|null
     */
    public function getInsertId(): ?int;

    /**
     * Check DB object class
     * @param string $name
     * @return bool
     */
    public function isInstanceOf(string $name): bool;

    /**
     * Add error message
     * @param string $message
     */
    public function addErrorMessage(string $message): void;

    /**
     * Set data version identifier
     * @param int $version
     */
    public function setVersion(int $version): void;
}
