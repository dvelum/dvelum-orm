<?php

namespace Dvelum\App\Orm\Data\Api;

use Dvelum\Config;

class Request
{
    protected string $object;
    /**
     * @var array<mixed>
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

    public function __construct(\Dvelum\Request $request)
    {
        $this->config = Config::storage()->get('api/request.php');
        $this->pagination = $request->post($this->config->get('paginationParam'), 'array', []);
        $this->filters = array_merge(
            $request->post($this->config->get('filterParam'), 'array', []),
            $request->extFilters()
        );
        $this->query = $request->post($this->config->get('searchParam'), 'string', null);
        $this->object = $request->post($this->config->get('objectParam'), 'string', '');
        $this->shard = $request->post($this->config->get('shardParam'), 'string', '');
    }

    public function getFilters() : array
    {
        return $this->filters;
    }

    public function getFilter(string $name)
    {
        if (isset($this->filters[$name])) {
            return $this->filters[$name];
        }
        return null;
    }

    public function addFilter(string $key, $val) : void
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
     * @param int|null $start
     * @param int|null $limit
     */
    public function addLimit(?int $start, ?int $limit)
    {
        $this->pagination['start'] = $start;
        $this->pagination['limit'] = $limit;
    }

    public function resetFilter($key)
    {
        unset($this->filters[$key]);
    }

    public function setObjectName(string $name)
    {
        $this->object = $name;
    }

    public function getObjectName(): string
    {
        return $this->object;
    }

    public function getPagination()
    {
        return $this->pagination;
    }

    public function getQuery()
    {
        return $this->query;
    }


    /**
     * @return mixed
     */
    public function getShard()
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
