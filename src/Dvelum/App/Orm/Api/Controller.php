<?php

declare(strict_types=1);

namespace Dvelum\App\Orm\Api;

use Dvelum\App\Router\RouterInterface;
use Dvelum\Lang;
use Dvelum\Lang\Dictionary;
use Dvelum\Orm\Orm;
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

    public function __construct(Request $request, Response $response, ContainerInterface $container, bool $canEdit)
    {
        $this->request = $request;
        $this->response = $response;
        $this->container = $container;
        $this->ormService = $container->get(Orm::class);
        $this->lang = $container->get(Lang::class)->getDictionary();
        $this->canEdit = $canEdit;
    }

    public function setRouter(RouterInterface $router) : void
    {
        $this->router = $router;
    }

    public function checkCanEdit() : bool
    {
        return $this->canEdit;
    }
}