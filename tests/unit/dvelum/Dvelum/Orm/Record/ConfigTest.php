<?php

use PHPUnit\Framework\TestCase;
use Dvelum\Orm\Record\BuilderFactory;
use Dvelum\Orm\Record;
use Dvelum\Orm\Model;
use Dvelum\Orm\Orm;

class ConfigTest extends TestCase
{
    protected function getOrm(): Orm
    {
        return \Dvelum\Test\ServiceLocator::factory()->getContainer()->get(Orm::class);
    }

    public function testGetObjectTtile(): void
    {
        $cfg = $this->getOrm()->config('User');
        $oldTitle = $cfg->getTitle();
        $cfg->setObjectTitle('My title');
        $this->assertEquals($cfg->getTitle(), 'My title');
        $cfg->setObjectTitle($oldTitle);
    }

    public function testCanUseForeignKeys(): void
    {
        $keyManager = new Record\Config\ForeignKey();
        $cfg = $this->getOrm()->config('User');
        $this->assertTrue($keyManager->canUseForeignKeys($cfg));

        $cfg = $this->getOrm()->config('Historylog');
        $this->assertFalse($keyManager->canUseForeignKeys($cfg));
    }

    public function testGetFields(): void
    {
        $cfg = $this->getOrm()->config('User');
        $fields = $cfg->getFields();
        $this->assertArrayHasKey('id', $fields);
        $this->assertTrue($fields['id'] instanceof Record\Config\Field);
    }

    public function testGetLinks(): void
    {
        $cfg = $this->getOrm()->config('User_Auth');
        $links = $cfg->getLinks();
        $this->assertTrue(isset($links['user']['user']));
    }

    public function testHasDbPrefix(): void
    {
        $cfg = $this->getOrm()->config('User');
        $this->assertTrue($cfg->hasDbPrefix());
    }

    public function testGetValidator(): void
    {
        $cfg = $this->getOrm()->config('User');
        $this->assertFalse($cfg->getValidator('id'));
    }

    public function testToArray(): void
    {
        $cfg = $this->getOrm()->config('User');
        $array = $cfg->__toArray();
        $this->assertTrue(is_array($array));
        $this->assertTrue(isset($array['fields']));
        $data = $cfg->getData();
        $this->assertEquals($array, $data);
        $data['title'] = 'title 1';
        $cfg->setData($data);
        $this->assertEquals($cfg->getTitle(), 'title 1');
    }


    public function testIsReadOnly(): void
    {
        $cfg = $this->getOrm()->config('User');
        $this->assertFalse($cfg->isReadOnly());
    }

    public function testIsLocked(): void
    {
        $cfg = $this->getOrm()->config('User');
        $this->assertFalse($cfg->isLocked());
    }

    public function testIsTransact(): void
    {
        $cfg = $this->getOrm()->config('User');
        $this->assertTrue($cfg->isTransact());
        $cfg = $this->getOrm()->config('bgtask_signal');
        $this->assertFalse($cfg->isTransact());
    }

    public function testSave(): void
    {
        $cfg = $this->getOrm()->config('User');
        $oldTitle = $cfg->getTitle();
        $cfg->setObjectTitle('My title');
        $this->assertTrue($cfg->save());
        $cfg->setObjectTitle($oldTitle);
        $this->assertTrue($cfg->save());
    }

    public function testRemoveField(): void
    {
        $cfg = $this->getOrm()->config('User');
        $fldCfg = $cfg->getFieldConfig('name');

        $fieldManager = new Record\Config\FieldManager();
        $fieldManager->removeField($cfg, 'name');

        $this->assertFalse($cfg->fieldExists('v'));
        $fieldManager->setFieldConfig($cfg, 'name', $fldCfg);
        $this->assertTrue($cfg->fieldExists('name'));
    }

    public function testIsText(): void
    {
        $cfg = $this->getOrm()->config('User_Auth');
        $this->assertTrue($cfg->getField('config')->isText());
        $this->assertFalse($cfg->getField('id')->isText());
    }

    public function testIndexExists(): void
    {
        $cfg = $this->getOrm()->config('User');
        $indexManager = new Record\Config\IndexManager;

        $this->assertTrue($indexManager->indexExists($cfg, 'PRIMARY'));
        $this->assertFalse($indexManager->indexExists($cfg, 'undefinedindex'));
    }

    public function testIsUnique(): void
    {
        $cfg = $this->getOrm()->config('User');
        $this->assertTrue($cfg->getField('id')->isUnique());
        $this->assertTrue($cfg->getField('login')->isUnique());
        $this->assertFalse($cfg->getField('name')->isUnique());
    }

    public function testIsHtml(): void
    {
        $cfg = $this->getOrm()->config('User_Auth');
        $this->assertTrue($cfg->getField('config')->isHtml());
        $this->assertFalse($cfg->getField('id')->isHtml());
    }

    public function testIsNumeric(): void
    {
        $cfg = $this->getOrm()->config('User');
        $this->assertTrue($cfg->getField('id')->isNumeric());
        $this->assertFalse($cfg->getField('name')->isNumeric());
    }

    public function testIsInteger(): void
    {
        $cfg = $this->getOrm()->config('User');
        $this->assertTrue($cfg->getField('id')->isInteger());
        $this->assertFalse($cfg->getField('name')->isInteger());
    }

    public function testIsSearch(): void
    {
        $cfg = $this->getOrm()->config('User');
        $this->assertTrue($cfg->getField('id')->isSearch());
        $this->assertTrue($cfg->getField('name')->isSearch());
    }

    public function testGetLinkTittle(): void
    {
        $cfg = $this->getOrm()->config('User');
        $this->assertEquals($cfg->getLinkTitle(), 'name');
    }

    public function testIsFloat(): void
    {
        $cfg = $this->getOrm()->config('test');
        $this->assertFalse($cfg->getField('integer')->isFloat());
        $this->assertTrue($cfg->getField('float')->isFloat());
    }

    public function testIsSystem(): void
    {
        $cfg = $this->getOrm()->config('test');
        $this->assertFalse($cfg->isSystem());

        $cfg = $this->getOrm()->config('Page');
        $this->assertTrue($cfg->isSystem());

        $this->assertTrue($cfg->getField('id')->isSystem());
        $this->assertFalse($cfg->getField('code')->isSystem());
    }

    public function testgetLinkTitle(): void
    {
        $cfg = $this->getOrm()->config('test');
        $this->assertEquals($cfg->getLinkTitle(), $cfg->getPrimaryKey());
    }

    public function testgetDbType(): void
    {
        $cfg = $this->getOrm()->config('test');
        $this->assertEquals('bigint', $cfg->getField($cfg->getPrimaryKey())->getDbType());
        $this->assertEquals('float', $cfg->getField('float')->getDbType());
    }

    public function testHasHistory(): void
    {
        $cfg = $this->getOrm()->config('User');
        $this->assertTrue($cfg->hasHistory());
        $this->assertFalse($cfg->hasExtendedHistory());
        $cfg = $this->getOrm()->config('Historylog');
        $this->assertFalse($cfg->hasHistory());
    }

    public function testIsObjectLink(): void
    {
        $cfg = $this->getOrm()->config('test');
        $this->assertTrue($cfg->getField('link')->isObjectLink());
        $this->assertFalse($cfg->getField('multilink')->isObjectLink());
        $this->assertFalse($cfg->getField('integer')->isObjectLink());
        $this->assertFalse($cfg->getField('dictionary')->isObjectLink());
    }

    public function testIsMultiLink(): void
    {
        $cfg = $this->getOrm()->config('test');
        $this->assertTrue($cfg->getField('multilink')->isMultiLink());
        $this->assertFalse($cfg->getField('link')->isMultiLink());
        $this->assertFalse($cfg->getField('dictionary')->isMultiLink());
        $this->assertFalse($cfg->getField('integer')->isMultiLink());
    }

    public function testGetLinkedObject(): void
    {
        $cfg = $this->getOrm()->config('test');
        $this->assertEquals('user', $cfg->getField('link')->getLinkedObject());
        $this->assertEquals('page', $cfg->getField('multilink')->getLinkedObject());
    }

    public function testGetLinkedDictionary(): void
    {
        $cfg = $this->getOrm()->config('test');
        $this->assertEquals('link_type', $cfg->getField('dictionary')->getLinkedDictionary());
    }

    public function testGetSearchFields(): void
    {
        $cfg = $this->getOrm()->config('test');
        $searchFields = $cfg->getSearchFields();
        $this->assertEquals(2, sizeof($searchFields));
        $this->assertTrue(in_array('id', $searchFields, true));
        $this->assertTrue(in_array('varchar', $searchFields, true));
    }

    public function testIsRevControl(): void
    {
        $cfg = $this->getOrm()->config('test');
        $this->assertFalse($cfg->isRevControl());

        $cfg = $this->getOrm()->config('User');
        $this->assertFalse($cfg->isRevControl());
    }

    public function testIsSystemField(): void
    {
        $cfg = $this->getOrm()->config('test');
        $this->assertFalse($cfg->getField('varchar')->isSystem());
        $this->assertTrue($cfg->getField('id')->isSystem());

        $cfg = $this->getOrm()->config('User');
        $this->assertTrue($cfg->getField('id')->isSystem());
    }

    public function testGetForeignKeys(): void
    {
        $cfg = $this->getOrm()->config('User_auth');
        $keyManager = new Record\Config\ForeignKey();
        $keys = $keyManager->getForeignKeys($cfg, $this->getOrm());
        $keys = \Dvelum\Utils::rekey('curField', $keys);
        $this->assertTrue(isset($keys['user']));
        $this->assertFalse(isset($keys['config']));
    }


    public function testIsVcField(): void
    {
        $cfg = $this->getOrm()->config('test');
        $this->assertTrue($cfg->isVcField('author_id'));
        $this->assertFalse($cfg->isVcField('id'));
    }

    public function testHasManyToMany(): void
    {
        $cfg = $this->getOrm()->config('test');
        $relation = new Record\Config\Relation();
        $this->assertFalse($relation->hasManyToMany($cfg));
    }
}