<?php

namespace Dvelum\Orm;

use PHPUnit\Framework\TestCase;
use Dvelum\Orm\Model;
use Dvelum\Orm\Record;
use Dvelum\Orm\Orm;

class ModelTest extends TestCase
{
    protected function getOrm(): Orm
    {
        return \Dvelum\Test\ServiceLocator::factory()->getContainer()->get(Orm::class);
    }

    protected function createPage(): \Dvelum\Orm\RecordInterface
    {
        $group = $this->getOrm()->record('Group');
        $group->setValues(
            array(
                'title' => date('YmdHis'),
                'system' => false
            )
        );
        $group->save();

        $user = $this->getOrm()->record('User');
        try {
            $user->setValues(
                array(
                    'login' => uniqid('', true) . date('YmdHis'),
                    'pass' => '111',
                    'email' => uniqid('', true) . date('YmdHis') . '@mail.com',
                    'enabled' => 1,
                    'admin' => 1,
                    'name' => 'Test User',
                    'group_id' => $group->getId()
                )
            );
        } catch (Exception $e) {
            echo $e->getMessage();
        }
        $saved = $user->save();
        $this->assertTrue(!empty($saved));


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
        return $page;
    }

    public function testFactory(): void
    {
        $apiKeys = $this->getOrm()->model('User');
        $this->assertEquals(($apiKeys instanceof Model), true);
        $this->assertEquals($apiKeys->getObjectName(), 'user');
    }

    public function testTable(): void
    {
        $userModel = $this->getOrm()->model('User');
        $dbCfg = $userModel->getDbConnection()->getConfig();
        $userModel = $this->getOrm()->model('User');
        $this->assertEquals($dbCfg['prefix'] . 'user', $userModel->table());
    }

    public function testGetItem(): void
    {
        $pageModel = $this->getOrm()->model('Page');
        $page = $this->createPage();
        $page2 = $this->createPage();
        $item = $pageModel->getItem($page->getId(), array('id', 'code'));
        $this->assertEquals($page->get('code'), $item['code']);

        $item2 = $pageModel->getCachedItem($page->getId());
        $this->assertEquals($item['code'], $item2['code']);

        $this->assertFalse($pageModel->checkUnique($page->getId(), 'code', $page2->get('code')));
        $this->assertTrue($pageModel->checkUnique($page->getId(), 'code', $page2->get('code') . '1'));
    }

    public function testGetCount(): void
    {
        $page = $this->createPage();
        $pageModel = $this->getOrm()->model('Page');
        $this->assertEquals(1, $pageModel->query()->filters(array('code' => $page->get('code')))->getCount());
    }

    /**
     * @todo check params , filters
     */
    public function testGetList(): void
    {
        $pageModel = $this->getOrm()->model('Page');
        $items = $pageModel->query()->filters(array('code' => 'index'))->fields(array('id', 'code'))->fetchAll();
        $this->assertEquals('index', $items[0]['code'] = 'index');
    }

    /**
     * @todo add assertations
     */
    public function testGetListVc(): void
    {
        $pageModel = $this->getOrm()->model('Page');
        $page = $this->createPage();
        $items = $pageModel->query()
            ->filters(array('code' => $page->get('code')))
            ->fields(array('id', 'code'))
            ->fetchAll();
        $this->assertEquals($page->get('code'), $items[0]['code']);
    }

    public function testGetObjectConfig(): void
    {
        $model = $this->getOrm()->model('Page');
        $config = $model->getObjectConfig();
        $this->assertTrue($config instanceof Record\Config);
        $this->assertEquals('page', $config->getName());
    }

    public function testRemove(): void
    {
        $model = $this->getOrm()->model('Page');
        $page = $this->createPage();
        $this->assertTrue($model->remove($page->getId()));
        $this->assertFalse($model->remove($page->getId()));
        $this->assertEquals(0, $model->query()->filters(['id' => $page->getId()])->getCount());
    }

    public function testInsert(): void
    {
        $model = $this->getOrm()->model('Page');
        $insert = $model->insert();
        $this->assertTrue($insert instanceof Model\Insert);
    }
}
