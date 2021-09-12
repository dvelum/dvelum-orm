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

namespace Dvelum\App\Orm\Data\Api;

use Dvelum\Config;

class Request
{
    protected string $object;
    /**
     * @var array{start:int,limit:int,sort:string,dir:string}
     */
    protected array $pagination;
    protected ?string $query;
    /**
     * @var array<mixed>
     */
    protected array $filters;
    /**
     * @var Config\ConfigInterface<string,mixed>
     */
    protected Config\ConfigInterface $config;

    protected string $shard;

    /**
     * @param Config\ConfigInterface<string,mixed> $config
     * @param \Dvelum\Request $request
     * @throws \Exception
     */
    public function __construct(Config\ConfigInterface $config, \Dvelum\Request $request)
    {
        $this->config = $config;
        $pagination = $request->post($this->config->get('paginationParam'), 'array', []);

        if (isset($pagination['start'])) {
            $this->pagination['start'] = (int)$pagination['start'];
        }
        if (isset($pagination['limit'])) {
            $this->pagination['limit'] = (int)$pagination['limit'];
        }

        $this->filters = array_merge(
            $request->post($this->config->get('filterParam'), 'array', []),
            $request->extFilters()
        );
        $this->query = $request->post($this->config->get('searchParam'), 'string', null);
        $this->object = $request->post($this->config->get('objectParam'), 'string', '');
        $this->shard = $request->post($this->config->get('shardParam'), 'string', '');
    }

    /**
     * @return array<int|string,mixed>
     */
    public function getFilters(): array
    {
        return $this->filters;
    }

    /**
     * @param string $name
     * @return mixed|null
     */
    public function getFilter(string $name)
    {
        if (isset($this->filters[$name])) {
            return $this->filters[$name];
        }
        return null;
    }

    /**
     * @param string $key
     * @param mixed $val
     */
    public function addFilter(string $key, $val): void
    {
        $this->filters[$key] = $val;
    }

    /**
     * Set sorter
     * @param string $field
     * @param string $direction
     */
    public function addSort(string $field, string $direction = 'ASC'): void
    {
        $this->pagination['sort'] = $field;
        $this->pagination['dir'] = $direction;
    }

    /**
     * Set limitation
     * @param int $start
     * @param int $limit
     */
    public function addLimit(int $start, int $limit): void
    {
        $this->pagination['start'] = $start;
        $this->pagination['limit'] = $limit;
    }

    public function resetFilter(string $key): void
    {
        unset($this->filters[$key]);
    }

    public function setObjectName(string $name): void
    {
        $this->object = $name;
    }

    public function getObjectName(): string
    {
        return $this->object;
    }

    /**
     * @return array{start:int,limit:int,sort:string,dir:string}
     */
    public function getPagination(): array
    {
        return $this->pagination;
    }

    public function getQuery(): ?string
    {
        return $this->query;
    }


    /**
     * @return string
     */
    public function getShard(): string
    {
        return $this->shard;
    }

    /**
     * @param mixed $shard
     */
    public function setShard($shard): void
    {
        $this->shard = $shard;
    }
}
