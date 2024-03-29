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

class Varchar extends \Dvelum\Orm\Record\Config\Field
{
    /**
     * Apply value filter
     * @param mixed $value
     * @return mixed
     */
    public function filter($value)
    {
        if (is_null($value) && $this->isNull()) {
            return null;
        }

        if (!isset($this->config['allow_html']) || !$this->config['allow_html']) {
            $value = \Dvelum\Filter::filterValue('string', $value);
        }

        return $value;
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

        if (mb_strlen((string)$value, 'UTF-8') > $this->config['db_len']) {
            $this->validationError = 'The field value exceeds the allowable length.';
            return false;
        }

        return true;
    }
}
