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

namespace Dvelum\App\Orm\Api\Controller;

use Dvelum\App\Orm\Api\Controller;
use Dvelum\Filter;
use Dvelum\Config;
use Dvelum\File;

class Log extends Controller
{
    public function indexAction(): void
    {
    }

    /**
     * Get DB_Object_Builder log contents
     * for current development version
     */
    public function getlogAction(): void
    {
        $file = $this->request->post('file', Filter::FILTER_STRING, false);

        $ormConfig = Config::storage()->get('orm.php');
        $logPath = $ormConfig->get('log_path');
        $fileName = $logPath . $file . '.sql';

        if (file_exists($fileName)) {
            $data = nl2br((string)file_get_contents($fileName));
        } else {
            $data = '';
        }
        $this->response->json(['success' => true, 'data' => $data]);
    }

    public function getLogFilesAction(): void
    {
        $ormConfig = Config::storage()->get('orm.php');
        $logPath = $ormConfig->get('log_path');

        if (!is_dir($logPath)) {
            $this->response->success([]);
            return;
        }

        $files = File::scanFiles($logPath, ['.sql'], false);
        $data = [];

        foreach ($files as $file) {
            $file = basename($file, '.sql');
            $data[] = ['id' => $file];
        }

        $this->response->success($data);
    }
}
