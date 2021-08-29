<?php

declare(strict_types=1);

namespace Dvelum\App\Orm\Api;

use Dvelum\App\Router\RouterInterface;
use Dvelum\Config\Storage\StorageInterface;
use Dvelum\Lang;
use Dvelum\Lang\Dictionary;
use Dvelum\Orm\Orm;
use Dvelum\Orm\Record\BuilderFactory;
use Dvelum\Orm\Record\Builder\AbstractAdapter;
use Dvelum\Request;
use Dvelum\Response;
use Psr\Container\ContainerInterface;

abstract class Controller
{
    protected Request $request;
    protected Response $response;
    protected ContainerInterface $container;
    protected RouterInterface $router;
    protected Orm $ormService;
    protected Dictionary $lang;
    protected bool $canEdit;
    protected bool $canDelete;
    protected StorageInterface $configStorage;

    public function __construct(
        Request $request,
        Response $response,
        ContainerInterface $container,
        bool $canEdit = true,
        bool $canDelete = true
    ) {
        $this->request = $request;
        $this->response = $response;
        $this->container = $container;
        $this->ormService = $container->get(Orm::class);
        $this->lang = $container->get(Lang::class)->getDictionary();
        $this->canEdit = $canEdit;
        $this->canDelete = $canDelete;
        $this->configStorage = $container->get(StorageInterface::class);
         /*
          * @todo remove bakward compat
          */
        \Dvelum\Orm::setContainer($container);
    }

    public function setRouter(RouterInterface $router): void
    {
        $this->router = $router;
    }

    public function checkCanEdit(): bool
    {
        return $this->canEdit;
    }

    public function checkCanDelete(): bool
    {
        return $this->canDelete;
    }
}