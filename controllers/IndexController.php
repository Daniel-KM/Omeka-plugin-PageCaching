<?php 
/**
 * @version $Id$
 * @copyright Center for History and New Media, 2009
 * @license http://www.gnu.org/licenses/gpl-3.0.txt
 * @package Contribution
 * @copyright Center for History and New Media, 2009
 **/
 
class PageCaching_IndexController extends Omeka_Controller_Action
{	
    public function clearCacheAction()
    {        
        if ($pageCacher = Zend_Registry::get('page_cacher')) {
            $pageCacher->cleanCache();
        }

        $this->flashSuccess("The page cache has been successfully cleared.");
        
        $this->redirect->gotoRoute( 
            array(
                'module' => 'default',
                'controller' => 'index', 
                'action' => 'index' 
            ) 
        );    
    }
}