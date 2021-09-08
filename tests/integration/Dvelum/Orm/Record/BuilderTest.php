<?php

use PHPUnit\Framework\TestCase;
use Dvelum\Orm\Record\BuilderFactory;
use Dvelum\Orm\Record\Builder\BuilderInterface;
use Dvelum\Orm\Orm;


class BuilderTest extends TestCase
{
    protected function getBuilder(string $object): BuilderInterface
    {
        return \Dvelum\Test\ServiceLocator::factory()->getContainer()->get(Orm::class)->getBuilder($object);
    }

    public function testCreateObject(): void
    {
        $o = $this->getBuilder('Page');
        $this->assertTrue($o instanceof BuilderInterface);
    }

    public function testTableExists(): void
    {
        $o = $this->getBuilder('Page');
        $this->assertTrue($o->tableExists());
    }


    public function testValidate(): void
    {
        $o = $this->getBuilder('Page');
        $o->build();
        $this->assertTrue($o->validate());
    }

    /**
     * @todo implement
     */
//	public function testRenameTable()
//	{
//		$cfg = Record\Config::factory('Page' , true);
//
//		$uniqName = uniqid();
//		$o = Builder::factory('Page',false);
//
//		$renamed = $o->renameTable($uniqName);
//
//		if(!$renamed)
//		    echo implode("\n", $o->getErrors());
//
//		$this->assertTrue($renamed);
//		$cfg->getConfig()->set('table',$uniqName);
//
//		Model::factory('Page');
//		$o = Builder::factory('Page',true);
//
//		$renamed = $o->renameTable('content');
//
//		if(!$renamed)
//		  echo implode("\n", $o->getErrors());
//
// 		$this->assertTrue($renamed);
//		$cfg->getConfig()->set('table','content');
//
//	}

    public function testCheckEngineCompatibility(): void
    {
        /**
         * @var Builder\MySQL $o
         */
        $o = $this->getBuilder('Page');
        $this->assertTrue($o->checkEngineCompatibility('myisam'));
        $this->assertTrue($o->checkEngineCompatibility('innodb'));
        $this->assertTrue(is_array($o->checkEngineCompatibility('memory')));

        $invalidEngine = false;
        try {
            $o->checkEngineCompatibility('ksdhuis');
        } catch (Exception $e) {
            $invalidEngine = true;
        }
        $this->assertTrue($invalidEngine);
    }
}