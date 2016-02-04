<?php
/* Copyright (C) NAVER <http://www.navercorp.com> */
/**
 * @class  installController
 * @author NAVER (developers@xpressengine.com)
 * @brief install module of the Controller class
 */
class installController extends install
{
	var $flagLicenseAgreement = './files/env/license_agreement';

	/**
	 * @brief Initialization
	 */
	function init()
	{
		// Stop if already installed.
		if (Context::isInstalled())
		{
			return new Object(-1, 'msg_already_installed');
		}
		
		$this->db_tmp_config_file = _XE_PATH_.'files/config/tmpDB.config.php';
		$this->etc_tmp_config_file = _XE_PATH_.'files/config/tmpEtc.config.php';
	}

	/**
	 * @brief division install step... DB Config temp file create
	 */
	function procDBConfig()
	{
		// Get DB config variables.
		$config = Context::gets('db_type', 'db_host', 'db_port', 'db_user', 'db_pass', 'db_database', 'db_prefix');
		
		// Create a temporary setting object.
		$db_info = new stdClass();
		$db_info->master_db = array();
		$db_info->master_db['db_type'] = $config->db_type;
		$db_info->master_db['db_hostname'] =  $config->db_host;
		$db_info->master_db['db_port'] =  $config->db_port;
		$db_info->master_db['db_userid'] =  $config->db_user;
		$db_info->master_db['db_password'] =  $config->db_pass;
		$db_info->master_db['db_database'] =  $config->db_database;
		$db_info->master_db['db_table_prefix'] =  $config->db_prefix;
		$db_info->slave_db = array($db_info->master_db);
		$db_info->default_url = Context::getRequestUri();
		$db_info->lang_type = Context::getLangType();
		$db_info->use_mobile_view = 'Y';
		Context::setDBInfo($db_info);
		
		// Check connection to the DB.
		$oDB = DB::getInstance();
		$output = $oDB->getError();
		if (!$output->toBool() || !$oDB->isConnected())
		{
			return $output;
		}
		
		// Check if MySQL server supports InnoDB.
		if(stripos($config->db_type, 'innodb') !== false)
		{
			$innodb_supported = false;
			$show_engines = $oDB->_fetch($oDB->_query('SHOW ENGINES'));
			foreach($show_engines as $engine_info)
			{
				if(strcasecmp($engine_info->Engine, 'InnoDB') === 0)
				{
					$innodb_supported = true;
				}
			}
			
			// If server does not support InnoDB, fall back to default storage engine (usually MyISAM).
			if(!$innodb_supported)
			{
				$config->db_type = str_ireplace('_innodb', '', $config->db_type);
			}
		}
		
		// Check if MySQL server supports utf8mb4.
		if(stripos($config->db_type, 'mysql') !== false)
		{
			$oDB->charset = $oDB->getBestSupportedCharset();
			$config->db_charset = $oDB->charset;
		}
		
		// Save DB config in session.
		$_SESSION['db_config'] = $config;
		
		// Continue the installation.
		if(!in_array(Context::getRequestMethod(), array('XMLRPC','JSON')))
		{
			$returnUrl = Context::get('success_return_url') ? Context::get('success_return_url') : getNotEncodedUrl('', 'act', 'dispInstallOtherConfig');
			$this->setRedirectUrl($returnUrl);
		}
	}

	/**
	 * @brief Install with received information
	 */
	function procInstall($install_config = null)
	{
		// Check if it is already installed
		if (Context::isInstalled())
		{
			return new Object(-1, 'msg_already_installed');
		}
		
		// Get install parameters.
		$config = Rhymix\Framework\Config::getDefaults();
		if ($install_config)
		{
			$install_config = (array)$install_config;
			$config['db']['master']['type'] = str_replace('_innodb', '', $install_config['db_type']);
			$config['db']['master']['host'] = $install_config['db_hostname'];
			$config['db']['master']['port'] = $install_config['db_port'];
			$config['db']['master']['user'] = $install_config['db_userid'];
			$config['db']['master']['pass'] = $install_config['db_password'];
			$config['db']['master']['database'] = $install_config['db_database'];
			$config['db']['master']['prefix'] = $install_config['db_table_prefix'];
			$config['db']['master']['charset'] = $install_config['db_charset'];
			$config['db']['master']['engine'] = strpos($install_config['db_type'], 'innodb') !== false ? 'innodb' : (strpos($install_config['db_type'], 'mysql') !== false ? 'myisam' : null);
			$config['use_rewrite'] = $install_config['use_rewrite'] === 'Y' ? true : false;
			$config['url']['ssl'] = $install_config['use_ssl'] ?: 'none';
			$time_zone = $install_config['time_zone'];
			$user_info = new stdClass;
			$user_info->email_address = $install_config['email_address'];
			$user_info->password = $install_config['password'];
			$user_info->nick_name = $install_config['nick_name'];
			$user_info->user_id = $install_config['user_id'];
		}
		else
		{
			$config['db']['master']['type'] = str_replace('_innodb', '', $_SESSION['db_config']->db_type);
			$config['db']['master']['host'] = $_SESSION['db_config']->db_host;
			$config['db']['master']['port'] = $_SESSION['db_config']->db_port;
			$config['db']['master']['user'] = $_SESSION['db_config']->db_user;
			$config['db']['master']['pass'] = $_SESSION['db_config']->db_pass;
			$config['db']['master']['database'] = $_SESSION['db_config']->db_database;
			$config['db']['master']['prefix'] = $_SESSION['db_config']->db_prefix;
			$config['db']['master']['charset'] = $_SESSION['db_config']->db_charset;
			$config['db']['master']['engine'] = strpos($_SESSION['db_config']->db_type, 'innodb') !== false ? 'innodb' : (strpos($_SESSION['db_config']->db_type, 'mysql') !== false ? 'myisam' : null);
			$config['use_rewrite'] = $_SESSION['use_rewrite'] === 'Y' ? true : false;
			$config['url']['ssl'] = Context::get('use_ssl') ?: 'none';
			$time_zone = Context::get('time_zone');
			$user_info = Context::gets('email_address', 'password', 'nick_name', 'user_id');
		}
		
		// Fix the database table prefix.
		$config['db']['master']['prefix'] = rtrim($config['db']['master']['prefix'], '_');
		if ($config['db']['master']['prefix'] !== '')
		{
			$config['db']['master']['prefix'] .= '_';
		}
		
		// Set the default language.
		$config['locale']['default_lang'] = Context::getLangType();
		$config['locale']['enabled_lang'] = array($config['locale']['default_lang']);
		
		// Set the internal and default time zones.
		if (strpos($time_zone, '/') !== false)
		{
			$config['locale']['default_timezone'] = $time_zone;
		}
		else
		{
			$user_timezone = intval(Rhymix\Framework\DateTime::getTimezoneOffsetByLegacyFormat($time_zone ?: '+0900') / 3600);
			switch ($user_timezone)
			{
				case 9:
					$config['locale']['default_timezone'] = 'Asia/Seoul'; break;
				case 0:
					$config['locale']['default_timezone'] = 'Etc/UTC'; break;
				default:
					$config['locale']['default_timezone'] = 'Etc/GMT' . ($user_timezone > 0 ? '-' : '+') . abs($user_timezone);
			}
		}
		$config['locale']['internal_timezone'] = intval(date('Z'));
		
		// Set the default URL.
		$config['url']['default'] = Context::getRequestUri();
		
		// Load the new configuration.
		Context::loadDBInfo($config);
		
		// Check DB.
		$oDB = DB::getInstance();
		if (!$oDB->isConnected())
		{
			return $oDB->getError();
		}
		
		// Assign a temporary administrator while installing.
		foreach ($user_info as $key => $val)
		{
			Context::set($key, $val, true);
		}
		$user_info->is_admin = 'Y';
		Context::set('logged_info', $user_info);
		
		// Install all the modules.
		try
		{
			$oDB->begin();
			$this->installDownloadedModule();
			$oDB->commit();
		}
		catch(Exception $e)
		{
			$oDB->rollback();
			return new Object(-1, $e->getMessage());
		}
		
		// Execute the install script.
		$scripts = FileHandler::readDir(_XE_PATH_ . 'modules/install/script', '/(\.php)$/');
		if(count($scripts))
		{
			sort($scripts);
			foreach($scripts as $script)
			{
				$script_path = FileHandler::getRealPath('./modules/install/script/');
				$output = include($script_path . $script);
			}
		}
		
		// Save the new configuration.
		Rhymix\Framework\Config::save($config);
		
		// Unset temporary session variables.
		unset($_SESSION['use_rewrite']);
		unset($_SESSION['db_config']);
		
		// Redirect to the home page.
		$this->setMessage('msg_install_completed');
		if(!in_array(Context::getRequestMethod(),array('XMLRPC','JSON')))
		{
			$returnUrl = Context::get('success_return_url') ? Context::get('success_return_url') : getNotEncodedUrl('');
			$this->setRedirectUrl($returnUrl);
		}
		
		return new Object();
	}

	/**
	 * @brief Set FTP Information
	 */
	function procInstallFTP()
	{
		if(Context::isInstalled()) return new Object(-1, 'msg_already_installed');
		$ftp_info = Context::gets('ftp_host', 'ftp_user','ftp_password','ftp_port','ftp_root_path');
		$ftp_info->ftp_port = (int)$ftp_info->ftp_port;
		if(!$ftp_info->ftp_port) $ftp_info->ftp_port = 21;
		if(!$ftp_info->ftp_host) $ftp_info->ftp_host = '127.0.0.1';
		if(!$ftp_info->ftp_root_path) $ftp_info->ftp_root_path = '/';

		$buff = array('<?php if(!defined("__XE__")) exit();');
		$buff[] = "\$ftp_info = new stdClass();";
		foreach($ftp_info as $key => $val)
		{
			$buff[] = sprintf("\$ftp_info->%s='%s';", $key, str_replace("'","\\'",$val));
		}

		// If safe_mode
		if(ini_get('safe_mode'))
		{
			if(!$ftp_info->ftp_user || !$ftp_info->ftp_password) return new Object(-1,'msg_safe_mode_ftp_needed');

			$oFtp = new ftp();
			if(!$oFtp->ftp_connect($ftp_info->ftp_host, $ftp_info->ftp_port)) return new Object(-1, sprintf(Context::getLang('msg_ftp_not_connected'), $ftp_info->ftp_host));

			if(!$oFtp->ftp_login($ftp_info->ftp_user, $ftp_info->ftp_password))
			{
				$oFtp->ftp_quit();
				return new Object(-1,'msg_ftp_invalid_auth_info');
			}

			if(!is_dir(_XE_PATH_.'files') && !$oFtp->ftp_mkdir($ftp_info->ftp_root_path.'files'))
			{
				$oFtp->ftp_quit();
				return new Object(-1,'msg_ftp_mkdir_fail');
			}

			if(!$oFtp->ftp_site("CHMOD 777 ".$ftp_info->ftp_root_path.'files'))
			{
				$oFtp->ftp_quit();
				return new Object(-1,'msg_ftp_chmod_fail');
			}

			if(!is_dir(_XE_PATH_.'files/config') && !$oFtp->ftp_mkdir($ftp_info->ftp_root_path.'files/config'))
			{
				$oFtp->ftp_quit();
				return new Object(-1,'msg_ftp_mkdir_fail');
			}

			if(!$oFtp->ftp_site("CHMOD 777 ".$ftp_info->ftp_root_path.'files/config'))
			{
				$oFtp->ftp_quit();
				return new Object(-1,'msg_ftp_chmod_fail');
			}

			$oFtp->ftp_quit();
		}

		FileHandler::WriteFile(Context::getFTPConfigFile(), join(PHP_EOL, $buff));
	}

	function procInstallCheckFtp()
	{
		$ftp_info = Context::gets('ftp_user','ftp_password','ftp_port','sftp');
		$ftp_info->ftp_port = (int)$ftp_info->ftp_port;
		if(!$ftp_info->ftp_port) $ftp_info->ftp_port = 21;
		if(!$ftp_info->sftp) $ftp_info->sftp = 'N';

		if(!$ftp_info->ftp_user || !$ftp_info->ftp_password) return new Object(-1,'msg_safe_mode_ftp_needed');

		if($ftp_info->sftp == 'Y')
		{
			$connection = ssh2_connect('localhost', $ftp_info->ftp_port);
			if(!ssh2_auth_password($connection, $ftp_info->ftp_user, $ftp_info->ftp_password))
			{
				return new Object(-1,'msg_ftp_invalid_auth_info');
			}
		}
		else
		{
			$oFtp = new ftp();
			if(!$oFtp->ftp_connect('127.0.0.1', $ftp_info->ftp_port)) return new Object(-1, sprintf(Context::getLang('msg_ftp_not_connected'), 'localhost'));

			if(!$oFtp->ftp_login($ftp_info->ftp_user, $ftp_info->ftp_password))
			{
				$oFtp->ftp_quit();
				return new Object(-1,'msg_ftp_invalid_auth_info');
			}
			$oFtp->ftp_quit();
		}

		$this->setMessage('msg_ftp_connect_success');
	}

	/**
	 * @brief Result returned after checking the installation environment
	 */
	function checkInstallEnv()
	{
		// Check each item
		$checklist = array();

		// Check PHP version
		$checklist['php_version'] = true;
		if(version_compare(PHP_VERSION, __XE_MIN_PHP_VERSION__, '<'))
		{
			$checklist['php_version'] = false;
		}
		if(version_compare(PHP_VERSION, __XE_RECOMMEND_PHP_VERSION__, '<'))
		{
			Context::set('phpversion_warning', true);
		}

		// Check DB
		if(DB::getEnableList())
		{
			$checklist['db_support'] = true;
		}
		else
		{
			$checklist['db_support'] = false;
		}

		// Check permission
		if(is_writable('./')||is_writable('./files'))
		{
			$checklist['permission'] = true;
		}
		else
		{
			$checklist['permission'] = false;
		}

		// Check session.auto_start
		if(ini_get('session.auto_start') != 1)
		{
			$checklist['session'] = true;
		}
		else
		{
			$checklist['session'] = false;
		}

		// Check curl
		if(function_exists('curl_init'))
		{
			$checklist['curl'] = true;
		}
		else
		{
			$checklist['curl'] = false;
		}

		// Check GD
		if(function_exists('imagecreatefromgif'))
		{
			$checklist['gd'] = true;
		}
		else
		{
			$checklist['gd'] = false;
		}

		// Check iconv or mbstring
		if(function_exists('iconv') || function_exists('mb_convert_encoding'))
		{
			$checklist['iconv'] = true;
		}
		else
		{
			$checklist['iconv'] = false;
		}

		// Check json
		if(function_exists('json_encode'))
		{
			$checklist['json'] = true;
		}
		else
		{
			$checklist['json'] = false;
		}

		// Check mcrypt or openssl
		if(function_exists('mcrypt_encrypt') || function_exists('openssl_encrypt'))
		{
			$checklist['mcrypt'] = true;
		}
		else
		{
			$checklist['mcrypt'] = false;
		}

		// Check xml & simplexml
		if(function_exists('xml_parser_create') && function_exists('simplexml_load_string'))
		{
			$checklist['xml'] = true;
		}
		else
		{
			$checklist['xml'] = false;
		}

		// Enable install if all conditions are met
		$install_enable = true;
		foreach($checklist as $k => $v)
		{
			if (!$v)
			{
				$install_enable = false;
				break;
			}
		}

		// Save the checked result to the Context
		Context::set('checklist', $checklist);
		Context::set('install_enable', $install_enable);
		Context::set('phpversion', PHP_VERSION);

		return $install_enable;
	}

	/**
	 * @brief License agreement
	 */
	function procInstallLicenseAgreement()
	{
		$vars = Context::getRequestVars();

		$license_agreement = ($vars->license_agreement == 'Y') ? true : false;

		if($license_agreement)
		{
			$currentTime = $_SERVER['REQUEST_TIME'];
			FileHandler::writeFile($this->flagLicenseAgreement, $currentTime);
		}
		else
		{
			FileHandler::removeFile($this->flagLicenseAgreement);
			return new Object(-1, 'msg_must_accept_license_agreement');
		}

		if(!in_array(Context::getRequestMethod(),array('XMLRPC','JSON')))
		{
			$returnUrl = Context::get('success_return_url') ? Context::get('success_return_url') : getNotEncodedUrl('', 'act', 'dispInstallCheckEnv');
			$this->setRedirectUrl($returnUrl);
		}
	}

	/**
	 * @brief Create files and subdirectories
	 * Local evironment setting before installation by using DB information
	 */
	function makeDefaultDirectory()
	{
		$directory_list = array(
			'./files/config',
			'./files/cache/queries',
			'./files/cache/js_filter_compiled',
			'./files/cache/template_compiled',
		);

		foreach($directory_list as $dir)
		{
			FileHandler::makeDir($dir);
		}
	}

	/**
	 * @brief Install all the modules
	 *
	 * Create a table by using schema xml file in the shcema directory of each module
	 */
	function installDownloadedModule()
	{
		$oModuleModel = getModel('module');
		// Create a table ny finding schemas/*.xml file in each module
		$module_list = FileHandler::readDir('./modules/', NULL, false, true);
		foreach($module_list as $module_path)
		{
			// Get module name
			$tmp_arr = explode('/',$module_path);
			$module = $tmp_arr[count($tmp_arr)-1];

			$xml_info = $oModuleModel->getModuleInfoXml($module);
			if(!$xml_info) continue;
			$modules[$xml_info->category][] = $module;
		}
		// Install "module" module in advance
		$this->installModule('module','./modules/module');
		$oModule = getClass('module');
		if($oModule->checkUpdate()) $oModule->moduleUpdate();
		// Determine the order of module installation depending on category
		$install_step = array('system','content','member');
		// Install all the remaining modules
		foreach($install_step as $category)
		{
			if(count($modules[$category]))
			{
				foreach($modules[$category] as $module)
				{
					if($module == 'module') continue;
					$this->installModule($module, sprintf('./modules/%s', $module));

					$oModule = getClass($module);
					if(is_object($oModule) && method_exists($oModule, 'checkUpdate'))
					{
						if($oModule->checkUpdate()) $oModule->moduleUpdate();
					}
				}
				unset($modules[$category]);
			}
		}
		// Install all the remaining modules
		if(count($modules))
		{
			foreach($modules as $category => $module_list)
			{
				if(count($module_list))
				{
					foreach($module_list as $module)
					{
						if($module == 'module') continue;
						$this->installModule($module, sprintf('./modules/%s', $module));

						$oModule = getClass($module);
						if($oModule && method_exists($oModule, 'checkUpdate') && method_exists($oModule, 'moduleUpdate'))
						{
							if($oModule->checkUpdate()) $oModule->moduleUpdate();
						}
					}
				}
			}
		}

		return new Object();
	}

	/**
	 * @brief Install an each module
	 */
	function installModule($module, $module_path)
	{
		// create db instance
		$oDB = DB::getInstance();
		// Create a table if the schema xml exists in the "schemas" directory of the module
		$schema_dir = sprintf('%s/schemas/', $module_path);
		$schema_files = FileHandler::readDir($schema_dir, NULL, false, true);

		$file_cnt = count($schema_files);
		for($i=0;$i<$file_cnt;$i++)
		{
			$file = trim($schema_files[$i]);
			if(!$file || substr($file,-4)!='.xml') continue;
			$output = $oDB->createTableByXmlFile($file);
			if($output === false)
				throw new Exception('msg_create_table_failed');
		}
		// Create a table and module instance and then execute install() method
		unset($oModule);
		$oModule = getClass($module);
		if(method_exists($oModule, 'moduleInstall')) $oModule->moduleInstall();
		return new Object();
	}
}
/* End of file install.controller.php */
/* Location: ./modules/install/install.controller.php */
