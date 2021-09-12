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

namespace Dvelum\App\Orm\Data;

use Dvelum\Orm;
use Dvelum\Orm\Model;
use Dvelum\App\Session\User;

class Api
{
    /**
     * @var Api\Request
     */
    protected Api\Request $apiRequest;
    /**
     * @var array<int|string,string>
     */
    protected array $fields = [];

    /**
     * @var Model\Query|Orm\Distributed\Model\Query
     */
    protected Model\Query $dataQuery;
    /**
     * @var bool $useApproximateCount
     */
    protected bool $useApproximateCount = false;

    protected Orm\Orm $orm;

    public function __construct(Api\Request $request, Orm\Orm $orm)
    {
        $this->apiRequest = $request;
        $this->orm = $orm;

        $object = $this->apiRequest->getObjectName();
        $ormObjectConfig = $this->orm->config($object);

        $model = $this->orm->model($object);

        if ($ormObjectConfig->isDistributed() && empty($this->apiRequest->getShard())) {
            $model = $this->orm->model($ormObjectConfig->getDistributedIndexObject());
        }

        $this->dataQuery = $model->query()
            ->params($this->apiRequest->getPagination())
            ->filters($this->apiRequest->getFilters())
            ->search($this->apiRequest->getQuery());

        if ($ormObjectConfig->isDistributed() && !empty($this->apiRequest->getShard())) {
            /**
             * @var Orm\Distributed\Model\Query $dataQuery
             */
            $dataQuery = $this->dataQuery;
            $dataQuery->setShard($this->apiRequest->getShard());
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     * @throws Orm\Exception
     */
    public function getList(): array
    {
        if (empty($this->fields)) {
            $fields = $this->getDefaultFields();
        } else {
            $fields = $this->fields;
        }

        $object = $this->apiRequest->getObjectName();
        $ormObjectConfig = $this->orm->config($object);
        if ($ormObjectConfig->isDistributed() && empty($this->apiRequest->getShard())) {
            $indexConfig = $this->orm->config($ormObjectConfig->getDistributedIndexObject());
            $fields = array_keys($indexConfig->getFields());
        }
        return $this->dataQuery->fields($fields)->fetchAll();
    }

    public function getCount(): int
    {
        return $this->dataQuery->getCount($this->isUseApproximateCount());
    }

    /**
     * Set fields to be fetched
     * @param array<int|string,string> $fields
     */
    public function setFields(array $fields): void
    {
        $this->fields = $fields;
    }

    /**
     * Get list of fields to be fetched
     * @return array<int|string,string>
     */
    public function getFields(): array
    {
        if (empty($this->fields)) {
            return $this->getDefaultFields();
        }
        return $this->fields;
    }

    /**
     * Get default field list
     * @return array<int|string,string>
     */
    protected function getDefaultFields(): array
    {
        $result = [];
        $objectName = $this->apiRequest->getObjectName();
        $config = $this->orm->config($objectName);

        $fields = $config->getFields();
        foreach ($fields as $v) {
            if ($v->isText() || $v->isMultiLink()) {
                continue;
            }
            $result[] = $v->getName();
        }
        return $result;
    }

    /**
     * @return bool
     */
    public function isUseApproximateCount(): bool
    {
        return $this->useApproximateCount;
    }

    /**
     * @param bool $useApproximateCount
     */
    public function setUseApproximateCount(bool $useApproximateCount): void
    {
        $this->useApproximateCount = $useApproximateCount;
    }

    /**
     * @return Model\Query
     */
    public function getDataQuery(): Model\Query
    {
        return $this->dataQuery;
    }
}
