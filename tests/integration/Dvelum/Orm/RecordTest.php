<?php

namespace Dvelum\Orm;

use PHPUnit\Framework\TestCase;

use Dvelum\Orm\Orm;

class RecordTest extends TestCase
{

    protected function getOrm(): Orm
    {
        return \Dvelum\Test\ServiceLocator::factory()->getContainer()->get(Orm::class);
    }

    protected $createdPages = [];


    protected function createPage(): RecordInterface
    {
        $page = $this->getOrm()->record('Page');
        $page->setValues(
            array(
                'code' => uniqid('', true) . date('YmdHis'),
                'is_fixed' => 1,
                'html_title' => 'Index',
                'menu_title' => 'Index',
                'page_title' => 'Index',
                'meta_keywords' => '',
                'meta_description' => '',
                'parent_id' => null,
                'text' => '[Index page content]',
                'func_code' => '',
                'order_no' => 1,
                'show_blocks' => true,
                'published' => true,
                'published_version' => 0,
                'editor_id' => 1,
                'date_created' => date('Y-m-d H:i:s'),
                'date_updated' => date('Y-m-d H:i:s'),
                'author_id' => 1,
                'blocks' => '',
                'theme' => 'default',
                'date_published' => date('Y-m-d H:i:s'),
                'in_site_map' => true,
                'default_blocks' => true
            )
        );
        $page->save();
        $this->createdPages[] = $page;
        return $page;
    }

    public function testSave(): void
    {
        $page = $this->createPage();
        $o = $this->getOrm()->record('page', $page->getId());
        $this->assertEquals($o->get('code'), $page->get('code'));
        $code = date('ymdHis') . 'testSave';
        $this->assertTrue($o->set('code', $code));
        $saved = $o->save();
        $this->assertTrue(!empty($saved));

        $page->delete();
        $o->delete();
    }

    public function testGetOld(): void
    {
        $o = $this->createPage();
        $oldCode = $o->get('code');
        $code = date('ymdHis');
        $o->set('code', $code);
        $this->assertEquals($o->get('code'), $code);
        $this->assertEquals($o->getOld('code'), $oldCode);

        $o->delete();
    }

    public function testCreate(): void
    {
        $o = $this->getOrm()->record('bgtask');
        $this->assertTrue($o->set('status', 1));
        $this->assertTrue($o->set('time_started', date('Y-m-d H:i:s')));
        $this->assertTrue($o->set('memory', 1024));
        $this->assertTrue($o->set('op_finished', 0));
        $this->assertTrue($o->set('op_total', 10));
        $this->assertTrue($o->set('title', 'Title'));
        $this->assertTrue((boolean)$o->save());

        $o->delete();
    }

    public function testFactory(): void
    {
        $page = $this->createPage();
        $o = $this->getOrm()->record('Page', $page->getId());
        $o2 = $this->getOrm()->record('page', $page->getId());
        $this->assertEquals($o, $o2);

        $o->delete();
        $o2->delete();
        $page->delete();
    }

    public function testHasUpdates(): void
    {
        $o = $this->createPage();
        $this->assertFalse($o->hasUpdates());
        $o->set('page_title', 'new title');
        $this->assertTrue($o->hasUpdates());

        $o->delete();
    }

    public function testToString(): void
    {
        $o = $this->createPage();
        $this->assertEquals($o->__toString(), (string)$o->getId());

        $o->delete();
    }

    public function testObjectExists(): void
    {
        $page = $this->createPage();

        $this->assertFalse($this->getOrm()->recordExists('ckwjhebjfcwe', false));
        $this->assertFalse($this->getOrm()->recordExists('Page', 999999));
        $this->assertTrue($this->getOrm()->recordsExists('Page', array($page->getId())));
        $this->assertTrue($this->getOrm()->recordExists('Page', $page->getId()));

        $page->delete();
    }

    public function testDeleteObject(): void
    {
        $page = $this->createPage();

        $id = $page->getId();
        $this->assertTrue($page->getId() > 0);
        $this->assertTrue($this->getOrm()->recordExists('Page', $id));
        $this->assertTrue($page->delete());
        $this->assertFalse($this->getOrm()->recordExists('Page', $id));

        $page->delete();
    }

    public function testGetLinkedObject()
    {
        $o = $this->createPage();

        $linked = $o->getLinkedObject('author_id');
        $this->assertEquals($linked, 'user');

        $o->delete();
    }

    public function test_hasRequired()
    {
        $somePage = $this->createPage();
        $page = $this->getOrm()->record('page');
        $code = date('ymdHiss');
        $page->set('code', $code);
        $page->set('author_id', 1);

        $this->assertFalse($page->save());
        $page->delete();

        $page = $this->createPage();

        $this->assertTrue($page->getId() > 0);
        $this->assertTrue($this->getOrm()->recordExists('Page', $page->getId()));
        $this->assertTrue($page->delete());
        $this->assertFalse($this->getOrm()->recordExists('Page', $page->getId()));

        $somePage->delete();
        $page->delete();
    }

    public function testExists(): void
    {
        $this->assertFalse($this->getOrm()->recordExists('page', 723489273));
        $this->assertFalse($this->getOrm()->recordExists('undefined', 723489273));
    }

    public function testSet(): void
    {
        $object_a = $this->createPage();
        $object_b = $this->createPage();
        $this->assertTrue($object_a->set('parent_id', $object_b->getId()));
        $this->assertEquals($object_a->get('parent_id'), $object_b->getId());

        $object_a->delete();
        $object_b->delete();
    }

    public function testIsInstanceOf(): void
    {
        $o = $this->getOrm()->record('Page');
        $this->assertTrue($o->isInstanceOf('Page'));
        $this->assertFalse($o->isInstanceOf('User'));
        $o->delete();
    }

    public function testGetInsertId(): void
    {
        $somePage = $this->createPage();
        $iId = time();
        $o = $this->getOrm()->record('Page');
        $o->setInsertId($iId);
        $this->assertEquals($iId, $o->getInsertId());

        $somePage->delete();
        $o->delete();
    }

    public function testSetInsertId(): void
    {
        $somePage = $this->createPage();

        $iId = time();
        $o = $this->getOrm()->record('Page');
        $o->setInsertId($iId);

        $this->assertEquals($iId, $o->getInsertId());
        $o->setValues(
            array(
                'code' => $iId,
                'is_fixed' => 1,
                'html_title' => 'Index',
                'menu_title' => 'Index',
                'page_title' => 'Index',
                'meta_keywords' => '',
                'meta_description' => '',
                'parent_id' => null,
                'text' => '[Index page content]',
                'func_code' => '',
                'order_no' => 1,
                'show_blocks' => true,
                'published' => true,
                'published_version' => 0,
                'editor_id' => 1,
                'date_created' => date('Y-m-d H:i:s'),
                'date_updated' => date('Y-m-d H:i:s'),
                'author_id' => 1,
                'blocks' => '',
                'theme' => 'default',
                'date_published' => date('Y-m-d H:i:s'),
                'in_site_map' => true,
                'default_blocks' => true
            )
        );
        $this->assertTrue((boolean)$o->save());
        $this->assertTrue($this->getOrm()->recordExists('Page', $iId));
        $this->assertEquals($iId, $o->getId());
        $somePage->delete();
        $o->delete();
    }

    public function testGetTitle(): void
    {
        $page = $this->getOrm()->record('Page');
        $cfg = $page->getConfig();

        $data = $cfg->getData();
        $data['link_title'] = '/ {code} / {menu_title} /';

        $cfg->setData($data);

        $page->set('code', 'pageCode');
        $page->set('menu_title', 'pageTitle');

        //echo $page->getTitle();exit;
        $this->assertEquals('/ pageCode / pageTitle /', $page->getTitle());
    }
}