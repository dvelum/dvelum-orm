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

declare(strict_types=1);

namespace Dvelum\Orm\Record\Config\Field;

use Dvelum\Orm\Orm;
use Dvelum\Orm\Record;
use Dvelum\Orm\RecordInterface;

class ObjectItem extends \Dvelum\Orm\Record\Config\Field
{
    protected Orm $orm;

    /**
     * @param Orm $orm
     * @param array<string,mixed> $config
     */
    public function __construct(Orm $orm, array $config)
    {
        $this->orm = $orm;
        parent::__construct($config);
    }

    /**
     * Apply value filter
     * @param mixed $value
     * @return mixed
     * @throws \Exception
     */
    public function filter($value)
    {
        if (is_object($value)) {
            if ($value instanceof RecordInterface) {
                if (!$value->isInstanceOf((string)$this->getLinkedObject())) {
                    throw new \Exception(
                        'Invalid value type for field ' . $this->getName() . ' expects ' . $this->getLinkedObject(
                        ) . ', ' . $value->getName() . ' passed'
                    );
                }
                $value = $value->getId();
            } else {
                if (method_exists($value, '__toString')) {
                    $value = $value->__toString();
                } else {
                    $value = null;
                }
            }
        }

        if (empty($value)) {
            return null;
        }

        return (int)$value;
    }

    /**
     * Validate value
     * @param mixed $value
     * @return bool
     */
    public function validate($value): bool
    {
        if (!parent::validate($value)) {
            return false;
        }

        if (!empty($value)) {
            if (!is_int($value)) {
                return false;
            }
            return $this->orm->recordExists($this->config['link_config']['object'], $value);
        }

        return true;
    }
}
