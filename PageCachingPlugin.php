<?php
/**
 * Page Caching
 *
 * @version $Id$
 * @copyright Center for History and New Media, 2009
 * @license http://www.gnu.org/licenses/gpl-3.0.txt
 * @package PageCaching
 */

define('PAGE_CACHING_PLUGIN_DIR', dirname(__FILE__) . DIRECTORY_SEPARATOR);
define('PAGE_CACHING_CACHE_DIR_PATH', PAGE_CACHING_PLUGIN_DIR . 'cache' . DIRECTORY_SEPARATOR);

/**
 * The Page Caching plugin.
 * @package Omeka\Plugins\PageCaching
 */
 class PageCachingPlugin extends Omeka_Plugin_AbstractPlugin
{
    /**
     * @var array Hooks for the plugin.
     */
    protected $_hooks = array(
        'initialize',
        'install',
        'uninstall',
        'config_form',
        'config',
        'after_save_record',
        'after_delete_record',
    );

    /**
     * @var array Filters for the plugin.
     */
    protected $_filters = array(
        'admin_dashboard_panels',
    );

    /**
     * @var array Options and their default values.
     */
    protected $_options = array(
    );

    /**
     * Initialize this plugin.
     */
    public function hookInitialize()
    {
        Zend_Controller_Front::getInstance()->registerPlugin(new PageCachingControllerPlugin);
    }

    /**
     * Install the plugin.
     */
    public function hookInstall()
    {
        $pageCacher = page_caching_get_page_cacher(false);
        $pageCacher->saveOptions();
    }

    /**
     * Uninstall the plugin.
     */
    public function hookUninstall()
    {
        $pageCacher = page_caching_get_page_cacher();
        $pageCacher->cleanCache();
        $pageCacher->deleteOptions();
    }

    /**
     * Shows plugin configuration page.
     */
    public function hookConfigForm($args)
    {
        $view = get_view();
        $pageCacher = page_caching_get_page_cacher();
        $form = $pageCacher->buildConfigForm();
        echo $form->render();
    }

    /**
     * Processes the configuration form.
     */
    public function hookConfig($args)
    {
        $pageCacher = page_caching_get_page_cacher();
        $form = $pageCacher->buildConfigForm();

        if (!$form->isValid($_POST)) {
            throw new Exception('Not valid!');
        }
        $pageCacher->setOptions($_POST);
        $pageCacher->saveOptions();

        $pageCacher = page_caching_get_page_cacher(true, true);
        $pageCache = $pageCacher->getCache();
        if ($pageCache) {
            $pageCacher->cleanCache();
        } else {
            $errorExceptions = $pageCacher->getErrorExceptions();
            if ($errorExceptions) {
                throw $errorExceptions[0];
            }
        }
    }

    /**
     * Hook called after saving a record.
     */
    public function hookAfterSaveRecord($args)
    {
        $record = $args['record'];
        if ($args['insert']) {
            $this->_after_record_changed($record, 'insert');
        }
        else {
            $this->_after_record_changed($record, 'update');
        }
    }

    /**
     * Hook called after deleting a record.
     */
    public function hookAfterDeleteRecord($args)
    {
        $record = $args['record'];
        $this->_after_record_changed($record, 'delete');
    }

    /**
     * Filter used to display a panel on the admin dashboad.
     */
    public function filterAdminDashboardPanels($panels)
    {
         ob_start();
        ?>
        <div id="page-caching" class="info-panel">
            <h2><?php echo __('Page Caching'); ?></h2>
            <p><a class="button" href="<?php echo html_escape(url('page-caching/index/clear-cache')); ?>"><?php echo __('Clear Page Cache'); ?></a></p>
        </div>
        <?php
        $panels[] = ob_get_clean();
        return $panels;
    }

    protected function _after_record_changed($record, $action='insert')
    {
        $pageCacher = page_caching_get_page_cacher();
        $pageCacher->blacklistForRecord($record, $action);
        if ($pageCacher->getOption('automatically_clear_cache_after_record_change')) {
            $pageCacher->cleanCache();
        }
    }
}

function page_caching_get_page_cacher($loadOptions = true, $forceReload = false)
{
    if (!$loadOptions) {
        $pageCacher = new PageCaching_PageCacher($loadOptions);
    }
    else {
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

class PageCachingControllerPlugin extends Zend_Controller_Plugin_Abstract
{
    /**
     * Called before Zend_Controller_Front begins evaluating the
     * request against its routes.
     *
     * @param Zend_Controller_Request_Abstract $request
     * @return void
     */
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
