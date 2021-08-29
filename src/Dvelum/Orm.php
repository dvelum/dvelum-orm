<?php

namespace Dvelum;

use Psr\Container\ContainerInterface;

/**
 * Class Orm factory
 * Backward compatibility
 * @package Dvelum
 * @deprecated
 */
class Orm
{
    static private ContainerInterface $di;

    public static function setContainer(ContainerInterface $di): void
    {
        self::$di = $di;
    }

    public static function factory(): \Dvelum\Orm\Orm
    {
        return self::$di->get(\Dvelum\Orm\Orm::class);
    }
}