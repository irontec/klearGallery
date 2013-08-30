<?php
class KlearGallery_Plugin_Init extends Zend_Controller_Plugin_Abstract
{


    /**
     * Este método que se ejecuta una vez se ha matcheado la ruta adecuada
     * (non-PHPdoc)
     * @see Zend_Controller_Plugin_Abstract::routeShutdown()
     */
    public function routeShutdown(Zend_Controller_Request_Abstract $request)
    {

        if (!preg_match("/^klear/", $request->getModuleName())) {
            return;
        }
    }
}
