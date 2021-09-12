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

namespace Dvelum\App\Form\Adapter\Orm;

use Dvelum\App\Form;
use Dvelum\Orm;

class Record extends Form\Adapter
{
    /**
     * @var Orm\Record|null $object
     */
    protected ?Orm\Record $object;

    public function validateRequest(): bool
    {
        if (empty($this->config->get('orm_object'))) {
            throw new \Exception(get_called_class() . ': orm_object is not set');
        }

        $this->object = null;

        $id = $this->request->post(
            $this->config->get('idField'),
            $this->config->get('idFieldType'),
            $this->config->get('idFieldDefault')
        );
        $shard = $this->request->post(
            $this->config->get('shardField'),
            $this->config->get('shardFieldType'),
            $this->config->get('shardFieldDefault')
        );

        if (empty($id)) {
            $id = null;
        }

        if (empty($shard)) {
            $shard = null;
        }

        try {
            /**
             * @var Orm\Record $obj
             */
            $obj = Orm::factory()->record($this->config->get('orm_object'), $id, $shard);
        } catch (\Exception $e) {
            $this->errors[] = new Form\Error($this->lang->get('CANT_EXEC'), null, 'init_object');
            return false;
        }

        $posted = $this->request->postArray();

        $fields = $this->getFields($obj);

        $objectConfig = $obj->getConfig();

        foreach ($fields as $name) {
            /*
             * skip primary field
             */
            if ($name === $this->config->get('idField')) {
                continue;
            }

            $field = $objectConfig->getField($name);

            if (
                $field->isRequired() &&
                !$objectConfig->getField($name)->isSystem() &&
                (!isset($posted[$name]) || !strlen($posted[$name]))
            ) {
                $this->errors[] = new Form\Error($this->lang->get('CANT_BE_EMPTY'), $name);
                continue;
            }

            if ($field->isBoolean() && !isset($posted[$name])) {
                $posted[$name] = false;
            }

            if (($field->isNull() || $field->isDateField()) && isset($posted[$name]) && empty($posted[$name])) {
                $posted[$name] = null;
            }


            if (!array_key_exists($name, $posted)) {
                continue;
            }

            if (
                !$id &&
                (
                    (is_string($posted[$name]) && !strlen((string)$posted[$name])) ||
                    (
                        is_array($posted[$name]) && empty($posted[$name])
                    )
                ) &&
                $field->hasDefault()
            ) {
                continue;
            }

            try {
                $obj->set($name, $posted[$name]);
            } catch (\Exception $e) {
                $this->errors[] = new Form\Error($this->lang->get('INVALID_VALUE'), $name);
            }
        }

        if (!empty($this->errors)) {
            return false;
        }

        if ($this->config->get('validateUnique')) {
            $errorList = $obj->validateUniqueValues();
            if (!empty($errorList)) {
                foreach ($errorList as $field) {
                    $this->errors[] = new Form\Error($this->lang->get('SB_UNIQUE'), $field);
                }
                return false;
            }
        }

        if ($id) {
            $obj->setId($id);
        }


        $this->object = $obj;
        return true;
    }

    /**
     * @param Orm\RecordInterface $object
     * @return array<string>
     */
    protected function getFields(Orm\RecordInterface $object): array
    {
        return $object->getFields();
    }

    /**
     * @return Orm\Record|null
     */
    public function getData(): ?Orm\Record
    {
        return $this->object;
    }
}
