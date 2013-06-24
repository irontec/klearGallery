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

    public function init()
    {
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

        $this->_mainRouter = $this->getRequest()->getParam("mainRouter");
        $this->_item = $this->_mainRouter->getCurrentItem();
        $this->_mainConfig = $this->_item->getConfig()->getRaw()->config;

        $this->_prepareMappers();
        $this->_setupCache();
    }

    protected function _setupCache()
    {
        $frontendOptions = array(
            'lifetime' => 2592000 // 1 month
        );

        $backendOptions = array(
            'cache_dir' => APPLICATION_PATH . '/cache/preview'
        );

        if (! file_exists($backendOptions['cache_dir']) ) {

            mkdir($backendOptions['cache_dir'], 0777);
        }

        $this->_cache = Zend_Cache::factory(
            'Page', 'File', $frontendOptions, $backendOptions
        );
    }

    public function dialogAction ()
    {
        $data = array(
            'message' =>  "<p>Test</p>",
            'title' => $this->view->translate('Galería multimedia'),
            'buttons' => array(),
            'templateName' => 'mainHelpTmpl',
            'baseUrl' => $this->view->serverUrl() . $this->view->url() . "?file=" . $this->_request->getParam("file"),
        );

        $data += $this->_getCurrentPageData();

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

        $currentPageData = $this->_getCurrentPageData();

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

    protected function _getCurrentPageData()
    {
        $data = array();
        $data['currentPage'] = $this->_getCurrentPage();

        switch ($data['currentPage']) {

            case 'index':

                $data['galleries']  = $this->_getGalleries();
                break;

            case 'gallery':

                $defaultItemsPerPage = $this->_request->getParam("isDialog") === "true" ? 8 : 14;
                $itemsPerPage = $this->_request->getParam("itemsPerPage", $defaultItemsPerPage);
                $currentPage = $this->_request->getParam("page", 1);
                $galleryPk = $this->_request->getParam("galleryPk");

                $data['galleryId'] = $this->_request->getParam("galleryPk");
                $data['publicImgRoute'] = $this->_getPublicImageRoute();
                $data +=  $this->_getPictures($galleryPk, $itemsPerPage, $currentPage);
                break;

            case 'upload':

                //NOTA: asigna variables directamente a la vista
                $this->_handleUpload();
                break;

            case 'removePic':

                $picture = $this->_pictureMapper->find($this->_request->getParam('removePic'));
                $picture->delete();
                $this->view->success = true;
                break;

            case 'updatePic':

                $picture = $this->_pictureMapper->find($this->_request->getParam('updatePic'));

                foreach ($this->_request->getPost() as $field => $value) {

                    $setter = 'set' . ucfirst($field);
                    $picture->$setter($value);
                }

                $resp = $picture->save();

                if ($resp) {

                    $this->view->success = true;
                }
                break;

            case 'picture':

                $pk = $this->_request->getParam("picturePk");
                if ($this->_request->getParam("preview", false)) {

                    $this->_sendPictureToBrowser($pk);
                    return;
                }

                $data['picture']  = $this->_getPicture($pk);
                $data['publicImgRoute'] = $this->_getPublicImageRoute();
                $data['fieldToParentTable'] = $this->_getParentRelationFieldName($pk);
                $data['page'] = $this->_request->getParam("page", 1);

                break;

            case 'picSizeDelete':

                $this->_pictureSizeMapper->find($this->_request->getParam("pk"))->delete();
                $this->view->success = true;
                break;

            case 'picSizeEdit':

                $data["parentPk"] = $this->_request->getParam("galleryPk");
                $data['sizes'] = $this->_getPictureSizes($this->_request->getParam("galleryPk"), $this->_request->getParam("pk"));
                $data['widthField'] = $this->_getPictureWidthFieldName();
                $data['heightField'] = $this->_getPictureHeightFieldName();
                $data['policyField'] = $this->_getPicturePolicyFieldName();
                $data['fieldToParentTable'] = $this->_getParentRelationFieldName();
                break;

            case 'picSizeNew':

                $data["parentPk"] = $this->_request->getParam("galleryPk");
                $data['sizes'] = $this->_getPictureSizes(null, 0);
                $data['widthField'] = $this->_getPictureWidthFieldName();
                $data['heightField'] = $this->_getPictureHeightFieldName();
                $data['policyField'] = $this->_getPicturePolicyFieldName();
                $data['fieldToParentTable'] = $this->_getParentRelationFieldName();
                break;

            case 'picSizeSave':

                 if ($this->_savePictureSize()) {

                     $this->view->success = true;
                 };

            case 'picSizes':

                $data["parentPk"] = $this->_request->getParam("galleryPk");
                $data['sizes'] = $this->_getPictureSizes($this->_request->getParam("galleryPk"));
                $data['widthField'] = $this->_getPictureWidthFieldName();
                $data['heightField'] = $this->_getPictureHeightFieldName();
                $data['policyField'] = $this->_getPicturePolicyFieldName();
                $data['fieldToParentTable'] = $this->_getParentRelationFieldName();
                break;

            default:

                throw new Exception("Unknown page");
        }

        return $data;
    }


    //NOTA: Este método asigna valores directamente a la vista
    /**
     * @return void
     */
    protected function _handleUpload()
    {
        $uploadHandler =  new \Iron_QQUploader_FileUploader;

        $resp = $uploadHandler->handleUpload(
            sys_get_temp_dir(),
            false,
            'klearGallery' . sha1(time() . rand(1000, 10000)),
            ''
        );

        $this->view->code = $resp['basename'];
        foreach ($resp as $key => $val) {

            $this->view->$key = $val;
        }

        if ($resp['success']) {

            $dataModel = $this->_pictureMapper->loadModel(null);
            $parentRelField = ucfirst($this->_getParentRelationFieldName());
            $parentIdSetter = 'set' . $parentRelField;

            $fileObjects = $dataModel->getFileObjects();
            $fileObjectName = current($fileObjects);
            $fileSetter = 'put' . ucfirst($fileObjectName);

            $dataModel->$parentIdSetter($this->_request->getParam("uploadTo"));
            $dataModel->$fileSetter($resp['path'], $resp['basename']);
            $dataModel->save();

            $this->view->picturePk = $dataModel->getPrimarykey();
        }
    }

    protected function _getCurrentPage()
    {
        if ($this->_request->getParam("uploadTo", false)) {

            return "upload";

        } else if ($this->_request->getParam("updatePic", false)) {

            return "updatePic";

        } else if ($this->_request->getParam("removePic", false)) {

            return "removePic";

        } else if ($this->_request->getParam("picturePk", false)) {

            return "picture";

        } else if ($this->_request->getParam("galleryPk", false)) {

            if ($action = $this->_request->getParam("picSizes", false)) {

                if ($action == "edit") {

                    return "picSizeEdit";

                } else if ($action == "update") {

                    return "picSizeSave";


                } else if ($action == "new") {

                    return "picSizeNew";

                } else if ($action == "delete") {

                    return "picSizeDelete";
                }

                return "picSizes";

            } else {

                return "gallery";
            }

        } else {

            return "index";
        }
    }

    protected function _savePictureSize()
    {
        $heightFieldName = $this->_getPictureHeightFieldName();
        $widthFieldName = $this->_getPictureWidthFieldName();
        $policyFieldName = $this->_getPicturePolicyFieldName();
        $relationFieldName = $this->_getParentRelationFieldName('picSizes');

        $heightSetter = 'set' . ucfirst($heightFieldName);
        $widthSetter = 'set' . ucfirst($widthFieldName);
        $policySetter = 'set'  . ucfirst($policyFieldName);
        $relationSetter = 'set' . ucfirst($relationFieldName);

        if ($this->_request->getParam("pk", false)) {

            $model = $this->_pictureSizeMapper->find($this->_request->getParam("pk"));

        } else {

            $model = $this->_pictureSizeMapper->loadModel(null);
        }

        $model->$heightSetter($this->_request->getParam($heightFieldName))
                     ->$widthSetter($this->_request->getParam($widthFieldName))
                     ->$policySetter($this->_request->getParam($policyFieldName))
                     ->$relationSetter($this->_request->getParam("galleryPk"));

        return $model->save();
    }

    protected function _getPictureSizes($galleryPk, $sizePk = null)
    {
        $relationField = $this->_getParentRelationFieldName('picSizes');

        if (! is_null($sizePk)) {

            $model = $this->_pictureSizeMapper->find($sizePk);

            if (! $model) {

                $model = $this->_pictureSizeMapper->loadModel(null);
            }

            $sizes = array($model);

        } else {

            $sizes = $this->_pictureSizeMapper->findByField($relationField, $galleryPk);
        }

        $availableSizes = array();

        foreach ($sizes as $size) {

            $heightGetter = 'get' . ucfirst($this->_getPictureHeightFieldName());
            $widthGetter = 'get' . ucfirst($this->_getPictureWidthFieldName());
            $policyGetter = 'get'  . ucfirst($this->_getPicturePolicyFieldName());

            $availableSizes[] = array(
                'pk' => $size->getPrimaryKey(),
                'height' => array(
                    'fieldName' => $this->_getPictureHeightFieldName(),
                    'value' => $size->$heightGetter(),
                ),
                'width' => array(
                    'fieldName' => $this->_getPictureWidthFieldName(),
                    'value' => $size->$widthGetter(),
                ),
                'policy' => array(
                    'fieldName' => $this->_getPicturePolicyFieldName(),
                    'value' => $size->$policyGetter()
                )
            );
        }

        return $availableSizes;
    }

    /**
     * @return string
     */
    protected function _getPictureHeightFieldName()
    {
        return $this->_mainConfig->pictureSizes->heightFieldName;
    }

    /**
     * @return string
     */
    protected function _getPictureWidthFieldName()
    {
        return $this->_mainConfig->pictureSizes->widthFieldName;
    }


    /**
     * @return string
     */
    protected function _getPicturePolicyFieldName()
    {
        return $this->_mainConfig->pictureSizes->policyFieldName;
    }

    protected function _getGalleries()
    {
        $galleryData = array();
        $galleries = $this->_galleryMapper->fetchAll();

        $galleryPicturesRelFieldName = $this->_getParentRelationFieldName();

        foreach ($galleries as $gallery) {

            $galleryNameGetter = 'get' . ucfirst($this->_mainConfig->galleries->titleFieldName);

            $galleryData[] = array(
                'pk' => $gallery->getPrimaryKey(),
                'name'   => $gallery->$galleryNameGetter(),
                'recordCount' => $this->_pictureMapper->countByQuery($galleryPicturesRelFieldName . ' = ' . $gallery->getPrimaryKey())
            );
        }

        return $galleryData;
    }

    /**
     * @return null | string
     */
    protected function _getPublicImageRoute()
    {
        if (isset($this->_mainConfig->publicPictureRoute)) {

            $uri = implode(
                "/",
                array(
                    $this->_mainConfig->publicPictureRoute->module,
                    $this->_mainConfig->publicPictureRoute->controller,
                    $this->_mainConfig->publicPictureRoute->action,
                )
            );

            return $this->view->serverUrl() . $this->view->baseUrl() . '/' . $uri;
        }

        return null;
    }

    /**
     * @param integer $galleryPk
     */
    protected function _getPictures($galleryPk, $itemsPerPage, $currentPage)
    {
        $pictureModel = $this->_pictureMapper->loadModel(null);
        $relationField = $this->_getParentRelationFieldName();

        $where = array($relationField. ' = ?', array($galleryPk));

        $pictureNum = $this->_pictureMapper->countByQuery($where);

        $pictures = $this->_pictureMapper->fetchList(
            $where,
            $pictureModel->getPrimaryKeyName() . ' desc',
            $itemsPerPage,
            ($itemsPerPage * ($currentPage-1))
        );

        $picturesData = array();

        foreach ($pictures as $picture) {

            $pictureNameGetter = 'get' . ucfirst($this->_mainConfig->galleryPictures->titleFieldName);

            $picturesData[] = array(
                'pk' => $picture->getPrimaryKey(),
                'name'   => $picture->$pictureNameGetter()
            );
        }

        $paginator = new Zend_Paginator(new Zend_Paginator_Adapter_Null($pictureNum));

        $paginator->setCurrentPageNumber($currentPage);
        $paginator->setItemCountPerPage($itemsPerPage);
        $paginatorResults = (array) $paginator->getPages();

        return array(
            'pictures' => $picturesData,
            'paginator' => $paginatorResults,
        );
    }

    /**
     * @param integer $galleryPk
     */
    protected function _getPicture($picturePk)
    {
        $pictureData = $this->_pictureMapper->find($picturePk);
        return $this->_getPictureMetadata($pictureData);
    }

    protected function _getPictureMetadata($pictureData)
    {
        $pictureFSOs = $pictureData->getFileObjects();
        $pictureFSO = array_shift($pictureFSOs);

        $picture = $pictureData->{'fetch' . ucfirst($pictureFSO)}();
        $pictureRoute = $picture->getFilePath();

        $image = new Iron_Images($pictureRoute);

        $pictureSpecsGetter = 'get'. ucfirst($pictureFSO) .'Specs';

        $pictureSpects = $pictureData->{$pictureSpecsGetter}();

        $fileNameGetter = 'get' . ucfirst($pictureSpects['baseNameName']);
        $fileMimeGetter = 'get' . ucfirst($pictureSpects['mimeName']);
        $fileSizeGetter = 'get' . ucfirst($pictureSpects['sizeName']);

        $parentField = $this->_getParentRelationFieldName();
        $parentIdGetter = 'get' . ucfirst($parentField);

        $titleFieldName = $this->_mainConfig->galleryPictures->titleFieldName;
        $titleGetter = 'get' . ucfirst($titleFieldName);

        return array(
            $parentField => $pictureData->$parentIdGetter(),
            'pk' => $pictureData->getPrimaryKey(),
            'title' => $pictureData->$titleGetter(),
            'titleFieldName' => $titleFieldName,
            'filename' => $pictureData->$fileNameGetter(),
            'mimetype' => str_replace("; charset=binary", "", $pictureData->$fileMimeGetter()),
            'size' => round($pictureData->$fileSizeGetter()/1024, 1),
            'dimensions' => array(
                'width' => $image->getWidth(),
                'height' => $image->getHeight(),
            ),
            'availableDimensions' => $this->_getPictureSizes($pictureData->$parentIdGetter())
        );
    }

    protected function _sendPictureToBrowser($picturePk)
    {
        $maxSize = $this->_request->getParam("size", false);

        if (! $maxSize) {

            Throw new exception("Size param is required");
        }

        $picture = $this->_pictureMapper->find($picturePk);
        $fileObjects = $picture->getFileObjects();
        $fileObject = current($fileObjects);

        $fileObjectGetter = 'fetch' . ucfirst($fileObject);
        $image = $picture->$fileObjectGetter();

        $options = array(
            'filename' => $image->getBaseName(),
            'Content-type' => $image->getMimeType(),
            'Content-Disposition' => 'inline',
        );

        if ($this->_resizeRequired($image->getFilePath(), $maxSize)) {

            if ($binary = $this->_cache->load($image->getMd5Sum() . '_' . $maxSize)) {

                $this->_helper->sendFileToClient($binary, $options, true);

            } else if ($image->getBinary() != "") {

                $ironImageLib = new Iron_Images($image->getFilePath());

                if ($maxSize > 300) {

                    $ironImageLib->thumbnailImage($maxSize, $maxSize);

                } else {

                    $ironImageLib->cropThumbnailImage($maxSize, $maxSize);
                }

                $binary = $ironImageLib->getRaw();

                $this->_cache->save($binary, $image->getMd5Sum() . '_' . $maxSize);
                $this->_helper->sendFileToClient($binary, $options, true);

            } else {

                $this->_imageNotFound();
            }

        } else if ($binary = $image->getBinary() != "") {

            $this->_helper->sendFileToClient($image->getBinary(), $options, true);

        } else {

            $this->_imageNotFound();
        }
    }

    protected function _imageNotFound()
    {
        //TODO: Image not found icon or something
    }

    /**
     * @return bool
     */
    protected function _resizeRequired ($imagePath, $maxSize = null)
    {
        if (!$maxSize) {

            $maxSize = $this->_request->getParam("size");
        }

        $image = new Iron_Images($imagePath);

        if ($image->getHeight() > $maxSize) {

            return true;
        }

        if ($image->getWidth() > $maxSize) {

            return true;
        }

        return false;
    }

    protected function _getParentRelationFieldName($seccion = null)
    {
        if (is_null($seccion)) {

            $seccion = $this->_getCurrentPage();
        }

        if ($seccion == 'picSizes') {

            $model = $this->_pictureSizeMapper->loadModel(null);

        } else {

            $model = $this->_pictureMapper->loadModel(null);
        }

        $parentTableNameSegments = explode('\\', get_class($this->_galleryMapper));
        $parentTableName = end($parentTableNameSegments);

        $tableNameSegments = explode('\\', get_class($this->_pictureMapper));
        $tableName = end($tableNameSegments);

        $propertyName = null;

        foreach ($model->getParentList() as $properties) {

            if ($properties['table_name'] == $parentTableName) {

                $propertyName = $properties['property'];
            }
        }

        if (is_null($propertyName)) {

            Throw new Exception("Could not determine related fields between " . $parentTableName . " and " . $tableName);
        }

        $idColumnName = $model->getColumnForParentTable($parentTableName, $propertyName);

        if (is_null($idColumnName)) {

            Throw new Exception("Could not determine related fields between " . $parentTableName . " and " . $tableName);
        }

        return $idColumnName;
    }

    protected function _prepareMappers()
    {
        if (
            !isset($this->_mainConfig->galleries)
            || !isset($this->_mainConfig->galleries->mapper)
            || !isset($this->_mainConfig->galleries->titleFieldName)
            || !class_exists($this->_mainConfig->galleries->mapper)
        ) {
            $this->_incompleteConfigException("galleries");
        }

        $this->_galleryMapper = new $this->_mainConfig->galleries->mapper;

        if (
            !isset($this->_mainConfig->galleryPictures)
            || !isset($this->_mainConfig->galleryPictures->mapper)
            || !isset($this->_mainConfig->galleryPictures->titleFieldName)
            || !class_exists($this->_mainConfig->galleryPictures->mapper)
        ) {
            $this->_incompleteConfigException("pictures");
        }

        $this->_pictureMapper = new $this->_mainConfig->galleryPictures->mapper;

        if (
            !isset($this->_mainConfig->pictureSizes)
            || !isset($this->_mainConfig->pictureSizes->mapper)
            || !class_exists($this->_mainConfig->pictureSizes->mapper)
            || !isset($this->_mainConfig->pictureSizes->widthFieldName)
            || !isset($this->_mainConfig->pictureSizes->heightFieldName)
        ) {
            $this->_incompleteConfigException("sizes");
        }

        $this->_galleryMapper = new $this->_mainConfig->galleries->mapper;
        $this->_pictureMapper = new $this->_mainConfig->galleryPictures->mapper;
        $this->_pictureSizeMapper = new $this->_mainConfig->pictureSizes->mapper;
    }

    protected function _incompleteConfigException ($section)
    {
        Throw new Exception("Missing ,incorrect or incomplete gallery configuration for $section section");
    }
}