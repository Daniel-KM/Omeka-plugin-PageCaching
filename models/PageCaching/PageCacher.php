<?php

class PageCaching_PageCacher
{
    const PAGE_CACHING_OPTIONS_NAME = 'page_caching_page_cacher';
    
    protected $_cache;
    protected $_errorExceptions = array();
    
    protected $_options = array(
        'enable_debugging'                              => false,
        'cache_lifetime'                                => 7200,
        'automatically_clear_cache_after_record_change' => true,
        'admin_blacklist'                               => array(),  // blacklist specified by the admin
        'plugins_blacklist'                             => array(),  // blacklist specified by other plugins
        'cache_dir_path'                                => PAGE_CACHING_CACHE_DIR_PATH,
        'backend_name'                                  => 'file'
    );
    
    public function __construct($loadOptionsFromDb = true)
    {   
        if ($loadOptionsFromDb) {
            $this->loadOptions();
        }
        
        // clear the plugins blacklist if the cache directory is empty
        if ($this->cacheDirectoryIsEmpty()) {            
            $this->_options['plugins_blacklist'] = array();
            $this->saveOptions();
        }
    }
        
    public function getCache()
    {
        return $this->_cache;
    }
    
    public function deleteOptions() 
    {
        delete_option(self::PAGE_CACHING_OPTIONS_NAME);
    }
    
    public function loadOptions()
    {
        $options = (array)unserialize(get_option(self::PAGE_CACHING_OPTIONS_NAME));
        $this->setOptions($options);
    }
    
    public function setOptions($options)
    {
        foreach ($options as $key => $value) {
            $this->setOption($key, $value);
        }
    }
    
    public function setOption($key, $value)
    {   
        if ($key == 'admin_blacklist_text') {
            $this->setAdminBlacklistByText($value);
        } else if ($key == 'cache_dir_path') {
            $this->setCacheDirectoryPath($value);
        } else {
            $this->_options[$key] = $value;
        }
    }
    
    public function getOptions()
    {
        return $this->_options;
    }
    
    public function getOption($key)
    {
        return $this->_options[$key];
    }
        
    public function saveOptions()
    {
        set_option(self::PAGE_CACHING_OPTIONS_NAME, serialize($this->_options));
    }
    
    /**
     *   Initialize, store, and return the cache frontend object
     *   @return Zend_Cache_Frontend|null
     */
    public function initializeCache()
    {
        if ($this->cacheDirectoryExists()) {
            if ($this->cacheDirectoryIsReadableAndWritable()) {                        
                // Get the frontend and backend for the cache
                list($frontendName, $frontendOptions) = $this->_getCacheFrontendConfiguration();
                list($backendName, $backendOptions) = $this->_getCacheBackendConfiguration();
                try {
                    // Create a Zend_Cache_Frontend object
                    $this->_cache = Zend_Cache::factory($frontendName,
                                                        $backendName,
                                                        $frontendOptions,
                                                        $backendOptions);
                    
                } catch (Zend_Cache_Exception $e) {
                    $this->_errorExceptions[] = $e; 
                    $this->_cache = null;
                }
            } else {
                // return a null object if the cache dir is not readable and writable
                $this->_errorExceptions[] = new Exception('The following cache directory must be readable and writeable: ' . $this->getCacheDirectoryPath());
                $this->_cache = null;
            }
        } else {
            // return a null object if the cache dir does not exist
            $this->_errorExceptions[] = new Exception('The following cache directory must exist: ' . $this->getCacheDirectoryPath());
            $this->_cache = null;
        }
        
        return $this->_cache;
    }
    
    public function getErrorExceptions() 
    {
        return $this->_errorExceptions;
    }
    
    public function cleanCache($mode = Zend_Cache::CLEANING_MODE_ALL, $tags = array()) 
    {
        if ($this->_cache) {
            // Enable the cache temporarily to clean it.
            $caching = $this->_cache->getOption('caching');
            $this->_cache->setOption('caching', true);
            $this->_cache->clean($mode, $tags);
            $this->_cache->setOption('caching', $caching);
            
            // clear the plugins blacklist if the entire cache is cleared
            if ($mode == Zend_Cache::CLEANING_MODE_ALL) {            
                $this->_options['plugins_blacklist'] = array();
                $this->saveOptions();
            }
        }
    }
    
    public function memcacheFailureCallback($hostname, $port) 
    {
        throw Exception('Memcache Failure on host:' . $hostname . ' on port ' . $port);
    }
     
    protected function _getCacheBackendConfiguration()
    {
        // get the backend settings from the cache_settings.ini file        
        $backendSettings = new Zend_Config_Ini(PAGE_CACHING_PLUGIN_DIR . 'cache_settings.ini', 'backend');
        
        // get the backend name
        $backendName = $this->_options['backend_name'];

        if (!$backendName) {
            $backendName = 'file';
        }
        
        // configure the backend based on the backend name        
        switch(strtolower($backendName)) {
            case 'memcached':
                $memCacheServers = array();
                foreach($backendSettings->memcached->servers as $serverSettings) {
                    $memCacheServer = array('host' => $serverSettings->host, 
                                            'port' => (int)$serverSettings->port, 
                                            'persistent' => (boolean)$serverSettings->persistent, 
                                            'weight' => (int)$serverSettings->weight, 
                                            'timeout' =>  (int)$serverSettings->timeout, 
                                            'retry_interval' =>  (int)$serverSettings->retry_interval, 
                                            'status' => (boolean)$serverSettings->status, 
                                            'failure_callback' => array($this, 'memcacheFailureCallback'));
                    $memCacheServers[] = $memCacheServer;
                }                                                                
                $backendOptions = array('servers' => $memCacheServers,
                                        'compression' => (boolean)$backendSettings->compression,
                                        'compatibility' => (boolean)$backendSettings->compatibility);
                                        
            break;
            
            case 'apc':
                $backendOptions = array();
            break;
            
            case 'xcache':
                $backendOptions = array('user' => $backendSettings->xcache->user,
                                        'password' => $backendSettings->xcache->password);
            break;
            
            case 'file':
            default:
                $backendOptions = array(
                    'cache_dir' => $this->getCacheDirectoryPath(),
                    'file_locking' => $backendSettings->file->file_locking,
                    'read_control' => $backendSettings->file->read_control,
                    'read_control_type' => $backendSettings->file->read_control_type,
                    'hashed_directory_level' => $backendSettings->file->hashed_directory_level,
                    'file_name_prefix' => $backendSettings->file->file_name_prefix
                );
            break;
        }
        
 
        return array($backendName,$backendOptions);
    }
        
    protected function _getCacheFrontendConfiguration()
    {
        // Only enable page caching for the public theme
        $isEnabled = (boolean)(!is_admin_theme());
                
        $isInDebugMode = (boolean)$this->_options['enable_debugging'];                              
        $cacheLifetime = (integer)$this->_options['cache_lifetime'];
        
        $isLoggedIn = (boolean)current_user();
                
        $defaultOptions = array(
            // Need to cache with GET vars b/c of searches (could be conditional?)
            'cache_with_get_variables' => true,
            
            // Never cache with POST b/c forms will not operate properly.
            'cache_with_post_variables' => false,
            
            // Always cache when there is session and cookie variables (because there is always a cookie and there may be session variables)
            'cache_with_session_variables' => true,
            'cache_with_cookie_variables' => true,
            
            // What? Why would we cache with a file upload?
            'cache_with_files_variables' => false,
            
            // Always make the cache ID with whatever variables are available, except the cookie variables (because there is always a cookie)
            // (until proven otherwise).
            'make_id_with_get_variables' => true,
            'make_id_with_post_variables' => true,
            'make_id_with_files_variables' => true,
            'make_id_with_session_variables' => true,
            'make_id_with_cookie_variables' => false,

            // Caching is enabled by default
            'cache' => false,
        );
        
        // default whitelist regular expressions
        $whitelist = $this->_getWhitelist();
        
        // merge the blacklist specified by the admin and those specified by other plugins        
        $blacklist = $this->_getBlacklist();

        // create a list of regular expressions which defaults to the whitelist, 
        // but overrides the whitelist with the blacklist
        $regexps = $whitelist;
        foreach($blacklist as $regexp => $options) {
            $regexps[$regexp] = $options;
        }
        
        $frontendName = 'Page';
        $frontendOptions = array(
           'lifetime' => $cacheLifetime,
           'caching' => $isEnabled,
           'debug_header' => $isInDebugMode, 
           'default_options' => $defaultOptions,
           'regexps' => $regexps,
           'memorize_headers' => array('Content-Type')
        );
        
        return array($frontendName,$frontendOptions);
    }
    
    protected function _getWhiteList()
    {
        $whitelist = array();
        
        // cache home page
        $whitelist['/$'] = array('cache'=>true);
        
        // cache item pages
        $whitelist['/items$'] = array('cache'=>true);
        $whitelist['/items/show'] = array('cache'=>true);
        $whitelist['/items/browse'] = array('cache'=>true);
        $whitelist['/items/tags'] = array('cache'=>true);
        $whitelist['/items/index'] = array('cache'=>true);
        
        // cache collection pages 
        $whitelist['/collections$'] = array('cache'=>true);
        $whitelist['/collections/show/'] = array('cache'=>true);
        $whitelist['/collections/browse/'] = array('cache'=>true);
        
        $whitelist = apply_filters('page_caching_whitelist', $whitelist);
        
        return $whitelist;
    }
    
    protected function _getBlacklist()
    {        
        return array_merge($this->_options['admin_blacklist'], $this->_options['plugins_blacklist']);
    }
    
    /**
    *   Builds a configuration form for the plugin.
    * 
    * 
    * Available options for the admin form:
    * 
    *  'expire_cache' => Expire the entire cache (useful when adding/removing themes and plugins).
    *  
    *  'is_enabled_for_public' => whether or not to turn caching on for the public site.
    * 
    *  'enable_debugging' => Turn on the debugging header (useful to see which pages
    * are being cached).
    * 
    * Advanced options:
    * 
    *  'public_regexps[]' => Set of regexp rules to follow, corresponds to the options
    * given for Zend_Cache_Frontend_Page.  Only applies if you have caching enabled
    * for the public theme.
    * 
    */
    public function buildConfigForm()
    {
        $adminBlacklistText = $this->getAdminBlacklistByText();

        $form = new Zend_Form;
        $form->setDecorators(array('FormElements'));

        $elementDecorators = array(
            'ViewHelper', 
            'Errors', 
            array('HtmlTag', array('tag' => 'div', 'class'=>'field')),
            'Label',        
            array('HtmlTag', array('tag' => 'div', 'class'=>'inputs')),
            array('Description', array('tag' => 'p', 'class' => 'explanation'))
        );

        $form->addElements(array(
            array(
                'type' => 'checkbox',
                'name' => 'automatically_clear_cache_after_record_change',
                'options' => array(
                    'decorators' => $elementDecorators,
                    'value' => $this->_options['automatically_clear_cache_after_record_change'],
                    'label' => 'Automatically Clear Page Cache After Record Change',
                    'description' => 'If enabled, the plugin will automatically clear the page cache whenever a record is added, updated, or deleted. Note: This feature may significantly lower caching performance for sites with high levels of dynamic content.'
                )
            ),
            array(
                'type' => 'text',
                'name' => 'cache_lifetime',
                'options' => array(
                    'decorators' => $elementDecorators,
                    'value' => $this->_options['cache_lifetime'],
                    'label' => 'Cache Lifetime',
                    'description' => 'The amount of time (in seconds) to keep the cache before automatically expiring it.'
                )
            ),
            array(
                'type' => 'select',
                'name' => 'backend_name',
                'options' => array(
                    'decorators' => $elementDecorators,
                    'value' => $this->_options['backend_name'],
                    'label' => 'Cache Backend Type',
                    'description' => 'The cache backend type.',
                    'multiOptions' => array('apc'=>'APC',  'file'=>'Filesystem', 'memcached'=>'Memcached', 'xcache'=>'XCache')
                )
            ),
            array(
                'type' => 'text',
                'name' => 'cache_dir_path',
                'options' => array(
                    'decorators' => $elementDecorators,
                    'value' => $this->_options['cache_dir_path'],
                    'label' => 'Cache Directory Path',
                    'description' => 'The path to the cache directory on the filesystem. This directory must be readable and writeable.  This setting only applies if you use the Filesystem cache backend type.'
                )
            ),
            array(
                'type' => 'textarea',
                'name' => 'admin_blacklist_text',
                'options' => array(
                    'decorators' => $elementDecorators,
                    'value' => $this->getAdminBlacklistByText(),
                    'label' => 'Cache Blacklist',
                    'description' => 'On each line, specify a regular expression for any relative URIs you do NOT want cached.  For example, if you want to blacklist all the items pages, you would put "/items" on a line by itself.'
                )
            ),
            array(
                'type' => 'checkbox',
                'name' => 'enable_debugging',
                'options' => array(
                    'decorators' => $elementDecorators,
                    'value' => $this->_options['enable_debugging'],
                    'label' => 'Enable Debugging',
                    'description' => 'If enabled, a DEBUG message will appear on each page that is being cached.  This is useful when tweaking the plugin settings to make sure it behaves correctly.'
                )
            )
        ));    
    
        $this->_form = $form;
        return $form;        
    }
    
    public function setAdminBlacklistByText($text) 
    {
        $this->_options['admin_blacklist'] = array();
        $lines = explode("\n", $text);
        foreach($lines as $line) {
            if ($line != '') {
                $line = '/' . ltrim(trim($line), '/');
                $this->_options['admin_blacklist'][$line] = array('cache'=>false);
            }
        }
    }
    
    public function getAdminBlacklistByText()
    {
        return implode("\n", array_keys($this->_options['admin_blacklist']));
    }
    
    /**
     * Let the plugins have an opportunity to create a blacklist for the record.
     * This function should only be called if a record is inserted, updated, or deleted
     *
     * @param $blacklistToAdd array An associative array urls to blacklist, 
     * where the key is a regular expression of relative urls to blacklist 
     * and the value is an array of Zend_Cache front end settings
     * Example:
     * $regularExpressionsToOptions = array(
     *  '/whatever/' => array('cache'=>false),
     *  '/whatever2/' => array('cache'=>false)
     * )
     * @param $record Omeka_Record
     * @param $action string The last action done to the record, either 'insert', 'update', or 'delete'
     * @return void
    */
    public function blacklistForRecord($record, $action)
    {
        if ($record) {
            $action = strtolower($action);
            $actions = array('insert', 'update', 'delete');
            if (in_array($action, $actions)) {                
        
                // Get the any new urls to blacklist from the plugins
                $pluginsBlacklistToAdd = array();
                $pluginsBlacklistToAdd = apply_filters('page_caching_blacklist_for_record', $pluginsBlacklistToAdd, $record, $action);
                
                // If the plugins added new urls to plugins blacklist,
                // then override the old ones and set the new plugins blacklist
                if ($pluginsBlacklistToAdd) {
                    $pluginsBlacklist = $this->_options['plugins_blacklist'];                   
                    foreach($pluginsBlacklistToAdd as $regexp => $options) {
                        $pluginsBlacklist[$regexp] = $options;
                    }
                    $this->_options['plugins_blacklist'] = $pluginsBlacklist;                    
                    $this->saveOptions();                    
                }
            }
        }
    }
    
    /**
     * Return the file path to the cache directory 
     * @return string
    */
    public function getCacheDirectoryPath() 
    {
        $cacheDirectoryPath = $this->_options['cache_dir_path'];
        if (trim($cacheDirectoryPath) == '') {
            $cacheDirectoryPath = PAGE_CACHING_CACHE_DIR_PATH;
        }
        return $cacheDirectoryPath;
    }
    
    public function setCacheDirectoryPath($cacheDirectoryPath)
    {
        $cacheDirectoryPath =  trim(trim($cacheDirectoryPath), DIRECTORY_SEPARATOR);
        if (trim($cacheDirectoryPath) == '') {
            $cacheDirectoryPath = PAGE_CACHING_CACHE_DIR_PATH;
        } else {
            $cacheDirectoryPath = DIRECTORY_SEPARATOR . $cacheDirectoryPath . DIRECTORY_SEPARATOR;
        }
        $this->_options['cache_dir_path'] = $cacheDirectoryPath;
    }
    
    /**
     * Return whether the cache directory is empty. 
     * If the cache directory does not exist or is not readable or not writeable, then it returns true.
     * @return boolean
    */
    public function cacheDirectoryIsEmpty()
    {
        if (!$this->cacheDirectoryIsReadableAndWritable()) {
            return true;
        }
        $d = dir($this->getCacheDirectoryPath());
        if ($d) {
            while (FALSE !== ($f = $d->read())) {  
                if ($f != '.' && $f != '..' && $f != '.htaccess') {  
                    return false;
                }
            }
            $d->close();
        }
        return true;
    }
    
    /**
     * Return whether the cache directory is readable and writeable. 
     * If the cache directory does not exist or is not readable or not writeable, then it returns false, else return true.
     * @return boolean
    */
    public function cacheDirectoryIsReadableAndWritable() 
    {
        $cacheDir = $this->getCacheDirectoryPath();
        return (is_readable($cacheDir) && is_writeable($cacheDir));
    }
    
    /**
     * Return whether the cache directory is exists
     * @return boolean
    */
    public function cacheDirectoryExists()
    {
        $cacheDir = $this->getCacheDirectoryPath();
        return (file_exists($cacheDir));
    }
}