<?php

use Psr\Container\ContainerInterface as c;

return [
    \Dvelum\Orm\Orm::class => static function (c $c): \Dvelum\Orm\Orm {
        $cache = $c->has(\Dvelum\Cache\CacheInterface::class) ? $c->get(
            \Dvelum\Cache\CacheInterface::class
        ) : null;

        $orm = new \Dvelum\Orm\Orm(
            $c->get(\Dvelum\Config\Storage\StorageInterface::class)->get('orm.php'),
            $c->get(\Dvelum\Db\ManagerInterface::class),
            $c->get('config.main')->get('language'),
            $cache,
            $c->get(Dvelum\Lang::class),
        );
        return $orm;
    }
];