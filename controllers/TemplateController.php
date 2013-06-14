<?php
class KlearGallery_TemplateController extends Zend_Controller_Action
{
    public function init()
    {
        /* Initialize action controller here */
        $this->_helper->layout->disableLayout();
        $this->_helper->viewRenderer->setNoRender(true);
    }

    public function indexAction()
    {
        $this->getRequest()->setParam("namespace", "klear-gallery");
        echo $response = $this->view->render('template/index.phtml');
    }
}