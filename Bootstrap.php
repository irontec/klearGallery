<?php

class KlearGallery_Bootstrap extends Zend_Application_Module_Bootstrap
{
    protected function _initRoutes()
    {
        $frontController = Zend_Controller_Front::getInstance();
        $router = $frontController->getRouter();

        $config = $this->_getGalleryConfiguration();

        if (array_key_exists('publicPictureRoute', $config)) {

            $route = new Zend_Controller_Router_Route_Regex(
                        $config['publicPictureRoute']['route'],
                        $config['publicPictureRoute']['defaults'],
                        $config['publicPictureRoute']['map'],
                        $config['publicPictureRoute']['reverse']
                     );

            $router->addRoute('klearGallery', $route);
        }
    }

    protected function _initGallery()
    {
        $front = Zend_Controller_Front::getInstance();
        $front->registerPlugin(new KlearGallery_Plugin_Init());
    }

    protected function _getGalleryConfiguration()
    {
        $config =  new Zend_Config_Yaml(
            $this->_getConfigPath(),
            APPLICATION_ENV,
            array(
                "yamldecoder" => "yaml_parse"
            )
        );

        return $config->toArray();
    }

    /**
     * Devuelve la ruta al fichero de configuración
     */
    protected function _getConfigPath()
    {
        return APPLICATION_PATH . '/configs/klear/klearGallery.yaml';
    }

}
