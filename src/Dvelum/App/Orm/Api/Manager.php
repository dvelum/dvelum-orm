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

namespace Dvelum\App\Orm\Api;

use Dvelum\File;
use Dvelum\Orm;
use Dvelum\Lang;
use Dvelum\Config;
use Dvelum\Orm\Record\BuilderFactory;
use Dvelum\Orm\Record\Config\Translator;
use Exception;
use Dvelum\Config\Storage\StorageInterface;

class Manager
{
    public const ERROR_EXEC = 1;
    public const ERROR_FS = 2;
    public const ERROR_DB = 3;
    public const ERROR_FS_LOCALISATION = 4;
    public const ERROR_INVALID_OBJECT = 5;
    public const ERROR_INVALID_FIELD = 6;
    public const ERROR_HAS_LINKS = 7;

    private Orm\Orm $orm;
    private Lang $lanService;
    private StorageInterface $configStorage;

    public function __construct(Orm\Orm $orm, Lang $langService, StorageInterface $configStorage)
    {
        $this->orm = $orm;
        $this->lanService = $langService;
        $this->configStorage = $configStorage;
    }

    /**
     * Remove object from ORM
     * @param string $name
     * @param bool $deleteTable - optional, default true
     * @return int
     */
    public function removeObject($name, $deleteTable = true)
    {
        /*
        $assoc = Db_Object_Expert::getAssociatedStructures($name);
        if (!empty($assoc)) {
            return self::ERROR_HAS_LINKS;
        }
        */

        $objectConfig = $this->orm->config($name);

        $relation = new Orm\Record\Config\Relation();
        $manyToMany = $relation->getManyToMany($objectConfig);

        if (!empty($manyToMany)) {
            $linkedFields = [];
            foreach ($manyToMany as $object => $fields) {
                foreach ($fields as $fieldName => $cfg) {
                    $linkedFields[] = $fieldName;
                }
            }

            if (!empty($linkedFields)) {
                foreach ($linkedFields as $field) {
                    /**
                     * @var string $relatedObject
                     */
                    $relatedObject = $objectConfig->getRelationsObject($field);
                    if (empty($relatedObject)) {
                        return self::ERROR_EXEC;
                    }
                    $result = $this->removeObject($relatedObject, $deleteTable);

                    if ($result !== 0) {
                        return $result;
                    }
                }
            }
        }

        $localisations = $this->getLocalisations();
        $langWritePath = $this->lanService->getStorage()->getWrite();
        $objectsWrite = $this->configStorage->getWrite();

        foreach ($localisations as $file) {
            if (file_exists($langWritePath . $file) && !is_writable($langWritePath . $file)) {
                return self::ERROR_FS_LOCALISATION;
            }

            $localeName = basename(dirname($file));
            $translator = $this->getTranslator($localeName, $name);
            if (!$translator->removeObjectTranslation($name, true)) {
                return self::ERROR_FS_LOCALISATION;
            }
        }

        $path = $objectsWrite . $this->configStorage->get('orm.php')->get('object_configs') . $name . '.php';

        try {
            $cfg = $this->orm->config($name);
        } catch (\Exception $e) {
            return self::ERROR_FS;
        }

        $builder = $this->orm->getBuilder($name);

        if ($deleteTable && !$cfg->isLocked() && !$cfg->isReadOnly()) {
            if (!$builder->remove()) {
                return self::ERROR_DB;
            }
        }

        if (!@unlink($path)) {
            return self::ERROR_FS;
        }

        $localisationKey = strtolower($name);
        $langStorage = $this->lanService->getStorage();

        foreach ($localisations as $file) {
            $cfg = $langStorage->get($file);
            if ($cfg->offsetExists($localisationKey)) {
                $cfg->remove($localisationKey);
                $langStorage->save($cfg);
            }

            $localeName = basename(dirname($file));
            $translator = $this->getTranslator($localeName, $name);
            $translator->removeObjectTranslation($name, true);
        }
        return 0;
    }

    /**
     * Get list of localization files
     * @return array<string>
     */
    public function getLocalisations(): array
    {
        $paths = $this->lanService->getStorage()->getPaths();
        $dirs = [];

        foreach ($paths as $path) {
            if (!is_dir($path)) {
                continue;
            }
            $data = File::scanFiles($path, [], false, File::DIRS_ONLY);
            foreach ($data as $k => &$v) {
                if (!file_exists($v . '/objects.php')) {
                    unset($data[$k]);
                    continue;
                }
                $v = str_replace($path, '', $v) . '/objects.php';
            }
            $dirs = array_merge($dirs, $data);
        }
        return array_unique($dirs);
    }

    /**
     * Get field config
     * @param string $object
     * @param string $field
     * @return null|array<string,mixed>
     */
    public function getFieldConfig(string $object, string $field): ?array
    {
        try {
            $cfg = $this->orm->config($object);
        } catch (\Exception $e) {
            return null;
        }

        if (!$cfg->fieldExists($field)) {
            return null;
        }

        $fieldCfg = $cfg->getFieldConfig($field);
        $fieldCfg['name'] = $field;

        if (isset($fieldCfg['db_default']) && $fieldCfg['db_default'] !== false) {
            $fieldCfg['set_default'] = true;
        } else {
            $fieldCfg['set_default'] = false;
        }

        if (!isset($fieldCfg['type']) || empty($fieldCfg['type'])) {
            $fieldCfg['type'] = '';
        }

        if (isset($fieldCfg['link_config']) && !empty($fieldCfg['link_config'])) {
            foreach ($fieldCfg['link_config'] as $k => $v) {
                $fieldCfg[$k] = $v;
            }
        }
        /**
         * @var array<string,mixed> $fieldCfg
         */
        return $fieldCfg;
    }

    /**
     * Get index config
     * @param string $object
     * @param string $index
     * @return array<string,mixed>|null
     */
    public function getIndexConfig(string $object, string $index): ?array
    {
        try {
            $cfg = $this->orm->config($object);
        } catch (\Exception $e) {
            return null;
        }

        $indexManager = new Orm\Record\Config\IndexManager();
        if (!$indexManager->indexExists($cfg, $index)) {
            return null;
        }

        $data = $cfg->getIndexConfig($index);
        $data['name'] = $index;
        /**
         * @var array<string,mixed> $data
         */
        return $data;
    }

    /**
     * Remove object field
     * @param string $objectName
     * @param string $fieldName
     * @return int  - 0 - success or error code
     */
    public function removeField(string $objectName, string $fieldName): int
    {
        try {
            $objectCfg = $this->orm->config($objectName);
        } catch (\Exception $e) {
            return self::ERROR_INVALID_OBJECT;
        }

        if (!$objectCfg->fieldExists($fieldName)) {
            return self::ERROR_INVALID_FIELD;
        }

        $localisations = $this->getLocalisations();

        $fieldManager = new Orm\Record\Config\FieldManager();
        $fieldManager->removeField($objectCfg, $fieldName);

        if (!$objectCfg->save()) {
            return self::ERROR_FS;
        }

        $localisationKey = strtolower($objectName);

        foreach ($localisations as $file) {
            $localeName = basename(dirname($file));

            $translator = $this->getTranslator($localeName, $objectName);
            $translation = $translator->getTranslation($objectName);

            unset($translation['fields'][$fieldName]);

            $langStorage = $this->lanService->getStorage();
            $cfg = $langStorage->get($file);

            if ($cfg->offsetExists($localisationKey)) {
                $cfg->offsetUnset($localisationKey);
                if (!$langStorage->save($cfg)) {
                    return self::ERROR_FS_LOCALISATION;
                }
            }

            if (!$translator->save($objectName, $translation)) {
                return self::ERROR_FS_LOCALISATION;
            }
        }
        return 0;
    }

    /**
     * Rename object field
     * @param Orm\Record\Config $cfg
     * @param string $oldName
     * @param string $newName
     * @return int 0 on success or error code
     * @throws \Exception
     */
    public function renameField(Orm\Record\Config $cfg, string $oldName, string $newName): int
    {
        $localisations = $this->getLocalisations();
        $langWritePath = $this->lanService->getStorage()->getWrite();

        foreach ($localisations as $file) {
            if (file_exists($langWritePath . $file) && !is_writable($langWritePath . $file)) {
                return self::ERROR_FS_LOCALISATION;
            }
            $localeName = basename(dirname($file));
            $translator = $this->getTranslator($localeName, $cfg->getName());
            if (!$translator->removeObjectTranslation($cfg->getName(), true)) {
                return self::ERROR_FS_LOCALISATION;
            }
        }

        $localisationKey = strtolower($cfg->getName());
        $langStorage = $this->lanService->getStorage();

        foreach ($localisations as $file) {
            $langCfg = $langStorage->get($file, true, true);

            if ($langCfg->offsetExists($localisationKey)) {
                $langCfg->offsetUnset($localisationKey);
                if (!$langStorage->save($langCfg)) {
                    return self::ERROR_FS_LOCALISATION;
                }
            }

            $localeName = basename(dirname($file));
            $translator = $this->getTranslator($localeName, $cfg->getName());
            $translation = $translator->getTranslation($cfg->getName());

            if (isset($translation['fields'][$oldName])) {
                $translation['fields'][$newName] = $translation['fields'][$oldName];
            }
            unset($translation['fields'][$oldName]);

            if (!$translator->save($cfg->getName(), $translation)) {
                return self::ERROR_FS_LOCALISATION;
            }
        }

        $fieldManager = new Orm\Record\Config\FieldManager();
        try {
            $fieldManager->renameField($cfg, $oldName, $newName);
        } catch (\Throwable $e) {
            return self::ERROR_EXEC;
        }

        if (!$cfg->save()) {
            return self::ERROR_EXEC;
        }
        // Rebuild database
        $builder = $this->orm->getBuilder($cfg->getName());

        if (!$builder->renameField($oldName, $newName)) {
            return self::ERROR_EXEC;
        }

        return 0;
    }

    /**
     * Rename Orm\Record
     * @param string $path - configs path
     * @param string $oldName
     * @param string $newName
     * @return int 0 on success or error code
     * @throws \Exception
     */
    public function renameObject(string $path, string $oldName, string $newName): int
    {
        $objectConfig = $this->orm->config($oldName);
        /*
         * Check fs write permissions for associated objects
         */
        $expert = new Orm\Record\Expert(
            $this->orm,
            $this->configStorage,
            new Orm\Record\Manager($this->configStorage, $this->orm)
        );
        $assoc = $expert->getAssociatedStructures($oldName);

        if (!empty($assoc)) {
            foreach ($assoc as $config) {
                if (!is_writable($this->configStorage->getPath($path) . strtolower($config['object']) . '.php')) {
                    return self::ERROR_FS_LOCALISATION;
                }
            }
        }

        /*
         * Check fs write permissions for localisation files
         */
        $langStorage = $this->lanService->getStorage();
        $localisations = $this->getLocalisations();
        $langWritePath = $langStorage->getWrite();

        $translator = $objectConfig->getTranslator();

        foreach ($localisations as $file) {
            if (file_exists($langWritePath . $file) && !is_writable($langWritePath . $file)) {
                return self::ERROR_FS_LOCALISATION;
            }

            $localeName = basename(dirname($file));
            $translator = $this->getTranslator($localeName, $oldName);
            if (!$translator->removeObjectTranslation($oldName, true)) {
                return self::ERROR_FS_LOCALISATION;
            }
        }

        $localisationKey = strtolower($oldName);
        foreach ($localisations as $file) {
            $localeName = basename(dirname($file));

            $cfg = $langStorage->get($file, true, true);
            if ($cfg->offsetExists($localisationKey)) {
                $cfg->remove($localisationKey);
                if (!$langStorage->save($cfg)) {
                    return self::ERROR_FS;
                }
            }

            $localeName = basename(dirname($file));
            $translator = $this->getTranslator($localeName, $oldName);
            $oldTranslations = $translator->getTranslation($oldName);
            if (!$translator->removeObjectTranslation($oldName)) {
                return self::ERROR_FS_LOCALISATION;
            }
            $translator = $this->getTranslator($localeName, $newName);
            if (!$translator->save($newName, $oldTranslations)) {
                return self::ERROR_FS_LOCALISATION;
            }
        }

        $newFileName = $this->configStorage->getWrite() . $path . $newName . '.php';
        $oldFileName = $this->configStorage->getPath($path) . $oldName . '.php';

        if (!@rename($oldFileName, $newFileName)) {
            return self::ERROR_FS;
        }

        $fieldManager = new Orm\Record\Config\FieldManager();

        if (!empty($assoc)) {
            foreach ($assoc as $config) {
                $object = $config['object'];
                $fields = $config['fields'];

                $oConfig = $this->orm->config($object);

                foreach ($fields as $fName => $fType) {
                    if ($oConfig->getField($fName)->isLink()) {
                        if (!$fieldManager->setFieldLink($oConfig, $fName, $newName)) {
                            return self::ERROR_EXEC;
                        }
                    }
                }

                if (!$oConfig->save()) {
                    return self::ERROR_FS;
                }
            }
        }
        return 0;
    }

    /**
     * Sync Distributed index structure
     * add fields into ObjectId
     * @param string $objectName
     * @return bool
     * @throws Exception
     */
    public function syncDistributedIndex($objectName)
    {
        $oConfig = $this->orm->config($objectName);
        $distIndexes = $oConfig->getDistributedIndexesConfig();

        $idObject = $oConfig->getDistributedIndexObject();
        $idObjectConfig = $this->orm->config($idObject);

        $indexManager = new Orm\Record\Config\IndexManager();
        $fieldManager = new Orm\Record\Config\FieldManager();

        foreach ($distIndexes as $name => $info) {
            if ($name === $idObjectConfig->getPrimaryKey()) {
                continue;
            }

            /**
             * @var array<string,mixed>
             */
            $cfg = $oConfig->getFieldConfig((string)$name);
            $cfg['system'] = false;
            $cfg['db_isNull'] = true;

            $unique = false;
            if (isset($cfg['unique']) && $cfg['unique']) {
                $unique = true;
            }
            $fieldManager->setFieldConfig($idObjectConfig, (string)$name, $cfg);

            $indexManager->setIndexConfig(
                $idObjectConfig,
                (string)$name,
                [
                    'columns' => [$name],
                    'fulltext' => false,
                    'unique' => $unique,
                ]
            );
        }
        return $idObjectConfig->save();
    }


    public function getTranslator(string $locale, string $objectName): Translator
    {
        $ormConfig = $this->configStorage->get('orm.php');
        $commonFile = $locale . '/objects.php';
        $objectsDir = $locale . '/' . $ormConfig->get('translations_dir');
        return new Translator($commonFile, $objectsDir, $this->lanService);
    }
}
