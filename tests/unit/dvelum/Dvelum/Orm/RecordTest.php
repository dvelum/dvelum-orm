<?php

namespace Dvelum\Orm;

use Dvelum\Orm\Record\Config\Field;
use PHPUnit\Framework\TestCase;

class RecordTest extends TestCase
{
    protected function getOrm(): Orm
    {
        return \Dvelum\Test\ServiceLocator::factory()->getContainer()->get(Orm::class);
    }

    /**
     * @return RecordInterface
     * @throws Exception
     */
    protected function createObject(): RecordInterface
    {
        return $this->getOrm()->record('User_Auth');
    }

    public function testSetId(): void
    {
        $object = $this->createObject();
        $object->setId(10);
        $this->assertEquals(10, $object->getId());
    }

    public function testSetInsertId(): void
    {
        $object = $this->createObject();
        $object->setInsertId(123);
        $this->assertEquals(123, $object->getInsertId());
    }

    public function testSetVersion(): void
    {
        $object = $this->createObject();
        $object->setVersion(2);
        $this->assertEquals(2, $object->getVersion());
    }

    public function testGetFields(): void
    {
        $object = $this->createObject();
        $fields = $object->getFields();
        $this->assertTrue(!empty($fields));
        foreach ($fields as $field) {
            $field = $object->getConfig()->getField($field);
            $this->assertTrue($field instanceof Field);
        }
    }

    public function testHasUpdates(): void
    {
        $object = $this->createObject();
        $this->assertFalse($object->hasUpdates());
        $object->set('config', '123');
        $this->assertTrue($object->hasUpdates());
    }

    public function testGetUpdates(): void
    {
        $object = $this->createObject();
        $object->set('config', '123');
        $this->assertEquals(['config' => '123'], $object->getUpdates());
    }

    public function testCommitChanges(): void
    {
        $object = $this->createObject();
        $object->commitChanges();
        $this->assertFalse($object->hasUpdates());
        $object->set('config', '123');
        $object->commitChanges();
        $this->assertFalse($object->hasUpdates());
    }

    public function testFieldExist(): void
    {
        $object = $this->createObject();
        $this->assertFalse($object->fieldExists('name_name'));
        $this->assertTrue($object->fieldExists('id'));
    }

    public function testGetLinkedObject(): void
    {
        $object = $this->createObject();
        $this->assertEquals('user', $object->getLinkedObject('user'));
    }

    public function testSetValues(): void
    {
        $object = $this->createObject();
        $values = [
            'config' => 'my_code',
        ];
        $object->setValues($values);
        $this->assertEquals($values, $object->getUpdates());
        $this->assertEquals('my_code', $object->get('config'));
    }

    public function testGetOld(): void
    {
        $object = $this->createObject();
        $object->set('config', '1');
        $object->commitChanges();
        $object->set('config', '2');
        $this->assertEquals('1', $object->getOld('config'));
    }

    public function testAddErrorMessage(): void
    {
        $object = $this->createObject();
        $object->addErrorMessage('msg');
        $this->assertEquals('msg', $object->getErrors()[0]);
    }

    public function testToString(): void
    {
        $object = $this->createObject();
        $object->setId(1);
        $this->assertEquals('1', $object->__toString());
    }

    public function testRejectChanges(): void
    {
        $object = $this->createObject();
        $values = [
            'config' => 'my_code'
        ];
        $object->setValues($values);
        $object->rejectChanges();
        $this->assertTrue(empty($object->getUpdates()));
    }

    public function testInstanceOf(): void
    {
        $object = $this->createObject();
        $this->assertTrue($object->isInstanceOf('User_Auth'));
    }

    public function testSet(): void
    {
        $object = $this->createObject();
        $object->set('config', 'pageCode');
        $this->assertEquals('pageCode', $object->get('config'));
        $object->setId(23);
        $this->assertEquals(23, $object->getId());
    }

    public function testGetDataModel(): void
    {
        $object = $this->createObject();
        $this->assertTrue($object->getDataModel() instanceof Record\DataModel);
    }
}
