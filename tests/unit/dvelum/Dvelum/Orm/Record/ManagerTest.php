<?php

namespace Dvelum\Orm\Record;

use PHPUnit\Framework\TestCase;
use Dvelum\Orm\Orm;

class ManagerTest extends TestCase
{
    protected function getOrmManager(): Manager
    {
        $container = \Dvelum\Test\ServiceLocator::factory()->getContainer();
        $orm = $container->get(Orm::class);
        return new Manager($container->get(\Dvelum\Config\Storage\StorageInterface::class), $orm);
    }

    public function testGetRegisteredObjects(): void
    {
        $manager = $this->getOrmManager();
        $objects = $manager->getRegisteredObjects();
        $this->assertTrue(is_array($objects));
        $this->assertTrue(in_array('user', $objects, true));
    }

    public function testObjectExists(): void
    {
        $manager = $this->getOrmManager();
        $this->assertTrue($manager->objectExists('user'));
        $this->assertFalse($manager->objectExists('user_0123'));
    }
}
