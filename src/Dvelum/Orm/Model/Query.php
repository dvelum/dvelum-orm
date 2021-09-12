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

namespace Dvelum\Orm\Model;

use Dvelum\Db\Adapter;
use Dvelum\Db;
use Dvelum\Db\Select\Filter;
use Dvelum\Orm\Model;
use Dvelum\Orm\Orm;
use Dvelum\Orm\Stat;

class Query
{
    public const SEARCH_TYPE_STARTS_WITH = 'starts';
    public const SEARCH_TYPE_CONTAINS = 'contains';
    public const SEARCH_TYPE_ENDS_WITH = 'ends';
    /**
     * @var Model $model
     */
    protected Model $model;
    /**
     * @var Adapter $db
     */
    protected Adapter $db;

    protected ?string $search = null;

    protected string $searchType = self::SEARCH_TYPE_CONTAINS;
    /**
     * @var array<int|string,mixed>|null
     */
    protected ?array $filters = null;
    /**
     * @var array{sort:string,dir:string,start:int,limit:int}|null
     * @phpstan-var array<string,int|string>| null
     */
    protected ?array $params = null;
    /**
     * @var array<int|string,mixed>
     */
    protected array $fields = ['*'];
    /**
     * @var array<int|string,mixed>|null
     */
    protected ?array $joins = null;
    protected string $table;
    protected ?string $tableAlias = null;

    protected Orm $orm;

    public function __construct(Orm $orm, Model $model)
    {
        $this->orm = $orm;
        $this->table = $model->table();
        $this->model = $model;
        $this->db = $model->getDbConnection();
    }

    /**
     * Change database connection
     * @param Adapter $connection
     * @return Query
     */
    public function setDbConnection(Adapter $connection): self
    {
        $this->db = $connection;
        return $this;
    }

    /**
     * @param string $table
     * @return Query
     */
    public function table(string $table): Query
    {
        $this->table = $table;
        return $this;
    }

    /**
     * @param string|null $alias
     * @return Query
     */
    public function tableAlias(?string $alias): Query
    {
        $this->tableAlias = $alias;
        return $this;
    }

    /**
     * @param array<int|string,mixed>|null $filters
     * @return Query
     */
    public function filters(?array $filters): Query
    {
        $this->filters = $filters;
        return $this;
    }

    /**
     * @param string|null $query
     * @param string $queryType
     * @return Query
     */
    public function search(?string $query, string $queryType = self::SEARCH_TYPE_CONTAINS): Query
    {
        $this->search = $query;
        $this->searchType = $queryType;
        return $this;
    }

    /**
     * @phpstan-param array<string,int|string> $params
     * @param array{sort:string,dir:string,start:int,limit:int}|null $params
     * @return Query
     */
    public function params(array $params): Query
    {
        $this->params = $params;
        return $this;
    }

    /**
     * @param mixed|null $fields
     * @return Query
     */
    public function fields($fields): Query
    {
        $this->fields = $fields;
        return $this;
    }

    /**
     * @param array<int|string,mixed>|null $joins
     * Config Example:
     * array(
     *        array(
     *            'joinType'=>   jonLeft/left , jonRight/right , joinInner/inner
     *            'table' => array / string
     *            'fields => array / string
     *            'condition'=> string
     *        )...
     * )
     * @return Query
     */
    public function joins(?array $joins): Query
    {
        $this->joins = $joins;
        return $this;
    }


    /**
     * Apply query filters
     * @param Db\Select $sql
     * @param array<int|string,mixed> $filters
     * @return void
     */
    public function applyFilters(Db\Select $sql, array $filters): void
    {
        /**
         * @var array<string,mixed> $filters
         */
        $filters = $this->clearFilters($filters);

        foreach ($filters as $k => $v) {
            if ($v instanceof Filter) {
                $v->applyTo($this->db, $sql);
            } else {
                if (is_array($v) && !empty($v)) {
                    $sql->where($this->db->quoteIdentifier($k) . ' IN(?)', $v);
                } elseif (is_bool($v)) {
                    $sql->where($this->db->quoteIdentifier($k) . ' = ' . ((int)$v));
                } elseif ((is_string($v) && strlen($v)) || is_numeric($v)) {
                    $sql->where($this->db->quoteIdentifier($k) . ' =?', $v);
                } elseif (is_null($v)) {
                    $sql->where($this->db->quoteIdentifier($k) . ' IS NULL');
                }
            }
        }
    }

    /**
     * Apply Search
     * @param Db\Select $sql
     * @param string $query
     * @param string $queryType
     * @return void
     */
    public function applySearch(Db\Select $sql, string $query, string $queryType): void
    {
        $searchFields = $this->model->getSearchFields();

        if (!empty($searchFields)) {
            if (empty($this->tableAlias)) {
                $alias = $this->table;
            } else {
                $alias = $this->tableAlias;
            }

            $q = [];

            foreach ($searchFields as $v) {
                switch ($queryType) {
                    case self::SEARCH_TYPE_CONTAINS:
                        $q[] = $alias . "." . $v . " LIKE(" . $this->db->quote('%' . $query . '%') . ")";
                        break;
                    case self::SEARCH_TYPE_STARTS_WITH:
                        $q[] = $alias . "." . $v . " LIKE(" . $this->db->quote($query . '%') . ")";
                        break;
                    case self::SEARCH_TYPE_ENDS_WITH:
                        $q[] = $alias . "." . $v . " LIKE(" . $this->db->quote('%' . $query) . ")";
                        break;
                }
            }
            $sql->where('(' . implode(' OR ', $q) . ')');
        }
    }

    /**
     * Apply query params (sorting and pagination)
     * @param Db\Select $sql
     * @param array{sort:string,dir:string,start:int,limit:int} $params
     * @phpstan-param array<string,int|string> $params
     */
    public function applyParams(Db\Select $sql, array $params): void
    {
        if (isset($params['limit'])) {
            $sql->limit((int)($params['limit']));
        }

        if (isset($params['start'])) {
            $sql->offset((int)($params['start']));
        }

        if (!empty($params['sort']) && !empty($params['dir'])) {
            if (is_array($params['sort']) && !is_array($params['dir'])) {
                $sort = [];
                foreach ($params['sort'] as $key => $field) {
                    if (!is_int($key)) {
                        $order = trim(strtolower($field));
                        if ($order === 'asc' || $order === 'desc') {
                            $sort[$key] = $order;
                        }
                    } else {
                        $sort[$field] = $params['dir'];
                    }
                }
                $sql->order($sort);
            } else {
                $sql->order([(string)$params['sort'] => $params['dir']]);
            }
        }
    }

    /**
     * Apply Join conditions
     * @param Db\Select $sql
     * @param array<array{joinType:string,table:mixed,condition:string,fields:array}> $joins
     */
    public function applyJoins(Db\Select $sql, array $joins): void
    {
        foreach ($joins as $config) {
            switch ($config['joinType']) {
                case 'joinLeft':
                case 'left':
                    $sql->join($config['table'], $config['condition'], $config['fields'], Db\Select::JOIN_LEFT);
                    break;
                case 'joinRight':
                case 'right':
                    $sql->join($config['table'], $config['condition'], $config['fields'], Db\Select::JOIN_RIGHT);
                    break;
                case 'joinInner':
                case 'inner':
                    $sql->join($config['table'], $config['condition'], $config['fields'], Db\Select::JOIN_INNER);
                    break;
            }
        }
    }

    /**
     * Prepare filter values , clean empty filters
     * @param array<int|string,mixed> $filters
     * @return array<int|string,mixed>
     */
    public function clearFilters(array $filters): array
    {
        $fields = $this->model->getLightConfig()->get('fields');
        foreach ($filters as $field => $val) {
            if (
                $val === false &&
                isset($fields[$field]) &&
                isset($fields[$field]['db_type']) &&
                $fields[$field]['db_type'] === 'boolean'
            ) {
                $filters[$field] = \Dvelum\Filter::filterValue(\Dvelum\Filter::FILTER_BOOLEAN, $val);
                continue;
            }

            if (!($val instanceof Db\Select\Filter) && !is_null($val) && (!is_array($val) && !strlen((string)$val))) {
                unset($filters[$field]);
                continue;
            }

            if (
                isset($fields[$field]) &&
                isset($fields[$field]['db_type']) &&
                $fields[$field]['db_type'] === 'boolean'
            ) {
                $filters[$field] = \Dvelum\Filter::filterValue(\Dvelum\Filter::FILTER_BOOLEAN, $val);
            }
        }
        return $filters;
    }


    /**
     * Prepare Db\Select object
     * @return Db\Select
     */
    public function sql(): Db\Select
    {
        $sql = $this->db->select();

        if (!empty($this->tableAlias)) {
            $sql->from([$this->tableAlias => (string)$this->table]);
        } else {
            $sql->from($this->table);
        }

        $sql->columns($this->fields);

        if (!empty($this->filters)) {
            $this->applyFilters($sql, $this->filters);
        }

        if ($this->search !== null) {
            $this->applySearch($sql, $this->search, $this->searchType);
        }

        if (!empty($this->params)) {
            $this->applyParams($sql, $this->params);
        }

        if (!empty($this->joins)) {
            $this->applyJoins($sql, $this->joins);
        }

        return $sql;
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        return $this->sql()->__toString();
    }

    /**
     * Fetch all records
     * @return array<int,array>
     * @throws \Exception
     */
    public function fetchAll(): array
    {
        try {
            return $this->db->fetchAll($this->__toString());
        } catch (\Exception $e) {
            $this->model->logError($e->getMessage());
            throw $e;
        }
    }

    /**
     * Fetch one
     * @return mixed
     * @throws \Exception
     */
    public function fetchOne()
    {
        try {
            return $this->db->fetchOne($this->__toString());
        } catch (\Exception $e) {
            $this->model->logError($e->getMessage());
            throw $e;
        }
    }

    /**
     * Fetch first result row
     * @return array<string,mixed>
     * @throws \Exception
     */
    public function fetchRow(): array
    {
        try {
            $result = $this->db->fetchRow($this->__toString());
            if (empty($result)) {
                $result = [];
            }
            return $result;
        } catch (\Exception $e) {
            $this->model->logError($e->getMessage());
            throw $e;
        }
    }

    /**
     * Fetch column
     * @return array<mixed>
     * @throws \Exception
     */
    public function fetchCol(): array
    {
        try {
            return $this->db->fetchCol($this->__toString());
        } catch (\Exception $e) {
            $this->model->logError($e->getMessage());
            throw $e;
        }
    }

    /**
     * Count the number of rows that satisfy the filters
     * @param bool $approximateValue - Get approximate count for innodb table (only for queries without filters)
     * @return int
     * @throws \Exception
     */
    public function getCount(bool $approximateValue = false): int
    {
        $joins = $this->joins;
        $filters = $this->filters;
        $query = $this->search;
        $searchType = $this->searchType;
        $tableAlias = $this->tableAlias;

        // disable fields selection
        if (!empty($joins)) {
            foreach ($joins as & $config) {
                $config['fields'] = [];
            }
            unset($config);
        }
        $count = 0;

        if ($approximateValue && empty($filters) && empty($query)) {
            $stat = $this->orm->stat();
            $config = $this->model->getObjectConfig();
            $data = $stat->getDetails($config->getName(), $this->db);
            if (!empty($data) && isset($data[0]) && isset($data[0]['records'])) {
                $count = (int)str_replace(' ', '', $data[0]['records']);
            }
        }

        // get exact count
        if ($count < 10000) {
            $sqlQuery = new Model\Query($this->orm, $this->model);
            $sqlQuery->setDbConnection($this->db);
            $sqlQuery->fields(['count' => 'COUNT(*)'])->tableAlias($tableAlias)
                ->filters($filters)->search($query, $searchType)
                ->joins($joins);

            if (!empty($this->tableAlias)) {
                $sqlQuery->tableAlias((string)$this->tableAlias);
            }

            $count = $sqlQuery->fetchOne();
        }


        if (empty($count)) {
            $count = 0;
        }
        return (int)$count;
    }
}
