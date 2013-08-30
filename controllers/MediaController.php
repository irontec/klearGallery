<?php
use Cianoplan\Model as Model;
use Cianoplan\Mapper\Sql as Mapper;

class KlearGallery_MediaController extends Zend_Controller_Action
{
    protected $_session;
    protected $_core;

    public function init()
    {
        /* Initialize action controller here */
        $this->_helper->layout()->disableLayout();
        $this->_helper->viewRenderer->setNoRender(true);

        $this->_core = new KlearGallery_Model_Core($this->getRequest());
    }

    public function imageAction ()
    {
        $picturePk = $this->_request->getParam("pk");
        $this->_core->sendPicture($picturePk);
        return;
    }

    /**
     * Retina displays & company
     * @param string $dimensions. Example: 250x150
     * @return array
     */
    protected function _getDimensions($dimensions)
    {
        $ratio = isset($_COOKIE['devicePixelRatio']) ? $_COOKIE['devicePixelRatio'] : 1;
        list($width, $height) = explode("x", $dimensions);

        return array($width * $ratio, $height * $ratio);
    }

}