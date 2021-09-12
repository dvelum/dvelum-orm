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

namespace Dvelum\Db;

use Dvelum\Config\ConfigInterface;

class OrmManager extends Manager
{
    /**
     * @var array<string,array{host:string,group:string,weight:int,override:array<string,mixed>}>
     */
    protected array $shardsConfig;

    /**
     * @param ConfigInterface<int|string,mixed> $appConfig
     * @param array<string,array{host:string,group:string,weight:int,override:array<string,mixed>}> $shardsConfig
     */
    public function __construct(ConfigInterface $appConfig, array $shardsConfig)
    {
        $this->shardsConfig = $shardsConfig;
        parent::__construct($appConfig);
    }

    /**
     * Get Database connection
     * @param string $name
     * @param null|string $workMode
     * @param null|string $shard
     * @return Adapter
     * @throws \Exception
     */
    public function getDbConnection(string $name, ?string $workMode = null, ?string $shard = null): Adapter
    {
        if (empty($workMode)) {
            $workMode = $this->appConfig->get('development');
        }

        if (empty($shard)) {
            $shardKey = '1';
        } else {
            $shardKey = $shard;
        }

        if (!isset($this->dbConnections[$workMode][$name][$shardKey])) {
            $cfg = $this->getDbConfig($name);

            $cfg['driver'] = $cfg['adapter'];
            /*
             * Enable Db profiler for development mode Attention! Db Profiler causes
             * memory leaks at background tasks. (Dev mode)
             */
            if (
                $this->appConfig->get('development') &&
                $this->appConfig->offsetExists('use_db_profiler') &&
                $this->appConfig->get('use_db_profiler')
            ) {
                $cfg['profiler'] = true;
            }

            if (!empty($shard)) {
                $shardInfo = $this->shardsConfig[$shard];
                $cfg['host'] = $shardInfo['host'];
                if (isset($shardInfo['override']) && !empty($shardInfo['override'])) {
                    foreach ($shardInfo['override'] as $k => $v) {
                        $cfg[$k] = $v;
                    }
                }
            }
            $db = $this->initConnection($cfg);
            $this->dbConnections[$workMode][$name][$shardKey] = $db;
        }
        return $this->dbConnections[$workMode][$name][$shardKey];
    }
}
