<?php

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
     * @param int|null $start
     * @param int|null $limit
     */
    public function addLimit(?int $start, ?int $limit): void
    {
        $this->pagination['start'] = $start;
        $this->pagination['limit'] = $limit;
    }

    public function resetFilter($key): void
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
     * @return array{start:int,limit:int,sort:string,dir:string}|null
     */
    public function getPagination(): ?array
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
