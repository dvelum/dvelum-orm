<?php

/*
 *
 * DVelum project https://github.com/dvelum/
 *
 * MIT License
 *
 *  Copyright (C) 2011-2021  Kirill Yegorov https://github.com/dvelum/dvelum-orm
 *
 *  Permission is hereby granted, free of charge, to any person obtaining a copy
 *  of this software and associated documentation files (the "Software"), to deal
 *  in the Software without restriction, including without limitation the rights
 *  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 *  copies of the Software, and to permit persons to whom the Software is
 *  furnished to do so, subject to the following conditions:
 *
 *  The above copyright notice and this permission notice shall be included in all
 *  copies or substantial portions of the Software.
 *
 *  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 *  IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 *  FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 *  AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 *  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 *  OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 *  SOFTWARE.
 *
 */

namespace Dvelum\Orm\Record\Event;

use Dvelum\Orm;

/**
 * Abstract class for event managing
 * @author Kirill A Egorov kirill.a.egorov@gmail.com
 * @copyright Copyright (C) 2012  Kirill A Egorov,
 * DVelum project https://github.com/dvelum/dvelum , http://dvelum.net
 * @license General Public License version 3
 */
abstract class Manager
{
    public const BEFORE_ADD = 'onBeforeAdd';
    public const BEFORE_UPDATE = 'onBeforeUpdate';
    public const BEFORE_DELETE = 'onBeforeDelete';
    public const BEFORE_UNPUBLISH = 'onBeforeUnpublish';
    public const BEFORE_PUBLISH = 'onBeforePublish';
    public const BEFORE_ADD_VERSION = 'onBeforeAddVersion';
    public const AFTER_ADD = 'onAfterAdd';
    public const AFTER_ADD_VERSION = 'onAfterAddVersion';
    public const AFTER_UPDATE = 'onAfterUpdate';
    public const AFTER_DELETE = 'onAfterDelete';
    public const AFTER_UNPUBLISH = 'onAfterUnpublish';
    public const AFTER_PUBLISH = 'onAfterPublish';
    public const AFTER_UPDATE_BEFORE_COMMIT = 'onAfterUpdateBeforeCommit';
    public const AFTER_INSERT_BEFORE_COMMIT = 'onAfterInsertBeforeCommit';
    public const AFTER_DELETE_BEFORE_COMMIT = 'onAfterDeleteBeforeCommit';

    /**
     * Find and run event triggers
     * Note that onBeforeDelete and onAfterDelete events provide "SpacialCase" empty Db_Object
     * id property exists
     * @param string $code (action constant)
     * @param Orm\RecordInterface $object
     */
    abstract public function fireEvent(string $code, Orm\RecordInterface $object): void;
}
