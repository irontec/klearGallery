<?php
class KlearGallery_IndexController extends Zend_Controller_Action
{
    protected $_auth;
    protected $_loggedIn = false;

    protected $_basePath;

    protected $_mainRouter;
    protected $_item;

    protected $_mainConfig;

    protected $_galleryMapper;
    protected $_pictureMapper;
    protected $_pictureSizeMapper;

    protected $_cache;
    protected $_screen;

    public function init()
    {
        $this->_screen = new KlearGallery_Model_Core($this->getRequest());

        /* Initialize action controller here */
        $this->_helper->ContextSwitch()
              ->addActionContext('index', 'json')
              ->addActionContext('dialog', 'json')
              ->initContext('json');

        if (!$this->_mainRouter = $this->getRequest()->getParam("mainRouter")
            ||!is_object($this->_mainRouter)
        ) {
            throw New Zend_Exception('Acceso restringido', Zend_Controller_Plugin_ErrorHandler::EXCEPTION_NO_ACTION);
        }
    }


    public function dialogAction ()
    {
        $data = array(
            'message' =>  "<p>Loading</p>",
            'title' => $this->view->translate('Galería multimedia'),
            'buttons' => array(),
            'templateName' => 'mainHelpTmpl',
            'baseUrl' => $this->view->serverUrl() . $this->view->url() . "?file=" . $this->_request->getParam("file"),
        );

        $data += $this->_screen->getCurrentPageData();

        $jsonResponse = new Klear_Model_DispatchResponse();
        $jsonResponse->setModule('default');
        $jsonResponse->setPlugin('gallery');
        $jsonResponse->addTemplate("/../klearGallery/template", "mainHelpTmpl");
        $jsonResponse->addJsFile('/../klearMatrix/js/plugins/jquery.klearmatrix.genericdialog.js');
        $jsonResponse->addJsFile('/../klearGallery/js/klear.gallery.js');
        $jsonResponse->addJsFile("/../klearMatrix/js/plugins/qq-fileuploader.js");
        $jsonResponse->addCssFile("/../klearGallery/css/gallery.css");
        $jsonResponse->setData($data);
        $jsonResponse->attachView($this->view);
    }

    public function indexAction()
    {
        $data = array(
           'templateName' => 'mainHelpTmpl',
           'baseUrl' => $this->view->serverUrl() . $this->view->url() . "?file=" . $this->_request->getParam("file"),
        );

        $currentPageData = $this->_screen->getCurrentPageData();

        if (is_array($currentPageData)) {

            $data += $currentPageData;
        }

        $jsonResponse = new Klear_Model_DispatchResponse();
        $jsonResponse->setModule('default');
        $jsonResponse->addTemplate("/../klearGallery/template", "mainHelpTmpl");
        $jsonResponse->setPlugin('gallery');
        $jsonResponse->addJsFile("/../klearGallery/js/klear.gallery.js");
        $jsonResponse->addJsFile("/../klearMatrix/js/plugins/qq-fileuploader.js");
        $jsonResponse->addCssFile("/../klearGallery/css/gallery.css");

        $jsonResponse->setData($data);
        $jsonResponse->attachView($this->view);
    }
}