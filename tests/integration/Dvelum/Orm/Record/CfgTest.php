<?php

use PHPUnit\Framework\TestCase;
use Dvelum\Orm\Record\BuilderFactory;
use Dvelum\Orm\Record;
use Dvelum\Orm\Orm;

class CfgTest extends TestCase
{
    protected function getOrm(): Orm
    {
        return \Dvelum\Test\ServiceLocator::factory()->getContainer()->get(Orm::class);
    }
    public function testRenameField() : void
    {
        $orm = $this->getOrm();

        $o = $orm->getBuilder('page_rename');
        $cfg = $orm->config('page_rename');

        $fieldManager = new Record\Config\FieldManager();
        $fieldManager->renameField($cfg,'page_title', 'untitle');

        $this->assertTrue($cfg->fieldExists('untitle'));
        $this->assertFalse($cfg->fieldExists('page_title'));
        $o->build();
        $this->assertTrue($o->validate());

        $fieldManager->renameField($cfg, 'untitle', 'page_title');
        $o->build();
        $this->assertTrue($o->validate());
        $this->assertFalse($cfg->fieldExists('untitle'));
        $this->assertTrue($cfg->fieldExists('page_title'));
    }
}