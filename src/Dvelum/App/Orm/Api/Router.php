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

use Dvelum\App\Orm\Api\Connections;
use Dvelum\Config;
use Dvelum\File;
use Dvelum\Orm;
use Dvelum\Lang;
use Dvelum\Request;
use Dvelum\Response\Response;
use Dvelum\Response\ResponseInterface;
use Dvelum\App\Router\RouterInterface;
use Dvelum\Utils;
use Psr\Container\ContainerInterface;

class Router implements RouterInterface
{
    protected ContainerInterface $container;
    /**
     * @var array<string,mixed> $routes
     */
    protected array $routes;

    private Request $request;
    private ResponseInterface $response;
    private Lang\Dictionary $lang;
    private int $pathIndex;

    /**
     * User has edit permissions
     * @var bool $canEdit
     */
    private bool $canEdit;
    private bool $canDelete;

    public function __construct(
        ContainerInterface $container,
        int $pathIndex,
        bool $canEdit = true,
        bool $canDelete = true
    ) {
        $this->canEdit = $canEdit;
        $this->canDelete = $canDelete;
        $this->pathIndex = $pathIndex;

        $this->container = $container;
        $this->lang = $container->get(Lang::class)->getDictionary();

        $configStorage = $this->container->get(Config\Storage\StorageInterface::class);
        $this->routes = $configStorage->get('orm/routes.php')->__toArray();
    }

    public function route(Request $request, ResponseInterface $response): ResponseInterface
    {
        $this->request = $request;
        $this->response = $response;
        $response->setFormat(ResponseInterface::FORMAT_JSON);

        $action = $request->getPart($this->pathIndex);

        if (isset($this->routes[$action])) {
            $router = new self($this->container, $this->pathIndex, $this->canEdit);
            return $router->runController(
                $this->routes[$action],
                $request->getPart($this->pathIndex + 1),
                $request,
                $response
            );
        }

        if (method_exists($this, $action . 'Action')) {
            $this->{$action . 'Action'}();
        } else {
            $this->indexAction();
        }

        if (!$response->isSent()) {
            $response->send();
        }

        return $response;
    }

    public function indexAction(): void
    {
        $this->response->error($this->lang->get('WRONG_REQUEST'));
    }

    /**
     * Get DB Objects list
     */
    public function listAction(): void
    {
        /**
         * @var Orm\Stat $stat
         */
        $stat = $this->container->get(\Dvelum\Orm\Stat::class);
        $data = $stat->getInfo();

        if ($this->request->post('hideSysObj', 'boolean', false)) {
            foreach ($data as $k => $v) {
                if ($v['system']) {
                    unset($data[$k]);
                }
            }
            sort($data);
        }
        $this->response->success($data);
    }

    /**
     * Get Data info
     */
    public function listDetailsAction(): void
    {
        /**
         * @var Orm\Orm $orm
         */
        $orm = $this->container->get(Orm\Orm::class);

        $object = $this->request->post('object', 'string', '');

        if (!$orm->configExists($object)) {
            $this->response->error($this->lang->get('WRONG_REQUEST'));
            return;
        }

        /**
         * @var Orm\Stat $stat
         */
        $stat = $this->container->get(\Dvelum\Orm\Stat::class);

        $config = $orm->config($object);
        if ($config->isDistributed()) {
            $data = $stat->getDistributedDetails($object);
        } else {
            $data = $stat->getDetails($object);
        }
        $this->response->success($data);
    }

    private function checkCanEdit(): bool
    {
        return $this->canEdit;
    }

    private function checkCanDelete(): bool
    {
        return $this->canDelete;
    }

    /**
     * Build all objects action
     */
    public function buildAllAction(): void
    {
        if (!$this->checkCanEdit()) {
            return;
        }

        session_write_close();

        $dbObjectManager = $this->container->get(Orm\Orm::class)->getRecordManager();
        $names = $dbObjectManager->getRegisteredObjects();
        if (empty($names)) {
            $names = [];
        }

        $configStorage = $this->container->get(Config\Storage\StorageInterface::class);

        $flag = false;
        $ormConfig = $configStorage->get('orm.php');
        if ($ormConfig->get('foreign_keys')) {
            /*
             * build only fields
             */
            foreach ($names as $name) {
                try {
                    $builder = $this->createBuilder($name);
                    $builder->build(false);
                } catch (\Exception $e) {
                    $flag = true;
                }
            }
            /*
             * Add foreign keys
             */
            foreach ($names as $name) {
                try {
                    $builder = $this->createBuilder($name);
                    if (!$builder->buildForeignKeys(true, true)) {
                        $flag = true;
                    }
                } catch (\Exception $e) {
                    $flag = true;
                }
            }
        } else {
            foreach ($names as $name) {
                try {
                    $builder = $this->createBuilder($name);
                    $builder->build();
                } catch (\Exception $e) {
                    $flag = true;
                }
            }
        }

        /**
         * @var Orm\Orm $orm
         */
        $orm = $this->container->get(Orm\Orm::class);

        if ($ormConfig->get('sharding')) {
            $sharding = $configStorage->get('sharding.php');
            $shardsFile = $sharding->get('shards');
            $shardsConfig = $configStorage->get($shardsFile);
            $registeredObjects = $dbObjectManager->getRegisteredObjects();
            if (empty($registeredObjects)) {
                $registeredObjects = [];
            }

            foreach ($shardsConfig as $item) {
                $shardId = $item['id'];
                //build objects
                foreach ($names as $index => $object) {
                    if (!$orm->config($object)->isDistributed()) {
                        unset($registeredObjects[$index]);
                        continue;
                    }
                    $builder = $this->createBuilder($object);
                    $builder->setConnection($orm->model($object)->getDbShardConnection($shardId));
                    if (!$builder->build(false, true)) {
                        $flag = true;
                    }
                }

                //build foreign keys
                if ($ormConfig->get('foreign_keys')) {
                    foreach ($registeredObjects as $index => $object) {
                        $builder = $this->createBuilder($object);
                        $builder->setConnection($orm->model($object)->getDbShardConnection($shardId));
                        if (!$builder->build(true, true)) {
                            $flag = true;
                        }
                    }
                }
            }
        }

        if ($flag) {
            $this->response->error($this->lang->get('CANT_EXEC'));
            return;
        }
        $this->response->success();
    }

    /**
     * @param string $objectName
     * @return Orm\Record\Builder\AbstractAdapter
     * @throws Orm\Exception
     */
    protected function createBuilder(string $objectName): Orm\Record\Builder\AbstractAdapter
    {
        /**
         * @var Orm\Orm $orm
         */
        $orm = $this->container->get(Orm\Orm::class);
        return $orm->getBuilder($objectName);
    }

    /**
     * Get list of database connections
     */
    public function connectionsListAction(): void
    {
        $manager = new Connections(
            $this->container->get('config.main')->get('db_configs'),
            $this->container->get(Config\Storage\StorageInterface::class),
            $this->container->get(Lang::class)->getDictionary(),
        );
        $list = $manager->getConnections(0);
        $data = [];
        if (!empty($list)) {
            foreach ($list as $k => $v) {
                $data[] = ['id' => $k];
            }
        }
        $this->response->success($data);
    }

    /*
     * Get connection types (prod , dev , test ... etc)
    */
    public function connectionTypesAction(): void
    {
        $config = $this->container->get('config.main');
        $data = [];
        foreach ($config->get('db_configs') as $k => $v) {
            $data[] = ['id' => $k, 'title' => $this->lang->get($v['title'])];
        }
        $this->response->success($data);
    }

    /*
     * Get list of field validators
     */
    public function listValidatorsAction(): void
    {
        $validators = [];
        $files = File::scanFiles('./extensions/dvelum-core/src/Dvelum/Validator', ['.php'], false, File::FILES_ONLY);

        foreach ($files as $v) {
            $name = substr(basename($v), 0, -4);
            if ($name !== 'ValidatorInterface') {
                $validators[] = ['id' => '\\Dvelum\\Validator\\' . $name, 'title' => $name];
            }
        }

        $this->response->success($validators);
    }

    /**
     * Dev. method. Compile JavaScript sources
     */
    public function compileAction(): void
    {
        $config = $this->container->get('config.main');

        $sources = [
            'js/app/system/orm/panel.js',
            'js/app/system/orm/dataGrid.js',
            'js/app/system/orm/objectWindow.js',
            'js/app/system/orm/fieldWindow.js',
            'js/app/system/orm/indexWindow.js',
            'js/app/system/orm/dictionaryWindow.js',
            'js/app/system/orm/objectsMapWindow.js',
            'js/app/system/orm/dataViewWindow.js',
            'js/app/system/orm/objectField.js',
            'js/app/system/orm/connections.js',
            'js/app/system/orm/logWindow.js',
            'js/app/system/orm/import.js',
            'js/app/system/orm/taskStatusWindow.js',
            'js/app/system/orm/selectObjectsWindow.js',
            'js/app/system/orm/validate.js'

        ];

        if (!$config->get('development')) {
            die('Use development mode');
        }

        $s = '';
        $totalSize = 0;

        $wwwPath = $config->get('wwwPath');
        foreach ($sources as $filePath) {
            $s .= file_get_contents($wwwPath . $filePath) . "\n";
            $totalSize += filesize($wwwPath . $filePath);
        }

        $time = microtime(true);
        file_put_contents($wwwPath . 'js/app/system/ORM.js', \Dvelum\App\Code\Minify\Minify::factory()->minifyJs($s));
        echo '
            Compilation time: ' . number_format(microtime(true) - $time, 5) . ' sec<br>
            Files compiled: ' . sizeof($sources) . ' <br>
            Total size: ' . Utils::formatFileSize($totalSize) . '<br>
            Compiled File size: ' . Utils::formatFileSize((int)filesize($wwwPath . 'js/app/system/ORM.js')) . ' <br>
        ';
        exit;
    }

    /**
     * Find url
     * @param string $module
     * @return string
     */
    public function findUrl(string $module): string
    {
        return '';
    }

    /**
     * Run controller
     * @param string $controller
     * @param null|string $action
     * @param Request $request
     * @param ResponseInterface $response
     * @throws \Exception
     */
    public function runController(
        string $controller,
        ?string $action,
        Request $request,
        ResponseInterface $response
    ): ResponseInterface {
        if (!class_exists($controller)) {
            throw new \Exception('Undefined Controller: ' . $controller);
        }

        /**
         * @var \Dvelum\App\Controller $controller
         */
        $controller = new $controller(
            $request,
            $response,
            $this->container,
            $this->checkCanEdit(),
            $this->checkCanDelete()
        );
        $controller->setRouter($this);

        if ($response->isSent()) {
            return $response;
        }

        if ($controller instanceof RouterInterface) {
            $controller->route($request, $response);
        } else {
            if (empty($action)) {
                $action = 'index';
            }

            if (!method_exists($controller, $action . 'Action')) {
                $action = 'index';
                if (!method_exists($controller, $action . 'Action')) {
                    $response->error(
                        $this->container->get(Lang::class)->lang()->get('WRONG_REQUEST') . ' ' . $request->getUri()
                    );
                    return $response;
                }
            }
            if ($action !== 'index') {
                // Default JSON response from server actions
                $response->setFormat(Response::FORMAT_JSON);
            }
            $controller->{$action . 'Action'}();
        }

        if (!$response->isSent() && method_exists($controller, 'showPage')) {
            $controller->showPage();
        }
        return $response;
    }
}
