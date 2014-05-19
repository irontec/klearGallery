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


    protected $_language;

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

        $currentKlearLanguage = Zend_Registry::get('currentSystemLanguage');
        $this->_language = $currentKlearLanguage->getIden();
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
        $data = array(
            'currentPage' => $this->_request->getParam("section", "index"),
            'currentAction' => $this->_request->getQuery("action"),
        );

        switch ($data['currentPage']) {

            case 'index':

                $resp = $this->_getGalleries();
                if (is_array($resp)) {
                    $data['galleries'] = $resp;
                    $data['galleryStructure'] = $this->_getGalleryStructure();
                }

                break;

            case 'gallery':

                $resp = $this->_getGallery();
                if (is_array($resp)) {
                    $data += $resp;
                    $data['galleryStructure'] = $this->_getGalleryStructure();
                }
                break;

            case 'picture':

                $resp = $this->_getPicturePageData();
                if (is_array($resp)) {
                    $data += $resp;
                }

                break;

            case 'sizes':

                $resp = $this->_getSizePageData();
                if (is_array($resp)) {
                    $data += $resp;
                }
                break;

            default:

                throw new Zend_Controller_Action_Exception('Page not found', 404);
        }

        return $data;
    }


    protected function _getPicturePageData ()
    {
        switch($this->_request->getQuery("action")) {

            case 'upload':

                //NOTA: asigna variables directamente a la vista
                $this->_handleUpload();
                break;

            case 'update':

                return $this->_updatePicture();
                break;

            case 'remove':

                $picture = $this->_pictureMapper->find($this->_request->getParam('pk'));
                $picture->delete();
                $this->_view->success = true;
                break;

            case 'load':
                return $this->_getPicture();
                break;

        }

        return array();
    }


    protected function _getSizePageData ()
    {
        switch($this->_request->getQuery("action")) {

            case 'delete':

                $this->_pictureSizeMapper->find($this->_request->getParam("pk"))->delete();
                $this->_view->success = true;
                break;

            case 'edit':

                return $this->_getPictureSizeEditScreenData();
                break;

            case 'new':

                return $this->_getPictureSizeNewScreenData();
                break;

            case 'save':
                 if ($this->_savePictureSize()) {
                     $this->_view->success = true;
                 };
                break;

            case 'load':

                return $this->_getPictureAvailableSizeConfigurations();
                break;

        }

        return array();
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
                     ->$relationSetter($this->_request->getParam("parentPk"));

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
            $recordCountCondition = $galleryPicturesRelFieldName . ' = ' . intval($gallery->getPrimaryKey());

            $galleryData[] = array(
                'pk' => $gallery->getPrimaryKey(),
                'name'   => $gallery->$galleryNameGetter(),
                'recordCount' => $this->_pictureMapper->countByQuery($recordCountCondition)
            );
        }

        return $galleryData;
    }

    protected function _getGalleryStructure()
    {
        $galleryModel = $this->_galleryMapper->loadModel(null);
        $titleField = $this->_mainConfig['galleries']['titleFieldName'];
        $availableLangs = $galleryModel->getAvailableLangs();
        $mlFields = $galleryModel->getMultiLangColumnsList();

        $isMultilang = false;
        if (in_array(ucfirst($titleField), $mlFields)) {
            $isMultilang = true;
        }

        $data = array(
            'pkName' => $galleryModel->getPrimaryKeyName(),
            'field' => $titleField,
            'isMultilang' => $isMultilang,
            'availableLangs' => $availableLangs
        );

        return $data;
    }

    protected function _getGallery()
    {

        $data = array();
        switch ($this->_request->getQuery("action")) {

            case 'remove':

                $this->_view->success = false;
                $galleryModel = $this->_galleryMapper->find($this->_request->getParam("pk"));

                if ($galleryModel) {

                    $this->_view->success = (boolean) $galleryModel->delete();
                }
                break;

            case 'edit':

                $galleryModel = $this->_galleryMapper->find($this->_request->getParam("pk"));
                if ($galleryModel) {
                    $data['galleryData'] = $galleryModel->toArray();
                }

                break;

            case 'save':

                $this->_getGallerySaveAction();
                break;

            default:

                $defaultItemsPerPage = $this->_request->getParam("isDialog") === "true" ? 8 : 14;
                $itemsPerPage = $this->_request->getParam("itemsPerPage", $defaultItemsPerPage);
                $currentPage = $this->_request->getParam("page", 1);
                $galleryPk = $this->_request->getParam("pk");

                $data['galleryId'] = $this->_request->getParam("pk");
                $data +=  $this->_getPictures($galleryPk, $itemsPerPage, $currentPage);

                $url = $this->_getPublicImageRoute($data);
                $data['publicImgBase'] = $this->_view->baseUrl("/");
                $data['publicImgUri'] = $url;
                break;
        }

        return $data;
    }


    protected function _getGallerySaveAction()
    {
        $params = $this->_request->getParams();
        unset($params['mainRouter']);

        $galleryStructure = $this->_getGalleryStructure();

        if ($this->_request->getParam("pk")) {

            $newModel = $this->_galleryMapper->find($this->_request->getParam("pk"));
            if (! $newModel) {
                throw new Zend_Controller_Action_Exception("Element not found", 500);
            }
        } else {
            $newModel = $this->_galleryMapper->loadModel(null);
        }

        if ($galleryStructure['isMultilang']) {

            foreach ($galleryStructure['availableLangs'] as $language) {
                $expectedPostVarName = $galleryStructure['field'] . $language;
                if ($this->_request->getParam($expectedPostVarName)) {
                    $setter = 'set' . ucfirst($galleryStructure['field']);
                    $newModel->$setter($this->_request->getParam($expectedPostVarName), $language);
                } else {
                    $setter = 'set' . ucfirst($galleryStructure['field']);
                    $newModel->$setter($this->_request->getParam($galleryStructure['field']), $language);
                }
            }
        }

        $this->_view->success =  $newModel->save();
    }

    /**
     * @var integer $pk
     * @var boolean $preview
     */
    protected function _getPicture()
    {
        $data = array();

        $pk = $this->_request->getParam("pk");
        $preview = $this->_request->getParam("preview", false);

        if ($preview) {
            $this->_sendPictureToBrowser($pk);
            return;
        }

        $data['picture']  = $this->_getPictureData($pk);
        $data['fieldToParentTable'] = $this->_getParentRelationFieldName($pk);
        $data['page'] = $this->_request->getParam("page", 1);

        $url = $this->_getPublicImageRoute($data['picture']);
        $data['publicImgBase'] = $this->_view->baseUrl("/");
        $data['publicImgUri'] = $url;
        $data['language'] = $this->_language;

        return $data;
    }

    public function sendPicture($pk)
    {
        $this->_sendPictureToBrowser($pk);
    }

    protected function _updatePicture()
    {
        $picture = $this->_pictureMapper->find($this->_request->getParam('pk'));

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
        $data["parentPk"] = $this->_request->getParam("parentPk");
        $data['sizes']    = $this->_getPictureSizes(
            $this->_request->getParam("galleryPk"),
            $this->_request->getParam("pk")
        );
        $data['widthField'] = $this->_getPictureWidthFieldName();
        $data['heightField'] = $this->_getPictureHeightFieldName();
        $data['policyField'] = $this->_getPictureResizePolicyFieldName();
        $data['fieldToParentTable'] = $this->_getParentRelationFieldName();

        return $data;
    }

    protected function _getPictureSizeNewScreenData()
    {
        $data = array();
        $data["parentPk"] = $this->_request->getParam("parentPk");
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

        $data["parentPk"] = $this->_request->getParam("pk");
        $data['sizes'] = $this->_getPictureSizes($this->_request->getParam("pk"));
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

        foreach ($valueMap as $varName) {
            $values[$varName] = array_key_exists($varName, $imageData) ? $imageData[$varName] : "#$varName#";
        }

        array_unshift($values, $this->_mainConfig['publicPictureRoute']['reverse']);
        return call_user_func_array('sprintf', $values);
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
        $image = $this->_fetchImageFso($picturePk);

        if (is_null($image)) {
            $this->_imageNotFound();
            return;
        }

        if (!$this->_secureArgumentMatch($image)) {
            $this->_imageNotFound();
            return;
        }

        $width = $height = $rule = null;
        try {
            $dimensions = $this->_getDesiredImageSizes();
            if ($dimensions) {
                list($width, $height, $rule) = $dimensions;
            }
        } catch (Exception $exception) {
            $this->_imageNotFound();
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

            if (is_null($rule)) {
                $eTag = md5($image->getMd5Sum() . $width . 'x' . $height . '-' . $rule);
            } else if ($width) {
                $eTag = md5($image->getMd5Sum() . $width);
            } else {
                $eTag = $image->getMd5Sum();
            }

            $options['ETag'] = $eTag;
            if ($this->_hashMatches($eTag)) {

                return $this->_imageHasNotChanged();
            }
        }

        $binary = $this->_getProperImageBinary($image, $width, $height, $rule);

        $fileSender = new Iron_Controller_Action_Helper_SendFileToClient();
        $fileSender->direct($binary, $options, true);
    }

    protected function _getProperImageBinary(Iron_Model_Fso $image, $width, $height, $rule)
    {
        if ($rule) {
            return $this->_resizeImage($image->getFilePath(), $width, $height, $rule);
        } else if ($width) {
            return $this->_resizeImage($image->getFilePath(), $width, $height);
        } else {
            return $image->getBinary();
        }
    }

    /**
     * @return array (width, height, rule) or null for original image size
     * @throws exception if no match
     *
     */
    protected function _getDesiredImageSizes()
    {
        $maxSize = $this->_request->getParam("size");
        if ($maxSize) {
            return array($maxSize, $maxSize, 0);
        }

        //sizeId == 0 ==> tamaño original
        $maxSizeId = intval($this->_request->getParam("sizeId"));
        if ($maxSizeId === 0) {
            return null;
        }

        //Resize rules
        $resizeRule = null;
        $widthGetter = 'get' . ucfirst($this->_getPictureWidthFieldName());
        $heightGetter = 'get' . ucfirst($this->_getPictureHeightFieldName());
        $resizeRuleGetter = 'get' . ucfirst($this->_getPictureResizePolicyFieldName());

        if ($maxSizeId) {
            $resizeRule = $this->_pictureSizeMapper->find($maxSizeId);
            if ($resizeRule) {
                return array(
                    $resizeRule->$widthGetter(),
                    $resizeRule->$heightGetter(),
                    $resizeRule->$resizeRuleGetter()
                );
            }
        }

        throw new Exception("Unkown expected image dimensions");
    }

    protected function _fetchImageFso ($picturePk)
    {
        $picture = $this->_pictureMapper->find($picturePk);
        if (!$picture) {
            return null;
        }

        $fileObjects = $picture->getFileObjects();
        $fileObject = current($fileObjects);

        $fileObjectGetter = 'fetch' . ucfirst($fileObject);
        return $picture->$fileObjectGetter();
    }

    protected function _secureArgumentMatch(Iron_Model_Fso $image)
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
            $response->setHeader($key, $value, true);
        }

        $response->sendHeaders();
        $response->setHttpResponseCode(304);
    }

    protected function _imageNotFound()
    {
        throw new Zend_Controller_Action_Exception('Image not found', 404);
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

            $exceptionMsg = "Could not determine related fields between " . $parentTableName . " and " . $tableName;
            Throw new Exception($exceptionMsg);
        }

        $idColumnName = $model->getColumnForParentTable($parentTableName, $propertyName);

        if (is_null($idColumnName)) {

            $exceptionMsg = "Could not determine related fields between " . $parentTableName . " and " . $tableName;
            Throw new Exception($exceptionMsg);
        }

        return $idColumnName;
    }

    protected function _prepareMappers()
    {
        $this->_checkProperGalleryConfig();
        $this->_checkProperPictureConfig();
        $this->_checkProperSizeConfig();

        $this->_galleryMapper = new $this->_mainConfig['galleries']['mapper'];
        $this->_pictureMapper = new $this->_mainConfig['galleryPictures']['mapper'];
        $this->_pictureSizeMapper = new $this->_mainConfig['pictureSizes']['mapper'];
    }

    protected function _checkProperGalleryConfig()
    {
        if (
            !isset($this->_mainConfig['galleries'])
            || !isset($this->_mainConfig['galleries']['mapper'])
            || !isset($this->_mainConfig['galleries']['titleFieldName'])
            || !class_exists($this->_mainConfig['galleries']['mapper'])
        ) {
            $this->_incompleteConfigException("galleries");
        }
    }

    protected function _checkProperPictureConfig()
    {
        if (
            !isset($this->_mainConfig['galleryPictures'])
            || !isset($this->_mainConfig['galleryPictures']['mapper'])
            || !isset($this->_mainConfig['galleryPictures']['titleFieldName'])
            || !class_exists($this->_mainConfig['galleryPictures']['mapper'])
        ) {
            $this->_incompleteConfigException("pictures");
        }
    }

    protected function _checkProperSizeConfig()
    {
        if (
            !isset($this->_mainConfig['pictureSizes'])
            || !isset($this->_mainConfig['pictureSizes']['mapper'])
            || !class_exists($this->_mainConfig['pictureSizes']['mapper'])
            || !isset($this->_mainConfig['pictureSizes']['widthFieldName'])
            || !isset($this->_mainConfig['pictureSizes']['heightFieldName'])
        ) {
            $this->_incompleteConfigException("sizes");
        }
    }

    protected function _incompleteConfigException ($section)
    {
        Throw new Exception("Missing ,incorrect or incomplete gallery configuration for $section section");
    }
}