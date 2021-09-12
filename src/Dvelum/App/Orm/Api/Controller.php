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

namespace Dvelum\App\Orm\Api;

use Dvelum\App\Router\RouterInterface;
use Dvelum\Config\Storage\StorageInterface;
use Dvelum\Lang;
use Dvelum\Lang\Dictionary;
use Dvelum\Orm\Orm;
use Dvelum\Orm\Record\BuilderFactory;
use Dvelum\Orm\Record\Builder\AbstractAdapter;
use Dvelum\Request;
use Dvelum\Response\ResponseInterface;
use Psr\Container\ContainerInterface;

abstract class Controller
{
    protected Request $request;
    protected ResponseInterface $response;
    protected ContainerInterface $container;
    protected RouterInterface $router;
    protected Orm $ormService;
    protected Dictionary $lang;
    protected bool $canEdit;
    protected bool $canDelete;
    protected StorageInterface $configStorage;

    public function __construct(
        Request $request,
        ResponseInterface $response,
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
         * @todo remove backward compatibility
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
