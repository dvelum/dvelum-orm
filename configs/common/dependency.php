<?php

use Psr\Container\ContainerInterface as c;
use Dvelum\DependencyContainer\Argument;
use Dvelum\DependencyContainer\CallableArgument;
use Dvelum\DependencyContainer\ContainerArgument;

return [
    'config.shards' => static function (c $c) {
        $storage = $c->get(\Dvelum\Config\Storage\StorageInterface::class);
        $shards = $storage->get($storage->get('sharding.php')->get('shards'))->__toArray();
        $result = [];
        foreach ($shards as $item){
            $result[$item['id']] = $item;
        }
        return $result;
    },
    \Dvelum\Db\ManagerInterface::class => [
        'class' => \Dvelum\Db\OrmManager::class,
        'arguments' => [
            new CallableArgument(static function (c $c) {
                $config = $c->get('config.main');
                $useProfiler = false;
                if ($config->get('development') && $config->get('debug_panel')) {
                    $useProfiler = $c->get(\Dvelum\Config\Storage\StorageInterface::class)->get('debug_panel.php')->get(
                        'options'
                    )['sql'];
                }
                $config->set('use_db_profiler', $useProfiler);
                return $config;
            }),
            new Argument('config.shards')
        ]
    ],
    \Dvelum\Orm\Orm::class => [
        'class' => \Dvelum\Orm\Orm::class,
        'arguments' => [
            new CallableArgument(static function (c $c) {
                return $c->get(\Dvelum\Config\Storage\StorageInterface::class)->get('orm.php');
            }),
            new Argument(\Dvelum\Db\ManagerInterface::class),
            new CallableArgument(static function (c $c) {
                return $c->get('config.main')->get('language');
            }),
            new Argument(\Dvelum\Lang::class),
            new Argument(\Dvelum\Config\Storage\StorageInterface::class),
            new CallableArgument(static function(c $c){
                return static function () use ($c){
                    return $c->get(\Dvelum\Orm\Distributed::class);
                };
            }),
            new CallableArgument(static function(c $c){
                return static function () use ($c){
                    return $c->get(\Dvelum\Orm\Record\Config\FieldFactory::class);
                };
            }),
            new Argument(\Dvelum\Cache\CacheInterface::class),
        ]
    ],
    \Dvelum\Orm\Stat::class => [
        'class' => \Dvelum\Orm\Stat::class,
        'arguments' => [
            new Argument(\Dvelum\Orm\Orm::class),
            new Argument(\Dvelum\Orm\Distributed::class),
            new CallableArgument(static function (c $c) {
                return $c->get(\Dvelum\Lang::class)->getDictionary();
            })
        ]
    ],
    \Dvelum\Orm\Distributed\RouterInterface::class => [
        'class' => \Dvelum\Orm\Distributed\Router::class,
        'arguments' => [
            new Argument(\Dvelum\Orm\Orm::class),
            new CallableArgument(static function (c $c) {
                $storage = $c->get(\Dvelum\Config\Storage\StorageInterface::class);
                $routesFile = $storage->get('sharding.php')->get('routes');
                return $storage->get($routesFile);
            })
        ]
    ],
    \Dvelum\Orm\Distributed::class => [
        'class' => \Dvelum\Orm\Distributed::class,
        'arguments' => [
            new CallableArgument(static function (c $c) {
                return $c->get(\Dvelum\Config\Storage\StorageInterface::class)->get('sharding.php');
            }),
            new Argument(\Dvelum\Orm\Distributed\RouterInterface::class),
            new Argument(\Dvelum\Config\Storage\StorageInterface::class),
            new Argument(\Dvelum\Orm\Orm::class),
        ]
    ],
    \Dvelum\Orm\Record\Config\FieldFactory::class => [
        'class' => \Dvelum\Orm\Record\Config\FieldFactory::class,
        'arguments' => [
            new Argument(\Dvelum\Orm\Orm::class),
            new Argument(\Dvelum\App\Dictionary\Service::class)
        ]
    ],
];