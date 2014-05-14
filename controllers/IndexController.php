<?php
/**
 * The index controller for the plugin.
 *
 * @package PageCaching
 */
class PageCaching_IndexController extends Omeka_Controller_AbstractActionController
{
    public function clearCacheAction()
    {
        if ($pageCacher = Zend_Registry::get('page_cacher')) {
            $pageCacher->cleanCache();
        }

        $this->_helper->flashMessenger(__('The page cache has been successfully cleared.'), 'success');

        $this->_helper->redirector->gotoUrl('/');
    }
}
