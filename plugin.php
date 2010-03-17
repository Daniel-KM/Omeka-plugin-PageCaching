<?php 
/**
 * @version $Id$
 * @copyright Center for History and New Media, 2009
 * @license http://www.gnu.org/licenses/gpl-3.0.txt
 * @package Contribution
 * @copyright Center for History and New Media, 2009
 **/

define('PAGE_CACHING_PLUGIN_DIR', dirname(__FILE__) . DIRECTORY_SEPARATOR);
define('PAGE_CACHING_CACHE_DIR_PATH', PAGE_CACHING_PLUGIN_DIR . 'cache' . DIRECTORY_SEPARATOR);
 
add_plugin_hook('install', 'page_caching_install');
add_plugin_hook('uninstall', 'page_caching_uninstall');

add_plugin_hook('initialize', 'page_caching_initialize');
add_plugin_hook('config_form', 'page_caching_config_form');
add_plugin_hook('config', 'page_caching_config');
add_plugin_hook('admin_append_to_dashboard_secondary', 'page_caching_admin_append_to_dashboard_secondary');

add_plugin_hook('after_insert_record', 'page_caching_after_insert_record');
add_plugin_hook('after_update_record', 'page_caching_after_update_record');
add_plugin_hook('after_delete_record', 'page_caching_after_delete_record');

function page_caching_install()
{
    $pageCacher = page_caching_get_page_cacher(false);    
    $pageCacher->saveOptions();
}

function page_caching_uninstall()
{    
    $pageCacher = page_caching_get_page_cacher();
    $pageCacher->cleanCache();
    $pageCacher->deleteOptions();    
}

function page_caching_initialize()
{   
    Zend_Controller_Front::getInstance()->registerPlugin(new PageCachingControllerPlugin);
}

function page_caching_after_insert_record($record)
{    
    page_caching_after_record_changed($record, 'insert');
}

function page_caching_after_update_record($record)
{    
    page_caching_after_record_changed($record, 'update');
}

function page_caching_after_delete_record($record)
{    
    page_caching_after_record_changed($record, 'delete');
}

function page_caching_after_record_changed($record, $action='insert')
{    
    $pageCacher = page_caching_get_page_cacher();    
    $pageCacher->blacklistForRecord($record, $action);
    if ($pageCacher->getOption('automatically_clear_cache_after_record_change')) {
        $pageCacher->cleanCache();
    }
}

function page_caching_get_page_cacher($loadOptions = true, $forceReload = false)
{
    if (!$loadOptions) {
        $pageCacher = new PageCaching_PageCacher($loadOptions);
    } else {
        $regError = false;
        try {
            $pageCacher = Zend_Registry::get('page_cacher');
        } catch (Exception $e) {
            $regError = true;
        }
        if ($forceReload || $regError) {
            $pageCacher = new PageCaching_PageCacher;       
            $pageCacher->initializeCache();
            Zend_Registry::set('page_cacher', $pageCacher);
        }
    }
    
    return $pageCacher;
}

function page_caching_config_form()
{
    $pageCacher = page_caching_get_page_cacher();
    $form = $pageCacher->buildConfigForm();
    echo $form->render();
}

function page_caching_config()
{
    $pageCacher = page_caching_get_page_cacher();
    $form = $pageCacher->buildConfigForm();

    if (!$form->isValid($_POST)) {
        throw new Exception('Not valid!');
    }
    $pageCacher->setOptions($_POST);
    $pageCacher->saveOptions();
    
    $pageCacher = page_caching_get_page_cacher(true, true);
    if ($pageCache = $pageCacher->getCache()) {
        $pageCacher->cleanCache();
    } else {
        if ($errorExceptions = $pageCacher->getErrorExceptions()) {
           throw $errorExceptions[0];
        }        
    }
}

function page_caching_admin_append_to_dashboard_secondary()
{
?>
    <div id="page-caching" class="info-panel">
        <h2>Page Caching</h2>
        <p><a class="button" href="<?php echo html_escape(uri('page-caching/index/clear-cache')); ?>">Clear Page Cache</a></p>
    </div>
<?php
}

class PageCachingControllerPlugin extends Zend_Controller_Plugin_Abstract
{    
    public function routeStartup(Zend_Controller_Request_Abstract $request)
    {
       $pageCacher = page_caching_get_page_cacher();       
       $pageCache = $pageCacher->getCache();
       if ($pageCache) {
           $pageCacher->cleanCache(Zend_Cache::CLEANING_MODE_OLD);
           $pageCache->start();
       }
    }
}