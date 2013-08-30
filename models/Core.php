<?php
class KlearGallery_Model_Core
{
    protected $_request;
    protected $_view;
    protected $_defaultHeaders;


    protected $_auth;
    protected $_loggedIn = false;

    protected $_basePath;
    protected $_mainConfig;

    protected $_galleryMapper;
    protected $_pictureMapper;
    protected $_pictureSizeMapper;

    protected $_cache;
    protected $_ironSlug;

    public function __construct(Zend_Controller_Request_Abstract $request)
    {
        $this->_request = $request;
        $this->_view = Zend_Controller_Front::getInstance()->getParam('bootstrap')
                                                           ->getResource('view');

        $this->_ironSlug = new Iron_Filter_Slug();
        $this->_init();
    }

    protected function _init()
    {
        $this->_loadConfig();
        $this->_prepareMappers();
        $this->_setupCache();
        $this->_setDefaultHeaders();
    }

    protected function _setDefaultHeaders()
    {
        $this->_defaultHeaders = array(
            'Pragma' => 'public',
            'Cache-control' => 'maxage=' . 10, // ~1 minute (e-tag + Last-Modified header are still working!
            'Expires' => gmdate('D, d M Y H:i:s', (time() + 10)) . ' GMT'
        );
    }

    protected function _loadConfig()
    {
        $this->_mainConfig = $this->_getConfig();
    }

    protected function _getConfig()
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

    protected function _setupCache()
    {
        $frontendOptions = array(
            'lifetime' => 2592000 // 1 month
        );

        $backendOptions = array(
            'cache_dir' => APPLICATION_PATH . '/cache/klearGallery'
        );

        if (! file_exists($backendOptions['cache_dir']) ) {

            mkdir($backendOptions['cache_dir'], 0777);
        }

        $this->_cache = Zend_Cache::factory(
            'Core',
            new Iron_Cache_Backend_File($backendOptions),
            array(
                'lifetime' => 86400, //24h
                'automatic_cleaning_factor' => 0,
                'automatic_serialization' => false,
                'write_control' => false
            )
         );
    }

    public function getCurrentPageData()
    {
        $data = array();
        $data['currentPage'] = $this->_getCurrentPage();

        switch ($data['currentPage']) {

            case 'index':

                $data['galleries']  = $this->_getGalleries();
                break;

            case 'gallery':

                $data += $this->_getGallery();
                break;

            case 'upload':

                //NOTA: asigna variables directamente a la vista
                $this->_handleUpload();
                break;

            case 'removePic':

                $picture = $this->_pictureMapper->find($this->_request->getParam('removePic'));
                $picture->delete();
                $this->_view->success = true;
                break;

            case 'updatePic':

                $this->_updatePicture();
                break;

            case 'picture':

                $picData = $this->_getPicture();

                if ($picData) {

                    $data += $picData;
                }
                break;

            case 'picSizeDelete':

                $this->_pictureSizeMapper->find($this->_request->getParam("pk"))->delete();
                $this->_view->success = true;
                break;

            case 'picSizeEdit':

                $data += $this->_getPictureSizeEditScreenData();
                break;

            case 'picSizeNew':

                $data += $this->_getPictureSizeNewScreenData();
                break;

            case 'picSizeSave':

                 if ($this->_savePictureSize()) {

                     $this->_view->success = true;
                 };

            case 'picSizes':

                $data += $this->_getPictureAvailableSizeConfigurations();
                break;

            default:

                throw new Exception("Unknown page");
        }

        return $data;
    }

    protected function _getCurrentPage()
    {
        if ($this->_request->getParam("uploadTo")) {

            return "upload";

        } else if ($this->_request->getParam("updatePic")) {

            return "updatePic";

        } else if ($this->_request->getParam("removePic")) {

            return "removePic";

        } else if ($this->_request->getParam("picturePk")) {

            return "picture";

        } else if ($this->_request->getParam("galleryPk")) {

            if ($action = $this->_request->getParam("picSizes")) {

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

        $this->_view->code = $resp['basename'];
        foreach ($resp as $key => $val) {

            $this->_view->$key = $val;
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

            $this->_view->picturePk = $dataModel->getPrimarykey();
        }
    }

    protected function _savePictureSize()
    {
        $heightFieldName = $this->_getPictureHeightFieldName();
        $widthFieldName = $this->_getPictureWidthFieldName();
        $policyFieldName = $this->_getPictureResizePolicyFieldName();
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
            $policyGetter = 'get'  . ucfirst($this->_getPictureResizePolicyFieldName());

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
                    'fieldName' => $this->_getPictureResizePolicyFieldName(),
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
        return $this->_mainConfig['pictureSizes']['heightFieldName'];
    }

    /**
     * @return string
     */
    protected function _getPictureWidthFieldName()
    {
        return $this->_mainConfig['pictureSizes']['widthFieldName'];
    }


    /**
     * @return string
     */
    protected function _getPictureResizePolicyFieldName()
    {
        return $this->_mainConfig['pictureSizes']['policyFieldName'];
    }

    protected function _getGalleries()
    {
        $galleryData = array();
        $galleries = $this->_galleryMapper->fetchAll();

        $galleryPicturesRelFieldName = $this->_getParentRelationFieldName();

        foreach ($galleries as $gallery) {

            $galleryNameGetter = 'get' . ucfirst($this->_mainConfig['galleries']['titleFieldName']);

            $galleryData[] = array(
                'pk' => $gallery->getPrimaryKey(),
                'name'   => $gallery->$galleryNameGetter(),
                'recordCount' => $this->_pictureMapper->countByQuery($galleryPicturesRelFieldName . ' = ' . $gallery->getPrimaryKey())
            );
        }

        return $galleryData;
    }

    protected function _getGallery()
    {
        $data = array();

        $defaultItemsPerPage = $this->_request->getParam("isDialog") === "true" ? 8 : 14;
        $itemsPerPage = $this->_request->getParam("itemsPerPage", $defaultItemsPerPage);
        $currentPage = $this->_request->getParam("page", 1);
        $galleryPk = $this->_request->getParam("galleryPk");

        $data['galleryId'] = $this->_request->getParam("galleryPk");
        $data +=  $this->_getPictures($galleryPk, $itemsPerPage, $currentPage);
        $data['publicImgRoute'] = $this->_getPublicImageRoute($data);

        return $data;
    }

    /**
     * @var integer $pk
     * @var boolean $preview
     */
    protected function _getPicture()
    {
        $data = array();

        $pk = $this->_request->getParam("picturePk");
        $preview = $this->_request->getParam("preview", false);

        if ($preview) {

            $this->_sendPictureToBrowser($pk);
            return;
        }

        $data['picture']  = $this->_getPictureData($pk);
        $data['publicImgRoute'] = $this->_getPublicImageRoute($data['picture']);
        $data['fieldToParentTable'] = $this->_getParentRelationFieldName($pk);
        $data['page'] = $this->_request->getParam("page", 1);

        return $data;
    }

    public function sendPicture($pk)
    {
        $this->_sendPictureToBrowser($pk);
    }

    protected function _updatePicture()
    {
        $picture = $this->_pictureMapper->find($this->_request->getParam('updatePic'));

        foreach ($this->_request->getPost() as $field => $value) {

            $setter = 'set' . ucfirst($field);
            $picture->$setter($value);
        }

        $resp = $picture->save();

        if ($resp) {

            $this->_view->success = true;
        }
    }

    protected function _getPictureSizeEditScreenData()
    {
        $data = array();
        $data["parentPk"] = $this->_request->getParam("galleryPk");
        $data['sizes'] = $this->_getPictureSizes($this->_request->getParam("galleryPk"), $this->_request->getParam("pk"));
        $data['widthField'] = $this->_getPictureWidthFieldName();
        $data['heightField'] = $this->_getPictureHeightFieldName();
        $data['policyField'] = $this->_getPictureResizePolicyFieldName();
        $data['fieldToParentTable'] = $this->_getParentRelationFieldName();

        return $data;
    }

    protected function _getPictureSizeNewScreenData()
    {
        $data = array();
        $data["parentPk"] = $this->_request->getParam("galleryPk");
        $data['sizes'] = $this->_getPictureSizes(null, 0);
        $data['widthField'] = $this->_getPictureWidthFieldName();
        $data['heightField'] = $this->_getPictureHeightFieldName();
        $data['policyField'] = $this->_getPictureResizePolicyFieldName();
        $data['fieldToParentTable'] = $this->_getParentRelationFieldName();

        return $data;
    }

    protected function _getPictureAvailableSizeConfigurations()
    {
        $data = array();

        $data["parentPk"] = $this->_request->getParam("galleryPk");
        $data['sizes'] = $this->_getPictureSizes($this->_request->getParam("galleryPk"));
        $data['widthField'] = $this->_getPictureWidthFieldName();
        $data['heightField'] = $this->_getPictureHeightFieldName();
        $data['policyField'] = $this->_getPictureResizePolicyFieldName();
        $data['fieldToParentTable'] = $this->_getParentRelationFieldName();

        return $data;
    }

    /**
     * @return null | string
     */
    protected function _getPublicImageRoute($imageData)
    {
        if (! isset($this->_mainConfig['publicPictureRoute'])) {

            throw new Exception('Missing publicPictureRoute configuration');
        }

        $values = array();
        $valueMap = $this->_mainConfig['publicPictureRoute']['map'];
        ksort($valueMap);

        foreach ($valueMap as $pos => $varName) {


            $values[$varName] = array_key_exists($varName, $imageData) ? $imageData[$varName] : "#$varName#";
        }

        array_unshift($values, $this->_mainConfig['publicPictureRoute']['reverse']);
        return $this->_view->baseUrl() . '/' . call_user_func_array('sprintf', $values);
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

            $pictureNameGetter = 'get' . ucfirst($this->_mainConfig['galleryPictures']['titleFieldName']);

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
    protected function _getPictureData($picturePk)
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

        $titleFieldName = $this->_mainConfig['galleryPictures']['titleFieldName'];
        $titleGetter = 'get' . ucfirst($titleFieldName);

        $slug = $this->_ironSlug->filter(pathinfo($pictureData->$fileNameGetter(), PATHINFO_FILENAME));

        return array(
            $parentField => $pictureData->$parentIdGetter(),
            'pk' => $pictureData->getPrimaryKey(),
            'title' => $pictureData->$titleGetter(),
            'titleFieldName' => $titleFieldName,
            'fileName' => $slug,
            'extension' => pathinfo($pictureData->$fileNameGetter(), PATHINFO_EXTENSION),
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
        $maxSizeId = $this->_request->getParam("sizeId", false);

        //Resize rules
        $resizeRule = null;
        $widthGetter = 'get' . ucfirst($this->_getPictureWidthFieldName());
        $heightGetter = 'get' . ucfirst($this->_getPictureHeightFieldName());
        $resizeRuleGetter = 'get' . ucfirst($this->_getPictureResizePolicyFieldName());

        if (!$maxSize && intval($maxSizeId) !== 0) {

            $resizeRule = $this->_pictureSizeMapper->find($maxSizeId);

            if (!$resizeRule) {
                $this->_imageNotFound();
                return;
            }
        }

        $picture = $this->_pictureMapper->find($picturePk);
        if (!$picture) {
            $this->_imageNotFound();
            return;
        }

        $fileObjects = $picture->getFileObjects();
        $fileObject = current($fileObjects);

        $fileObjectGetter = 'fetch' . ucfirst($fileObject);
        $image = $picture->$fileObjectGetter();

        if (!$this->_secureArgumentMatch($image)) {

            return;
        }

        $options = array(
            'filename' => $image->getBaseName(),
            'Content-type' => $image->getMimeType(),
            'Content-Disposition' => 'inline',
        );

        $options += $this->_defaultHeaders;

        //ETag control
        if ($image->getMd5Sum()) {

            if ($maxSize) {
                $eTag = md5($image->getMd5Sum() . $maxSize);
            } else if ($resizeRule) {
                $eTag = md5($image->getMd5Sum() . $resizeRule->$widthGetter() . 'x' . $resizeRule->$heightGetter() . '-' . $rule);
            } else {
                $eTag = $image->getMd5Sum();
            }

            $options['ETag'] = $eTag;
            if ($this->_hashMatches($eTag)) {

                return $this->_imageHasNotChanged();
            }
        }

        $binary = $image->getBinary();

        if ($maxSize) {

            $binary = $this->_resizeImage($image->getFilePath(), $maxSize, $maxSize);

        } else if ($resizeRule) {


            $width = $resizeRule->$widthGetter();
            $height = $resizeRule->$heightGetter();
            $rule = $resizeRule->$resizeRuleGetter();

            $binary = $this->_resizeImage($image->getFilePath(), $width, $height, $rule);
        }

        $fileSender = new Iron_Controller_Action_Helper_SendFileToClient();
        $fileSender->direct($binary, $options, true);
    }

    protected function _secureArgumentMatch(KlearMatrix_Model_Fso $image)
    {
        if ($this->_request->get("mainRouter") instanceof KlearMatrix_Model_RouteDispatcher) {

            //Estamos en klear, no es necesario comprobar nada
            return true;
        }

        $spectedFileName = $this->_ironSlug->filter(pathinfo($image->getBaseName(), PATHINFO_FILENAME));
        $spectedFileExtension = $this->_ironSlug->filter(pathinfo($image->getBaseName(), PATHINFO_EXTENSION));

        if (
            $this->_request->getParam('fileName') !== $spectedFileName
            || $this->_request->getParam('extension') !== $spectedFileExtension
        ) {

            $this->_imageNotFound();
            return false;
        }

        return true;
    }

    protected function _hashMatches($hash)
    {
        $matchHash = $this->_request->getHeader('If-None-Match');

        if ($matchHash == $hash) {
            return true;
        }
        return false;
    }

    protected function _imageHasNotChanged()
    {
        $response = Zend_Controller_Front::getInstance()->getResponse();

        foreach ($this->_defaultHeaders as $key => $value) {
            if (!isset($headers[$key])) {
                $response->setHeader($key, $value, true);
            }
        }

        $response->sendHeaders();
        $response->setHttpResponseCode(304);
    }

    protected function _imageNotFound()
    {
        Zend_Controller_Front::getInstance()->getResponse()->setHttpResponseCode(404);
    }

    protected function _resizeImage($imagePath, $width, $height, $rule = null)
    {
        //TODO check actualización
        $resizedImageCacheKey = md5_file($imagePath) . $width . $height . $rule;

        if (($rawImagePath = $this->_retrieveReizedImageCache($resizedImageCacheKey)) === false) {

            $ironImageLib = new Iron_Images($imagePath);

            switch ($rule) {

                case 'exact':

                    $ironImageLib->resize($width, $height, 'exact');
                    break;

                case 'exactWidth':

                    $ironImageLib->resize($width, $height, 'landscape');
                    break;

                case 'exactHeight':

                    $ironImageLib->resize($width, $height, 'portrait');
                    break;

                case 'thumbnail':

                    $ironImageLib->thumbnailImage($width, $height);
                    break;

                case 'crop':
                default:

                    $ironImageLib->cropThumbnailImage($width, $height);
                    break;
            }

            $rawImageContent = $ironImageLib->getRaw();
            $this->_storeResizedImageFromCache($resizedImageCacheKey, $rawImageContent);

            return $rawImageContent;
        }

        return file_get_contents($rawImagePath);
    }


    /**
     * @return binary string | boolean false
     */
    protected function _retrieveReizedImageCache($resizedImageCacheKey)
    {
        return $this->_cache->getBackend()->getCacheFilePath($resizedImageCacheKey);
    }

    /**
     * @param boolean
     */
    protected function _storeResizedImageFromCache($resizedImageCacheKey, $binary)
    {
        $this->_cache->save($binary, $resizedImageCacheKey);
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
            !isset($this->_mainConfig['galleries'])
            || !isset($this->_mainConfig['galleries']['mapper'])
            || !isset($this->_mainConfig['galleries']['titleFieldName'])
            || !class_exists($this->_mainConfig['galleries']['mapper'])
        ) {
            $this->_incompleteConfigException("galleries");
        }

        if (
            !isset($this->_mainConfig['galleryPictures'])
            || !isset($this->_mainConfig['galleryPictures']['mapper'])
            || !isset($this->_mainConfig['galleryPictures']['titleFieldName'])
            || !class_exists($this->_mainConfig['galleryPictures']['mapper'])
        ) {
            $this->_incompleteConfigException("pictures");
        }

        if (
            !isset($this->_mainConfig['pictureSizes'])
            || !isset($this->_mainConfig['pictureSizes']['mapper'])
            || !class_exists($this->_mainConfig['pictureSizes']['mapper'])
            || !isset($this->_mainConfig['pictureSizes']['widthFieldName'])
            || !isset($this->_mainConfig['pictureSizes']['heightFieldName'])
        ) {
            $this->_incompleteConfigException("sizes");
        }

        $this->_galleryMapper = new $this->_mainConfig['galleries']['mapper'];
        $this->_pictureMapper = new $this->_mainConfig['galleryPictures']['mapper'];
        $this->_pictureSizeMapper = new $this->_mainConfig['pictureSizes']['mapper'];
    }

    protected function _incompleteConfigException ($section)
    {
        Throw new Exception("Missing ,incorrect or incomplete gallery configuration for $section section");
    }
}