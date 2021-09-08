<?php

use PHPUnit\Framework\TestCase;

use Dvelum\Orm;

class FieldTest extends TestCase
{
    protected function getOrm(): Orm\Orm
    {
        return \Dvelum\Test\ServiceLocator::factory()->getContainer()->get(Orm\Orm::class);
    }

    public function testProperties()
    {
        $config = $this->getOrm()->config('user');
        $field = $config->getField('id');
        $this->assertTrue($field->isSystem());
        $this->assertTrue($field->isSearch());
        $this->assertTrue($field->isNumeric());
        $this->assertTrue($field->isInteger());
        $this->assertTrue($field->isUnsigned());
        $this->assertTrue($field->isUnique());
        $this->assertTrue($config->getField('name')->isSearch());
        $this->assertEquals('id', $field->getName());
        $this->assertEquals('bigint', $field->getDbType());

        $config = $this->getOrm()->config('user_auth');

        $authorField = $config->getField('user');
        $this->assertEquals('user', $authorField->getLinkedObject());
        $this->assertEquals('link', $authorField->getType());
        $this->assertTrue($authorField->isLink());
        $this->assertFalse($authorField->isDictionaryLink());
        $this->assertTrue($authorField->isObjectLink());
        $this->assertTrue($authorField->isRequired());

        $this->assertFalse($authorField->isBoolean());
        $this->assertFalse($authorField->isHtml());
        $this->assertFalse($authorField->isDateField());
        $this->assertFalse($authorField->isEncrypted());
        $this->assertFalse($authorField->isFloat());
        $this->assertFalse($authorField->isManyToManyLink());
        $this->assertFalse($authorField->isMultiLink());

        $textField = $config->getField('config');
        $this->assertTrue($textField->isText());
        $this->assertTrue($textField->isHtml());
        $this->assertFalse($textField->isVirtual());
        $this->assertFalse($textField->isSystem());
    }
}