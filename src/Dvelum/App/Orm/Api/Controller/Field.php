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
use Dvelum\App\Orm\Api\Manager;
use Dvelum\Lang;
use Dvelum\Orm;
use Dvelum\Orm\Exception;

class Field extends Controller
{
    public function indexAction(): void
    {
    }

    /**
     * Save field configuration options
     */
    public function saveAction(): void
    {
        if (!$this->checkCanEdit()) {
            return;
        }

        $manager = new Manager($this->ormService, $this->container->get(Lang::class), $this->configStorage);

        $object = $this->request->post('objectName', 'string', false);
        $objectField = $this->request->post('objectField', 'string', false);
        $name = $this->request->post('name', 'string', false);

        if (!$object) {
            $this->response->error($this->lang->get('WRONG_REQUEST'));
            return;
        }

        if (!$name) {
            $this->response->error(
                $this->lang->get('FILL_FORM'),
                [
                    [
                        'id' => 'name',
                        'msg' => $this->lang->get('CANT_BE_EMPTY')
                    ]
                ]
            );
            return;
        }

        try {
            /**
             * @var Orm\Record\Config
             */
            $objectCfg = $this->ormService->config($object);
        } catch (Exception $e) {
            $this->response->error($this->lang->get('WRONG_REQUEST') . ' code 2');
            return;
        }

        $oFields = array_keys($objectCfg->getFieldsConfig());

        if ($objectField !== $name && in_array($name, $oFields, true)) {
            $this->response->error(
                $this->lang->get('FILL_FORM'),
                [['id' => 'name', 'msg' => $this->lang->get('SB_UNIQUE')]]
            );
            return;
        }

        $unique = $this->request->post('unique', 'str', '');
        $newConfig = array();
        $newConfig['type'] = $this->request->post('type', 'str', '');
        $newConfig['title'] = $this->request->post('title', 'str', '');
        $newConfig['unique'] = ($unique === false) ? '' : $unique;
        $newConfig['db_isNull'] = $this->request->post('db_isNull', 'boolean', false);
        $newConfig['required'] = $this->request->post('required', 'boolean', false);
        $newConfig['validator'] = $this->request->post('validator', 'string', '');

        if ($newConfig['type'] === 'link') {
            if ($newConfig['db_isNull']) {
                $newConfig['required'] = false;
            }
            /**
             * Process link field
             */
            $newConfig['link_config']['link_type'] = $this->request->post('link_type', 'str', 'object');

            if ($newConfig['link_config']['link_type'] === Orm\Record\Config::LINK_DICTIONARY) {
                $newConfig['link_config']['object'] = $this->request->post('dictionary', 'str', '');
                $newConfig['db_type'] = 'varchar';
                $newConfig['db_len'] = 255;
                $newConfig['db_isNull'] = false;

                if ($newConfig['required']) {
                    $newConfig['db_default'] = false;
                } else {
                    $newConfig['db_default'] = '';
                }
            } else {
                $linkedObject = $this->request->post('object', 'string', false);

                if (!$linkedObject) {
                    $this->response->error(
                        $this->lang->get('FILL_FORM'),
                        [['id' => 'object', 'msg' => $this->lang->get('CANT_BE_EMPTY')]]
                    );
                    return;
                }

                try {
                    $this->ormService->config($linkedObject);
                } catch (Exception $e) {
                    $this->response->error(
                        $this->lang->get('FILL_FORM'),
                        [['id' => 'object', 'msg' => $this->lang->get('INVALID_VALUE')]]
                    );
                    return;
                }
                $newConfig['link_config']['object'] = $linkedObject;

                switch ($newConfig['link_config']['link_type']) {
                    case Orm\Record\Config::LINK_OBJECT_LIST:
                        $newConfig['link_config']['relations_type'] = $this->request->post(
                            'relations_type',
                            'string',
                            false
                        );
                        if (
                            !in_array(
                                $newConfig['link_config']['relations_type'],
                                ['polymorphic', 'many_to_many'],
                                true
                            )
                        ) {
                            $newConfig['link_config']['relations_type'] = 'polymorphic';
                        }
                        $newConfig['db_type'] = 'longtext';
                        $newConfig['db_isNull'] = false;
                        $newConfig['db_default'] = '';
                        break;
                    case Orm\Record\Config::LINK_OBJECT:
                        $newConfig['db_isNull'] = !$newConfig['required'];
                        $newConfig['db_type'] = 'bigint';
                        $newConfig['db_default'] = false;
                        $newConfig['db_unsigned'] = true;
                        break;
                }
            }
        } elseif ($newConfig['type'] === 'encrypted') {
            $setDefault = $this->request->post('set_default', 'boolean', false);

            if (!$setDefault) {
                $newConfig['db_default'] = false;
            } else {
                $newConfig['db_default'] = $this->request->post('db_default', 'string', false);
            }

            $newConfig['db_type'] = 'longtext';
            $newConfig['is_search'] = false;
            $newConfig['allow_html'] = false;
        } else {
            $setDefault = $this->request->post('set_default', 'boolean', false);
            /*
             * Process std field
             */
            $newConfig['db_type'] = $this->request->post('db_type', 'str', 'false');

            if (!$newConfig['db_type']) {
                $this->response->error(
                    $this->lang->get('FILL_FORM'),
                    [['id' => 'db_type', 'msg' => $this->lang->get('CANT_BE_EMPTY')]]
                );
                return;
            }


            if ($newConfig['db_type'] === 'bool' || $newConfig['db_type'] === 'boolean') {
                /*
                 * boolean
                 */
                $newConfig['required'] = false;
                $newConfig['db_default'] = (int)$this->request->post('db_default', 'bool', false);
            } elseif (in_array($newConfig['db_type'], Orm\Record\BuilderFactory::$intTypes, true)) {
                /*
                 * integer
                 */
                $newConfig['db_default'] = $this->request->post('db_default', 'integer', false);
                $newConfig['db_unsigned'] = $this->request->post('db_unsigned', 'bool', false);
            } elseif (in_array($newConfig['db_type'], Orm\Record\BuilderFactory::$floatTypes)) {
                /*
                 * float
                 */
                $newConfig['db_default'] = $this->request->post('db_default', 'float', false);
                $newConfig['db_unsigned'] = $this->request->post('db_unsigned', 'bool', false);
                $newConfig['db_scale'] = $this->request->post('db_scale', 'integer', 0);
                $newConfig['db_precision'] = $this->request->post('db_precision', 'integer', 0);
            } elseif (in_array($newConfig['db_type'], Orm\Record\BuilderFactory::$charTypes, true)) {
                /*
                 * char
                 */
                $newConfig['db_default'] = $this->request->post('db_default', 'string', false);
                $newConfig['db_len'] = $this->request->post('db_len', 'integer', 255);
                $newConfig['is_search'] = $this->request->post('is_search', 'bool', false);
                $newConfig['allow_html'] = $this->request->post('allow_html', 'bool', false);
            } elseif (in_array($newConfig['db_type'], Orm\Record\BuilderFactory::$textTypes, true)) {
                /*
                 * text
                 */
                $newConfig['db_default'] = $this->request->post('db_default', 'string', false);
                $newConfig['is_search'] = $this->request->post('is_search', 'bool', false);
                $newConfig['allow_html'] = $this->request->post('allow_html', 'bool', false);

                if (!$newConfig['required']) {
                    $newConfig['db_isNull'] = true;
                }
            } elseif (in_array($newConfig['db_type'], Orm\Record\BuilderFactory::$dateTypes, true)) {
                /*
                 * date
                 */
                if (!$newConfig['required']) {
                    $newConfig['db_isNull'] = true;
                }
            } else {
                $this->response->error(
                    $this->lang->get('FILL_FORM'),
                    [['id' => 'db_type', 'msg' => $this->lang->get('INVALID_VALUE')]]
                );
            }

            if (!$setDefault) {
                $newConfig['db_default'] = false;
            }
        }
        $fieldManager = new Orm\Record\Config\FieldManager();
        /**
         * @todo Rename
         */
        if ($objectField !== $name && !empty($objectField)) {
            $fieldManager = new Orm\Record\Config\FieldManager();
            $fieldManager->setFieldConfig($objectCfg, $objectField, $newConfig);

            $renameResult = $manager->renameField($objectCfg, $objectField, $name);

            switch ($renameResult) {
                case Manager::ERROR_EXEC:
                    $this->response->error($this->lang->get('CANT_EXEC'));
                    return;
                case Manager::ERROR_FS_LOCALISATION:
                    $this->response->error(
                        $this->lang->get('CANT_WRITE_FS') . ' (' . $this->lang->get('LOCALIZATION_FILE') . ')'
                    );
                    return;
            }
        } else {
            $fieldManager->setFieldConfig($objectCfg, $name, $newConfig);
        }

        if ($objectCfg->save()) {
            /**
             * @todo refactor
             */
            $builder = $this->ormService->getBuilder($object);
            $builder->build();
            $this->response->success();
        } else {
            $this->response->error($this->lang->get('CANT_WRITE_FS'));
        }
    }

    /**
     * Delete object field
     */
    public function deleteAction(): void
    {
        if (!$this->checkCanDelete()) {
            return;
        }

        $object = $this->request->post('object', 'string', false);
        $field = $this->request->post('name', 'string', false);

        $manager = new Manager($this->ormService, $this->container->get(Lang::class), $this->configStorage);
        $result = $manager->removeField($object, $field);

        switch ($result) {
            case 0:
                $this->response->success();
                break;
            case Manager::ERROR_INVALID_FIELD:
            case Manager::ERROR_INVALID_OBJECT:
                $this->response->error($this->lang->get('WRONG_REQUEST'));

                break;
            case Manager::ERROR_FS_LOCALISATION:
                $this->response->error(
                    $this->lang->get('CANT_WRITE_FS') . ' (' . $this->lang->get('LOCALIZATION_FILE') . ')'
                );

                break;
            case Manager::ERROR_FS:
                $this->response->error($this->lang->get('CANT_WRITE_FS'));

                break;
            default:
                $this->response->error($this->lang->get('CANT_EXEC'));
        }
    }

    /**
     * Load Field config
     */
    public function loadAction(): void
    {
        $object = $this->request->post('object', 'string', false);
        $field = $this->request->post('field', 'string', false);

        if (!$object || !$field) {
            $this->response->error($this->lang->get('INVALID_VALUE'));
            return;
        }

        $manager = new Manager($this->ormService, $this->container->get(Lang::class), $this->configStorage);
        $result = $manager->getFieldConfig($object, $field);

        if (!$result) {
            $this->response->error($this->lang->get('INVALID_VALUE'));
        } else {
            $this->response->success($result);
        }
    }
}
