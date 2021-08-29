<?php

use Psr\Container\ContainerInterface as c;

return [
    \Dvelum\Orm\Orm::class => static function (c $c): \Dvelum\Orm\Orm {
        $cache = $c->has(\Dvelum\Cache\CacheInterface::class) ? $c->get(
            \Dvelum\Cache\CacheInterface::class
        ) : null;

        $storage = $c->get(\Dvelum\Config\Storage\StorageInterface::class);
        return new \Dvelum\Orm\Orm(
            $storage->get('orm.php'),
            $c->get(\Dvelum\Db\ManagerInterface::class),
            $c->get('config.main')->get('language'),
            $cache,
            $c->get(Dvelum\Lang::class),
            $storage
        );
    },
    \Dvelum\Orm\Stat::class => static function (c $c): \Dvelum\Orm\Stat {
       return new \Dvelum\Orm\Stat(
           $c->get(\Dvelum\Config\Storage\StorageInterface::class),
           $c->get(\Dvelum\Orm\Orm::class),
           $c->get(\Dvelum\Lang::class)->getDictionary(),
        );
    }
];