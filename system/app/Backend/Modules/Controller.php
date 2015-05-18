<?php
class Backend_Modules_Controller extends Backend_Controller{
    /**
     * (non-PHPdoc)
     * @see Backend_Controller::indexAction()
     */
     public function indexAction()
     {    
         $res = Resource::getInstance();  
         $res->addJs('/js/app/system/FilesystemWindow.js'  , 1);
	     $res->addJs('/js/app/system/crud/modules.js'  , 2);
		 $res->addJs('/js/app/system/ImageField.js'  , 1);
		 $res->addJs('/js/app/system/IconField.js'  , 1);
		 $res->addJs('/js/app/system/HistoryPanel.js'  , 1);
		 $res->addJs('/js/app/system/EditWindow.js'  , 1);
	       $this->_resource->addInlineJs('
        	var canEdit = '.((integer)$this->_user->canEdit($this->_module)).';
        	var canDelete = '.((integer)$this->_user->canDelete($this->_module)).';
        ');
    }
    
    /**
     * Get modules list
     */
    public function listAction()
    {
		$type = Request::post('type' , Filter::FILTER_STRING, false);

		if(!$type)
			Response::jsonError($this->_lang->get('WRONG_REQUEST'));

		switch($type){
			case 'backend':
				$this->listBackend();
				break;
			case 'frontend':
				$this->listFrontend();
				break;
			default:
				Response::jsonError($this->_lang->get('WRONG_REQUEST'));
		}
    }
	/**
	 * Get list of backend modules
	 */
	public function listBackend()
	{
		$manager = new Backend_Modules_Manager();
		$data = $manager->getList();

		foreach ($data as $k=>&$item)
		{
			$item['related_files']= '';
			$classFile = './system/app/'.str_replace('_', '/', $item['class']).'.php';

			if(file_exists($classFile))
				$item['related_files'].= $classFile.'</br>';

			$item['iconUrl'] = $this->_configMain->get('wwwroot').$item['icon'];

			if(!empty($item['designer']))
			{
				$item['related_files'].=$item['designer'].'</br>';
				$crudJs = './js/app/system/crud/' . strtolower($manager->getModuleName($item['class'])) . '.js';
				if(file_exists($crudJs)){
					$item['related_files'].=$crudJs.'</br>';
				}

				$actionJs = './js/app/actions/' . strtolower($manager->getModuleName($item['class'])) . '.js';
				if(file_exists($actionJs)){
					$item['related_files'].=$actionJs.'</br>';
				}
			}
		}
		Response::jsonSuccess(array_values($data));
	}

	/**
	 * Get list of frontend modules
	 */
	public function listFrontend()
	{
		$manager = new Backend_Fmodules_Manager();
		$data = $manager->getList();

		foreach($data as $k => $v)
			$data[$k]['name'] = $k;

		Response::jsonSuccess(array_values($data));
	}

    /**
     * Update modules list
     */
    public function updateAction()
    {
		$this->_checkCanEdit();
		$type = Request::post('type' , Filter::FILTER_STRING, false);

		if(!$type)
			Response::jsonError($this->_lang->get('WRONG_REQUEST'));

		switch($type){
			case 'backend':
				$this->updateBackendRecord();
				break;
			case 'frontend':
				$this->updateFrontendRecord();
				break;
			default:
				Response::jsonError($this->_lang->get('WRONG_REQUEST'));
		}
    }

	/**
	 * Update module record
	 */
	public function updateBackendRecord()
	{
		$id = Request::post('id' , Filter::FILTER_STRING , false);

		if(!$id)
			Response::jsonError($this->_lang->get('INVALID_VALUE'));

		$acceptedFields =  array(
			'dev' => Filter::FILTER_BOOLEAN ,
			'active' => Filter::FILTER_BOOLEAN ,
			'title'=> Filter::FILTER_STRING ,
			'designer'=> Filter::FILTER_STRING,
			'controller'=> Filter::FILTER_STRING,
			'in_menu'=> Filter::FILTER_BOOLEAN ,
			'icon'=> Filter::FILTER_STRING
		);

		$data = array();
		foreach($acceptedFields as $name => $type){
			$data[$name] = Request::post($name , $type , null);
		}

		$manager = new Backend_Modules_Manager();

		if(!$manager->isValidModule($id))
			Response::jsonError($this->_lang->get('WRONG_REQUEST'));

		if($manager->updateModule($id , $data)){
			Response::jsonSuccess(array('id'=>$id));
		}else{
			Response::jsonError($this->_lang->get('CANT_WRITE_FS'));
		}
	}

    /**
     * Get list of available controllers
     */
    public function controllersAction()
    {
        $appPath = $this->_configMain['application_path'];
        $folders = File::scanFiles($this->_configMain['backend_controllers'],false,true,File::Dirs_Only);
        $data = array();
        
        $systemControllers = $this->_configBackend->get('system_controllers');
        
        if(!empty($folders))
        {
        	foreach ($folders as $item)
        	{
        		$name = basename($item);
        		/*
        		 * Skip system controller
        		 */
        		if(in_array($name, $systemControllers , true))
        			continue;
        		
        		if(file_exists($item.'/Controller.php'))
        		{
        			$name = str_replace($appPath, '', $item.'/Controller.php');
        			$name = Utils::classFromPath($name);
        			$data[] = array('id'=>$name,'title'=>$name);
        		}
        	}
        }
        Response::jsonSuccess($data);  			
    }
    
	/**
	 * Get Designer projects tree list
	 */
	public function fslistAction()
	{
		$node = Request::post('node', 'string', '');
		$manager = new Designer_Manager($this->_configMain);
		Response::jsonArray($manager->getProjectsList($node));
	}
	
	/**
	 * Get list of registered Db_Object's
	 */
	public function objectsAction()
	{
		$manager = new Db_Object_Manager();
		$list = $manager->getRegisteredObjects();
		$data = array();
		
		$systemObjects = $this->_configBackend['system_objects'];
		
		foreach ($list as $key)
			if(!in_array(ucfirst($key), $systemObjects) && !class_exists('Backend_'.Utils_String::formatClassName($key).'_Controller'))
				$data[]= array('id'=>$key , 'title'=>Db_Object_Config::getInstance($key)->getTitle());
		
		Response::jsonSuccess($data);
	}
	
	/**
	 * Create new module
	 */
	public function createAction()
	{				
		$this->_checkCanEdit();
		
		$object = Request::post('object', 'string', false);
		
		if(!$object)
			Response::jsonError($this->_lang->WRONG_REQUEST);
		
		$object = Utils_String::formatClassName($object);
		
		$class = 'Backend_' . $object . '_Controller';
				
		if(class_exists($class))
			Response::jsonError($this->_lang->FILL_FORM , array('id'=>'name','msg'=>$this->_lang->SB_UNIQUE));
		
		$designerConfig = Config::factory(Config::File_Array, $this->_configMain['configs'] . 'designer.php');
				
		$projectFile = $designerConfig->get('configs') . strtolower($object) . '.designer.dat';
		
		if(file_exists($projectFile))
			Response::jsonError($this->_lang->FILE_EXISTS . '(' . $projectFile . ')');
		
		$actionFile = $designerConfig->get('actionjs_path') . strtolower($object) . '.js';
		
		if(file_exists($actionFile))
			Response::jsonError($this->_lang->FILE_EXISTS . '(' . $actionFile . ')');

		$objectConfig = Db_Object_Config::getInstance($object);

		// Check ACL permissions
		$acl = $objectConfig->getAcl();
		if($acl){
			if(!$acl->can(Db_Object_Acl::ACCESS_CREATE , $object)  || 	!$acl->can(Db_Object_Acl::ACCESS_VIEW , $object)){
				Response::jsonError($this->_lang->get('ACL_ACCESS_DENIED'));
			}
		}

		$manager = new Db_Object_Manager();
		
		if(!$manager->objectExists($object))
			Response::jsonError($this->_lang->FILL_FORM , array('id'=>'object','msg'=>$this->_lang->INVALID_VALUE));
			
	    $codeGenadApter = $this->_configBackend->get('modules_codegen');
	    $codeGen = new $codeGenadApter();
		try{
			if($objectConfig->isRevControl())
				$codeGen->createVcModule($object,  $projectFile , $actionFile);
			else
				$codeGen->createModule($object,  $projectFile , $actionFile);
			
		}catch (Exception $e){
			Response::jsonError($e->getMessage());
		}

		
		$userInfo = User::getInstance()->getInfo();	
		$per = Model::factory('Permissions');
		
		if(!$per->setGroupPermissions($userInfo['group_id'], $object , 1 , 1 , 1 , 1))
			Response::jsonError($this->_lang->CANT_EXEC);
		
		Response::jsonSuccess(
				array(
						'class'=>$class,
						'name'=>$object , 
						'active'=>true,
						'dev'=>false,
						'title'=>$objectConfig->getTitle(),
						'designer'=> $projectFile
				)
		);
	}

	/**
	 * Get module data
	 */
	public function loadDataAction()
	{
		$id = Request::post('id' , Filter::FILTER_STRING , false);
		$type = Request::post('type' , Filter::FILTER_STRING , false);

		if(!$id || !$type)
			Response::jsonError($this->_lang->get('WRONG_REQUEST'));

		switch($type){
			case 'backend':
				 $manager = new Backend_Modules_Manager();
				 Response::jsonSuccess($manager->getModuleConfig($id));
				break;
			case 'frontend':
				$manager = new Backend_Fmodules_Manager();
				Response::jsonSuccess($manager->getModuleConfig($id));
				break;
			default:
				Response::jsonError($this->_lang->get('WRONG_REQUEST'));
		}
	}
	
	/**
	 * Delete module
	 */
	public function deletemoduleAction()
	{
	  $this->_checkCanEdit();
	  $module = Request::post('id', 'string', false);
	  $removeRelated = Request::post('delete_related', 'boolean', false);
	  
	  $manager = new Backend_Modules_Manager();
	  $moduleName = $manager->getModuleName($module);
	  
	  if(!$module || !strlen($module) || !$manager->isValidModule($moduleName))
	      Response::jsonError($this->_lang->WRONG_REQUEST);
	  
      $filesToDelete = array();
      
	  if($removeRelated)
	  {    	  
      	  $item = $manager->getModuleConfig($moduleName);
      	  
          $classFile = './system/app/'.str_replace('_', '/', $item['class']).'.php';
      	  if(file_exists($classFile))
      	    $filesToDelete[] = $classFile;
      		   	
      	  if(!empty($item['designer']))
      	  {
      	    if(file_exists($item['designer']))
      	      $filesToDelete[] = $item['designer'];
   	    
      	    $crudJs = './js/app/system/crud/' . strtolower($manager->getModuleName($item['class'])) . '.js';
      	    if(file_exists($crudJs)){
      	       $filesToDelete[]=$crudJs;
      	    }
      	    
      	    $actionJs = './js/app/actions/' . strtolower($manager->getModuleName($item['class'])) . '.js';
      	    if(file_exists($actionJs)){
      	       $filesToDelete[]=$actionJs;
      	    }       		  		    
      	 }
	  }
	  
	  // check before deleting
	  if(!empty($filesToDelete))
	  {
	    $err = array();
	    foreach ($filesToDelete as $file){
	      if(!is_writable($file))
	        $err[] = $file;
	    }
	    
	    if(!empty($err))
	      Response::jsonError($this->_lang->CANT_WRITE_FS . "\n<br>".implode(",\n<br>", $err));
	  }
	  
	  $manager->removeModule($moduleName);
	  
	  if(!$manager->save())
	    Response::jsonError($this->_lang->CANT_WRITE_FS.' '.$manager->getConfig()->getName());
	  
	  // try to delete
	  if(!empty($filesToDelete))
	  {
	    $err = array();
	    foreach ($filesToDelete as $file){
	      if(!unlink($file))
	        $err[] = $file;
	    }
	    
	    if(!empty($err))
	      Response::jsonError($this->_lang->CANT_WRITE_FS . "\n<br>".implode(",\n<br>", $err));
	  }
	  Response::jsonSuccess();
	}

	/**
	 * Get list of image folders
	 */
	public function iconDirsAction()
	{
		$path = Request::post('node', 'string', '');
		$path = str_replace('.','', $path);

		$dirPath = $this->_configMain->get('wwwpath');

		if(!is_dir($dirPath.$path))
			Response::jsonArray(array());

		$files = File::scanFiles($dirPath . $path, false, false , File::Dirs_Only);

		if(empty($files))
			Response::jsonArray(array());

		sort($files);
		$list = array();

		foreach($files as $k=>$fpath)
		{
			$text = basename($fpath);

			$obj = new stdClass();
			$obj->id = str_replace($dirPath, '', $fpath);
			$obj->text = $text;
			$obj->url = '/' . $obj->id;

			if(is_dir($fpath))
			{
				$obj->expanded = false;
				$obj->leaf = false;
			}
			else
			{
				$obj->leaf = true;
			}
			$list[] = $obj;
		}
		Response::jsonArray($list);
	}

	/**
	 * Get image list
	 */
	public function iconListAction()
	{
		$dirPath = $this->_configMain->get('wwwpath');
		$dir = Request::post('dir', 'string', '');

		if(!is_dir($dirPath . $dir))
			Response::jsonArray(array());

		$files = File::scanFiles($dirPath . $dir, array('.jpg','.png','.gif','.jpeg') , false , File::Files_Only);

		if(empty($files))
			Response::jsonArray(array());

		sort($files);
		$list = array();

		foreach($files as $k=>$fpath)
		{
			$text  = basename($fpath);
			$list[] = array(
				'name'=>$text,
				'url'=>str_replace($dirPath .'/', $this->_configMain->get('wwwroot'), $fpath),
				'path'=>str_replace($dirPath .'/', '', $fpath),
			);
		}
		Response::jsonSuccess($list);
	}
}