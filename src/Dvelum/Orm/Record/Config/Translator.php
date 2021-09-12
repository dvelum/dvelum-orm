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

namespace Dvelum\Orm\Record\Config;

use Dvelum\Config\Storage\StorageInterface;
use Dvelum\Lang;

class Translator
{
    protected string $commonPath = '';
    protected string $localesDir = '';

    /**
     * @var array<string,array>|null
     */
    protected ?array $translation = null;

    private StorageInterface $langStorage;
    private Lang $lang;

    /**
     * @param string $commonPath - path to translation Array config
     * @param string $localesDir - locales directory (relative)
     */
    public function __construct(string $commonPath, string $localesDir, Lang $lang)
    {
        $this->commonPath = $commonPath;
        $this->localesDir = $localesDir;
        $this->langStorage = $lang->getStorage();
        $this->lang = $lang;
    }

    /**
     * Get object fields translation
     * @param string $objectName
     * @param bool $force
     * @return array<string,mixed>
     */
    public function getTranslation(string $objectName, bool $force = false): array
    {
        if (!$this->translation || $force) {
            /**
             * @var array<string,array<string,string>>
             */
            $translations = $this->langStorage->get($this->commonPath, true, true)->__toArray();
            $this->translation = $translations;
        }

        if (!isset($this->translation[$objectName])) {
            $localFile = $this->localesDir . strtolower($objectName) . '.php';

            if ($this->langStorage->exists($localFile)) {
                $this->translation[$objectName] = $this->langStorage->get($localFile, true, true)->__toArray();
            }
        }

        if (isset($this->translation[$objectName])) {
            return $this->translation[$objectName];
        }
        return [];
    }

    /**
     * Get translations storage
     * @return StorageInterface
     */
    public function getStorage(): StorageInterface
    {
        return $this->langStorage;
    }

    /**
     * Get common config path
     * @return string
     */
    public function getcommonConfigPath(): string
    {
        return $this->commonPath;
    }

    /**
     * Translate Object config
     * @param string $objectName
     * @param array<int|string,mixed> $objectConfig
     * @throws \Exception
     */
    public function translate(string $objectName, &$objectConfig): void
    {
        $translation = $this->getTranslation($objectName);

        if (!empty($translation)) {
            if (isset($translation['title']) && strlen($translation['title'])) {
                $objectConfig['title'] = $translation['title'];
            } else {
                $objectConfig['title'] = $objectName;
            }

            if (isset($translation['fields']) && is_array($translation['fields'])) {
                $fieldTranslates = $translation['fields'];
            }
        } else {
            if (isset($translation['title']) && strlen($translation['title'])) {
                $objectConfig['title'] = $translation['title'];
            } else {
                $objectConfig['title'] = $objectName;
            }
        }

        $dictionary = $this->lang->getDictionary();

        foreach ($objectConfig['fields'] as $k => &$v) {
            if (isset($v['lazyLang']) && $v['lazyLang']) {
                if (isset($v['title'])) {
                    $v['title'] = $dictionary->get($v['title']);
                } else {
                    $v['title'] = '';
                }
            } elseif (isset($fieldTranslates[$k]) && strlen($fieldTranslates[$k])) {
                $v['title'] = $fieldTranslates[$k];
            } elseif (!isset($v['title']) || !strlen($v['title'])) {
                $v['title'] = $k;
            }
        }
        unset($v);
    }

    /**
     * Save object translation
     * @param string $objectName
     * @param array<string,string> $translationData
     * @return bool
     */
    public function save(string $objectName, array $translationData): bool
    {
        $localFile = $this->localesDir . strtolower($objectName) . '.php';

        if (!$this->langStorage->exists($localFile)) {
            if (!$this->langStorage->create($localFile)) {
                return false;
            }
        }

        $configFile = $this->langStorage->get($localFile);
        $configFile->setData($translationData);

        if (empty($configFile)) {
            return false;
        }

        if (!$this->getStorage()->save($configFile)) {
            return false;
        }

        $common = $this->langStorage->get($this->commonPath, true, true);

        if ($common->offsetExists($objectName)) {
            $common->offsetUnset($objectName);
            if (!$this->getStorage()->save($common)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Remove object translations
     * @param string $objectName
     * @param bool $checkOnly - only check filesystem permission to write file
     * @return bool
     */
    public function removeObjectTranslation(string $objectName, bool $checkOnly = false): bool
    {
        $localFile = $this->getStorage()->getWrite() . $this->localesDir . strtolower($objectName) . '.php';

        if (file_exists($localFile)) {
            if ($checkOnly) {
                return is_writable($localFile);
            } else {
                try {
                    return unlink($localFile);
                } catch (\Error $e) {
                    return false;
                }
            }
        }
        return true;
    }
}
