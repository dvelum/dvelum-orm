<?php
/**
 *  DVelum project https://github.com/dvelum/dvelum
 *  Copyright (C) 2011-2017  Kirill Yegorov
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace Dvelum\App\Orm\Api;

use Dvelum\Config;
use Dvelum\Lang;
use Dvelum\Config\ConfigInterface;

class Connections
{
    /**
     * @var array<string,mixed>
     */
    protected array $config;

    protected Config\Storage\StorageInterface $configStorage;

    protected Lang\Dictionary $lang;

    /**
     * @param array<string,mixed> $config
     * @param Config\Storage\StorageInterface $configStorage
     * @param Lang\Dictionary $lang
     */
    public function __construct(array $config, Config\Storage\StorageInterface $configStorage, Lang\Dictionary $lang)
    {
        $this->config = $config;
        $this->configStorage = $configStorage;
        $this->lang = $lang;
    }

    public function typeExists(int $devType): bool
    {
        return isset($this->config[$devType]);
    }

    /**
     * Get connections list
     * @param int $devType
     * @return array<string,ConfigInterface>
     * @throws \Exception
     */
    public function getConnections(int $devType)
    {
        if (!$this->typeExists($devType)) {
            throw new \Exception('Backend_Orm_Connections_Manager :: getConnections undefined dev type ' . $devType);
        }

        $dbPath = $this->configStorage->get('main.php')->get('db_config_path');

        $dir = dirname($this->config[$devType]['dir'] . '/' . $dbPath);

        if (!is_dir($dir)) {
            return [];
        }

        $files = \Dvelum\File::scanFiles($dir, array('.php'), false, \Dvelum\File::FILES_ONLY);
        $result = [];
        if (!empty($files)) {
            foreach ($files as $path) {
                $data = include $path;
                $result[substr(basename($path), 0, -4)] = Config\Factory::create($data, $path);
            }
        }
        return $result;
    }

    /**
     * Remove DB Connection config
     * Caution! Connection settings will be removed for all system modes.
     * @param string $id
     * @throws \Exception
     */
    public function removeConnection($id)
    {
        $errors = [];
        /*
         * Check for write permissions before operation
         */
        foreach ($this->config as $devType => $data) {
            $file = $data['dir'] . $id . '.php';
            if (!file_exists($file) && !is_writable($file)) {
                $errors[] = $file;
            }
        }

        if (!empty($errors)) {
            throw new \Exception($this->lang->get('CANT_WRITE_FS') . ' ' . implode(', ', $errors));
        }

        foreach ($this->config as $devType => $data) {
            $file = $data['dir'] . $id . '.php';
            if (!@unlink($file)) {
                throw new \Exception($this->lang->get('CANT_WRITE_FS') . ' ' . $file);
            }
        }
    }

    /**
     * Get connection config
     * @param int $devType
     * @param string $id
     * @return ConfigInterface|null
     */
    public function getConnection(int $devType, string $id): ?ConfigInterface
    {
        if (!$this->typeExists($devType)) {
            return null;
        }

        $path = $this->config[$devType]['dir'] . $id . '.php';

        $data = include $path;

        $cfg = Config\Factory::create($data, $path);

        if (empty($cfg)) {
            return null;
        }

        return $cfg;
    }

    public function createConnection($id)
    {
        foreach ($this->config as $devType => $data) {
            if ($this->connectionExists($devType, $id)) {
                return false;
            }
        }

        foreach ($this->config as $devType => $data) {
            $path = $this->config[$devType]['dir'] . $id . '.php';

            $c = Config\Factory::create(
                [
                    'username' => '',
                    'password' => '',
                    'dbname' => '',
                    'host' => '',
                    'charset' => 'UTF8',
                    'prefix' => '',
                    'adapter' => 'Mysqli',
                    'driver' => 'Mysqli',
                    'transactionIsolationLevel' => 'default'
                ],
                $path
            );

            if (!$this->configStorage->save($c)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Rename DB connection config
     * @param string $oldId
     * @param string $newId
     * @return boolean
     */
    public function renameConnection($oldId, $newId)
    {
        /**
         * Check permissions
         */
        foreach ($this->config as $devType => $data) {
            if (!is_writable($data['dir'])
                || $this->connectionExists($devType, $newId)
                || !file_exists($data['dir'] . $oldId . '.php')
                || !is_writable($data['dir'] . $oldId . '.php')
            ) {
                return false;
            }
        }
        foreach ($this->config as $devType => $data) {
            rename($this->config[$devType]['dir'] . $oldId . '.php', $this->config[$devType]['dir'] . $newId . '.php');
        }
        return true;
    }

    /**
     * Check if DB Connection exists
     * @param int $devType
     * @param string $id
     * @return bool
     */
    public function connectionExists(int $devType, string $id):bool
    {
        if (!$this->typeExists($devType)) {
            return false;
        }

        return file_exists($this->config[$devType]['dir'] . $id . '.php');
    }

    /**
     * Get connections config
     * @return array<string,mixed>
     */
    public function getConfig() : array
    {
        return $this->config;
    }
}
