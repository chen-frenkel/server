<?php

/**
 * Retrieve information and invoke actions on caption Asset
 *
 * @service captionAsset
 * @package plugins.caption
 * @subpackage api.services
 */
class CaptionAssetService extends KalturaBaseService
{
	public function initService($serviceId, $serviceName, $actionName)
	{
		parent::initService($serviceId, $serviceName, $actionName);
		
		parent::applyPartnerFilterForClass(new conversionProfile2Peer());
		parent::applyPartnerFilterForClass(new assetParamsOutputPeer());
		parent::applyPartnerFilterForClass(new assetPeer());
		
		$partnerGroup = null;
		if(
			$actionName == 'get' ||
			$actionName == 'list' ||
			$actionName == 'getByEntryId' ||
			$actionName == 'getDownloadUrl' ||
			$actionName == 'getWebPlayableByEntryId' ||
			$actionName == 'generateByEntryId' ||
			$actionName == 'regenerate'
			)
			$partnerGroup = $this->partnerGroup . ',0';
			
		parent::applyPartnerFilterForClass(new assetParamsPeer(), $partnerGroup);
	}
	
    /**
     * Add caption asset
     *
     * @action add
     * @param string $entryId
     * @param KalturaCaptionAsset $captionAsset
     * @return KalturaCaptionAsset
     * @throws KalturaErrors::ENTRY_ID_NOT_FOUND
     * @throws KalturaCaptionErrors::CAPTION_ASSET_ALREADY_EXISTS
	 * @throws KalturaErrors::UPLOAD_TOKEN_INVALID_STATUS_FOR_ADD_ENTRY
	 * @throws KalturaErrors::UPLOADED_FILE_NOT_FOUND_BY_TOKEN
	 * @throws KalturaErrors::RECORDED_WEBCAM_FILE_NOT_FOUND
	 * @throws KalturaCaptionErrors::CAPTION_ASSET_ID_NOT_FOUND
	 * @throws KalturaErrors::STORAGE_PROFILE_ID_NOT_FOUND
	 * @throws KalturaErrors::RESOURCE_TYPE_NOT_SUPPORTED
     */
    function addAction($entryId, KalturaCaptionAsset $captionAsset)
    {
    	$dbEntry = entryPeer::retrieveByPK($entryId);
    	if(!$dbEntry || $dbEntry->getType() != KalturaEntryType::MEDIA_CLIP || !in_array($dbEntry->getMediaType(), array(KalturaMediaType::VIDEO, KalturaMediaType::AUDIO)))
    		throw new KalturaAPIException(KalturaErrors::ENTRY_ID_NOT_FOUND, $entryId);
    	
    	if($captionAsset->captionParamsId)
    	{
    		$dbCaptionAsset = assetPeer::retrieveByEntryIdAndParams($entryId, $captionAsset->captionParamsId);
    		if($dbCaptionAsset)
    			throw new KalturaAPIException(KalturaCaptionErrors::CAPTION_ASSET_ALREADY_EXISTS, $dbCaptionAsset->getId(), $captionAsset->captionParamsId);
    	}
    	
    	$dbCaptionAsset = new CaptionAsset();
    	$dbCaptionAsset = $captionAsset->toInsertableObject($dbCaptionAsset);
    	
		$dbCaptionAsset->setEntryId($entryId);
		$dbCaptionAsset->setPartnerId($dbEntry->getPartnerId());
		$dbCaptionAsset->setStatus(CaptionAsset::FLAVOR_ASSET_STATUS_QUEUED);
		$dbCaptionAsset->save();

		$captionAsset = new KalturaCaptionAsset();
		$captionAsset->fromObject($dbCaptionAsset);
		return $captionAsset;
    }
    
    /**
     * Update content of caption asset
     *
     * @action setContent
     * @param string $id
     * @param KalturaContentResource $contentResource
     * @return KalturaCaptionAsset
     * @throws KalturaCaptionErrors::CAPTION_ASSET_ID_NOT_FOUND
	 * @throws KalturaErrors::UPLOAD_TOKEN_INVALID_STATUS_FOR_ADD_ENTRY
	 * @throws KalturaErrors::UPLOADED_FILE_NOT_FOUND_BY_TOKEN
	 * @throws KalturaErrors::RECORDED_WEBCAM_FILE_NOT_FOUND
	 * @throws KalturaCaptionErrors::CAPTION_ASSET_ID_NOT_FOUND
	 * @throws KalturaErrors::STORAGE_PROFILE_ID_NOT_FOUND
	 * @throws KalturaErrors::RESOURCE_TYPE_NOT_SUPPORTED 
     */
    function setContentAction($id, KalturaContentResource $contentResource)
    {
   		$dbCaptionAsset = assetPeer::retrieveById($id);
   		if(!$dbCaptionAsset)
   			throw new KalturaAPIException(KalturaCaptionErrors::CAPTION_ASSET_ID_NOT_FOUND, $id);
    	
   		$previousStatus = $dbCaptionAsset->getStatus();
		$contentResource->validateEntry($dbCaptionAsset->getentry());
		$kContentResource = $contentResource->toObject();
    	$this->attachContentResource($dbCaptionAsset, $kContentResource);
		$contentResource->entryHandled($dbCaptionAsset->getentry());
		
    	$newStatuses = array(
    		CaptionAsset::FLAVOR_ASSET_STATUS_READY,
    		CaptionAsset::FLAVOR_ASSET_STATUS_VALIDATING,
    		CaptionAsset::FLAVOR_ASSET_STATUS_TEMP,
    	);
    	
    	if($previousStatus == CaptionAsset::FLAVOR_ASSET_STATUS_QUEUED && in_array($dbCaptionAsset->getStatus(), $newStatuses))
   			kEventsManager::raiseEvent(new kObjectAddedEvent($dbCaptionAsset));
   		
		$captionAsset = new KalturaCaptionAsset();
		$captionAsset->fromObject($dbCaptionAsset);
		return $captionAsset;
    }
	
    /**
     * Update caption asset
     *
     * @action update
     * @param string $id
     * @param KalturaCaptionAsset $captionAsset
     * @param KalturaContentResource $contentResource
     * @return KalturaCaptionAsset
     * @throws KalturaErrors::ENTRY_ID_NOT_FOUND
     * @throws KalturaCaptionErrors::CAPTION_ASSET_ALREADY_EXISTS
	 * @throws KalturaErrors::UPLOAD_TOKEN_INVALID_STATUS_FOR_ADD_ENTRY
	 * @throws KalturaErrors::UPLOADED_FILE_NOT_FOUND_BY_TOKEN
	 * @throws KalturaErrors::RECORDED_WEBCAM_FILE_NOT_FOUND
	 * @throws KalturaCaptionErrors::CAPTION_ASSET_ID_NOT_FOUND
	 * @throws KalturaErrors::STORAGE_PROFILE_ID_NOT_FOUND
	 * @throws KalturaErrors::RESOURCE_TYPE_NOT_SUPPORTED
     */
    function updateAction($id, KalturaCaptionAsset $captionAsset, KalturaContentResource $contentResource = null)
    {
		$dbCaptionAsset = assetPeer::retrieveById($id);
		if(!$dbCaptionAsset)
			throw new KalturaAPIException(KalturaCaptionErrors::CAPTION_ASSET_ID_NOT_FOUND, $id);
    	
    	$dbCaptionAsset = $captionAsset->toUpdatableObject($dbCaptionAsset);
    	
    	if($contentResource)
    	{
			$contentResource->validateEntry($dbCaptionAsset->getentry());
			$kContentResource = $contentResource->toObject();
	    	$this->attachContentResource($dbCaptionAsset, $kContentResource);
    	}
    	else 
    	{
    		$dbCaptionAsset->save();
    	}
		
		if($dbCaptionAsset->getDefault())
			$this->setAsDefaultAction($dbCaptionAsset->getId());
			
		$captionAsset = new KalturaCaptionAsset();
		$captionAsset->fromObject($dbCaptionAsset);
		return $captionAsset;
    }
    
	/**
	 * @param CaptionAsset $captionAsset
	 * @param string $fullPath
	 * @param bool $copyOnly
	 */
	protected function attachFile(CaptionAsset $captionAsset, $fullPath, $copyOnly = false)
	{
		$ext = pathinfo($fullPath, PATHINFO_EXTENSION);
		
		$captionAsset->incrementVersion();
		$captionAsset->setFileExt($ext);
		$captionAsset->setSize(filesize($fullPath));
		$captionAsset->save();
		
		$syncKey = $captionAsset->getSyncKey(CaptionAsset::FILE_SYNC_FLAVOR_ASSET_SUB_TYPE_ASSET);
		
		try {
			kFileSyncUtils::moveFromFile($fullPath, $syncKey, true, $copyOnly);
		}
		catch (Exception $e) {
			
			if($captionAsset->getStatus() == CaptionAsset::FLAVOR_ASSET_STATUS_QUEUED || $captionAsset->getStatus() == CaptionAsset::FLAVOR_ASSET_STATUS_NOT_APPLICABLE)
			{
				$captionAsset->setDescription($e->getMessage());
				$captionAsset->setStatus(CaptionAsset::FLAVOR_ASSET_STATUS_ERROR);
				$captionAsset->save();
			}												
			throw $e;
		}

		$finalPath = kFileSyncUtils::getLocalFilePathForKey($syncKey);
		list($width, $height, $type, $attr) = getimagesize($finalPath);
		
		$captionAsset->setWidth($width);
		$captionAsset->setHeight($height);
		$captionAsset->setSize(filesize($finalPath));
		
		$captionAsset->setStatus(CaptionAsset::FLAVOR_ASSET_STATUS_READY);
		$captionAsset->save();
	}
    
	/**
	 * @param CaptionAsset $captionAsset
	 * @param string $url
	 */
	protected function attachUrl(CaptionAsset $captionAsset, $url)
	{
    	$fullPath = myContentStorage::getFSUploadsPath() . '/' . $captionAsset->getId() . '.jpg';
		if (kFile::downloadUrlToFile($url, $fullPath))
			return $this->attachFile($captionAsset, $fullPath);
			
		if($captionAsset->getStatus() == CaptionAsset::FLAVOR_ASSET_STATUS_QUEUED || $captionAsset->getStatus() == CaptionAsset::FLAVOR_ASSET_STATUS_NOT_APPLICABLE)
		{
			$captionAsset->setDescription("Failed downloading file[$url]");
			$captionAsset->setStatus(CaptionAsset::FLAVOR_ASSET_STATUS_ERROR);
			$captionAsset->save();
		}
		
		throw new KalturaAPIException(KalturaCaptionErrors::CAPTION_ASSET_DOWNLOAD_FAILED, $url);
    }
    
	/**
	 * @param CaptionAsset $captionAsset
	 * @param kUrlResource $contentResource
	 */
	protected function attachUrlResource(CaptionAsset $captionAsset, kUrlResource $contentResource)
	{
    	$this->attachUrl($captionAsset, $contentResource->getUrl());
    }
    
	/**
	 * @param CaptionAsset $captionAsset
	 * @param kLocalFileResource $contentResource
	 */
	protected function attachLocalFileResource(CaptionAsset $captionAsset, kLocalFileResource $contentResource)
	{
		if($contentResource->getIsReady())
			return $this->attachFile($captionAsset, $contentResource->getLocalFilePath(), $contentResource->getKeepOriginalFile());
			
		$captionAsset->setStatus(asset::FLAVOR_ASSET_STATUS_IMPORTING);
		$captionAsset->save();
		
		$contentResource->attachCreatedObject($captionAsset);
    }
    
	/**
	 * @param CaptionAsset $captionAsset
	 * @param FileSyncKey $srcSyncKey
	 */
	protected function attachFileSync(CaptionAsset $captionAsset, FileSyncKey $srcSyncKey)
	{
		$captionAsset->incrementVersion();
		$captionAsset->save();
		
        $newSyncKey = $captionAsset->getSyncKey(CaptionAsset::FILE_SYNC_FLAVOR_ASSET_SUB_TYPE_ASSET);
        kFileSyncUtils::createSyncFileLinkForKey($newSyncKey, $srcSyncKey);
                
		$finalPath = kFileSyncUtils::getLocalFilePathForKey($newSyncKey);
		list($width, $height, $type, $attr) = getimagesize($finalPath);
		
		$captionAsset->setWidth($width);
		$captionAsset->setHeight($height);
		$captionAsset->setSize(filesize($finalPath));
		
		$captionAsset->setStatus(CaptionAsset::FLAVOR_ASSET_STATUS_READY);
		$captionAsset->save();
    }
    
	/**
	 * @param CaptionAsset $captionAsset
	 * @param kFileSyncResource $contentResource
	 */
	protected function attachFileSyncResource(CaptionAsset $captionAsset, kFileSyncResource $contentResource)
	{
    	$syncable = kFileSyncObjectManager::retrieveObject($contentResource->getFileSyncObjectType(), $contentResource->getObjectId());
    	$srcSyncKey = $syncable->getSyncKey($contentResource->getObjectSubType(), $contentResource->getVersion());
    	
        return $this->attachFileSync($captionAsset, $srcSyncKey);
    }
    
	/**
	 * @param CaptionAsset $captionAsset
	 * @param kRemoteStorageResource $contentResource
	 * @throws KalturaErrors::STORAGE_PROFILE_ID_NOT_FOUND
	 */
	protected function attachRemoteStorageResource(CaptionAsset $captionAsset, kRemoteStorageResource $contentResource)
	{
        $storageProfile = StorageProfilePeer::retrieveByPK($contentResource->getStorageProfileId());
        if(!$storageProfile)
        {
        	if($captionAsset->getStatus() == CaptionAsset::FLAVOR_ASSET_STATUS_QUEUED || $captionAsset->getStatus() == CaptionAsset::FLAVOR_ASSET_STATUS_NOT_APPLICABLE)
        	{
				$captionAsset->setDescription("Could not find storage profile id [$contentResource->getStorageProfileId()]");
				$captionAsset->setStatus(CaptionAsset::FLAVOR_ASSET_STATUS_ERROR);
				$captionAsset->save();
        	}
			
        	throw new KalturaAPIException(KalturaErrors::STORAGE_PROFILE_ID_NOT_FOUND, $contentResource->getStorageProfileId());
        }
        $captionAsset->incrementVersion();
		$captionAsset->setStatus(CaptionAsset::FLAVOR_ASSET_STATUS_READY);
        $captionAsset->save();
        	
        $syncKey = $captionAsset->getSyncKey(CaptionAsset::FILE_SYNC_FLAVOR_ASSET_SUB_TYPE_ASSET);
		$fileSync = kFileSyncUtils::createReadyExternalSyncFileForKey($syncKey, $contentResource->getUrl(), $storageProfile);
    }
    
    
	/**
	 * @param CaptionAsset $captionAsset
	 * @param kContentResource $contentResource
	 * @throws KalturaErrors::UPLOAD_TOKEN_INVALID_STATUS_FOR_ADD_ENTRY
	 * @throws KalturaErrors::UPLOADED_FILE_NOT_FOUND_BY_TOKEN
	 * @throws KalturaErrors::RECORDED_WEBCAM_FILE_NOT_FOUND
	 * @throws KalturaCaptionErrors::CAPTION_ASSET_ID_NOT_FOUND
	 * @throws KalturaErrors::STORAGE_PROFILE_ID_NOT_FOUND
	 * @throws KalturaErrors::RESOURCE_TYPE_NOT_SUPPORTED
	 */
	protected function attachContentResource(CaptionAsset $captionAsset, kContentResource $contentResource)
	{
    	switch($contentResource->getType())
    	{
			case 'kUrlResource':
				return $this->attachUrlResource($captionAsset, $contentResource);
				
			case 'kLocalFileResource':
				return $this->attachLocalFileResource($captionAsset, $contentResource);
				
			case 'kFileSyncResource':
				return $this->attachFileSyncResource($captionAsset, $contentResource);
				
			case 'kRemoteStorageResource':
				return $this->attachRemoteStorageResource($captionAsset, $contentResource);
				
			default:
				$msg = "Resource of type [" . get_class($contentResource) . "] is not supported";
				KalturaLog::err($msg);
				
				if($captionAsset->getStatus() == CaptionAsset::FLAVOR_ASSET_STATUS_QUEUED || $captionAsset->getStatus() == CaptionAsset::FLAVOR_ASSET_STATUS_NOT_APPLICABLE)
				{
					$captionAsset->setDescription($msg);
					$captionAsset->setStatus(asset::FLAVOR_ASSET_STATUS_ERROR);
					$captionAsset->save();
				}
				
				throw new KalturaAPIException(KalturaErrors::RESOURCE_TYPE_NOT_SUPPORTED, get_class($contentResource));
    	}
    }
    
    
	/**
	 * Serves caption by entry id and thumnail params id
	 *  
	 * @action serveByEntryId
	 * @param string $entryId
	 * @param int $captionParamId if not set, default caption will be used.
	 * @return file
	 * 
	 * @throws KalturaCaptionErrors::CAPTION_ASSET_PARAMS_ID_NOT_FOUND
	 * @throws KalturaErrors::ENTRY_ID_NOT_FOUND
	 */
	public function serveByEntryIdAction($entryId, $captionParamId = null)
	{
		$entry = entryPeer::retrieveByPK($entryId);
		if (!$entry)
			throw new KalturaAPIException(KalturaErrors::ENTRY_ID_NOT_FOUND, $entryId);

		$fileName = $entry->getId() . '.jpg';
		
		$captionAsset = null;
		if(is_null($captionParamId))
		{
			$captionAssets = assetPeer::retrieveByEntryId($entryId, array(self::getAssetTypeCoreValue(CaptionAssetType::CAPTION)));
			foreach($captionAssets as $checkCaptionAsset)
			{
				if($checkCaptionAsset->getDefault())
				{
					$captionAsset = $checkCaptionAsset;
					break;
				}
			}
		}
		else
		{
			$captionAsset = assetPeer::retrieveByEntryIdAndParams($entryId, $captionParamId);
		}
		
		if(!$captionAsset)
			throw new KalturaAPIException(KalturaCaptionErrors::CAPTION_ASSET_PARAMS_ID_NOT_FOUND, $captionParamId);
		
		return $this->serveFile($captionAsset, CaptionAsset::FILE_SYNC_FLAVOR_ASSET_SUB_TYPE_ASSET, $fileName);
	}
	
	/**
	 * Get download URL for the caption Asset
	 * 
	 * @action getDownloadUrl
	 * @param string $id
	 * @param bool $useCdn
	 * @return string
	 */
	public function getDownloadUrlAction($id, $useCdn = false)
	{
		$captionAssetDb = assetPeer::retrieveById($id);
		if (!$captionAssetDb)
			throw new KalturaAPIException(KalturaCaptionErrors::CAPTION_ASSET_ID_NOT_FOUND, $id);

		if ($captionAssetDb->getStatus() != CaptionAsset::FLAVOR_ASSET_STATUS_READY)
			throw new KalturaAPIEXception(KalturaErrors::FLAVOR_ASSET_IS_NOT_READY);

		return $captionAssetDb->getDownloadUrl($useCdn);
	}

	/**
	 * Serves caption by its id
	 *  
	 * @action serve
	 * @param string $captionAssetId
	 * @return file
	 *  
	 * @throws KalturaCaptionErrors::CAPTION_ASSET_ID_NOT_FOUND
	 */
	public function serveAction($captionAssetId)
	{
		$captionAsset = assetPeer::retrieveById($captionAssetId);
		if (!$captionAsset)
			throw new KalturaAPIException(KalturaCaptionErrors::CAPTION_ASSET_ID_NOT_FOUND, $captionAssetId);

		$ext = $captionAsset->getFileExt();
		if(is_null($ext))
			$ext = 'jpg';
			
		$fileName = $captionAsset->getEntryId()."_" . $captionAsset->getId() . ".$ext";
		
		return $this->serveFile($captionAsset, CaptionAsset::FILE_SYNC_FLAVOR_ASSET_SUB_TYPE_ASSET, $fileName);
	}
	
	/**
	 * Markss the caption as default and removes that mark from all other caption assets of the entry.
	 *  
	 * @action setAsDefault
	 * @param string $captionAssetId
	 * @throws KalturaCaptionErrors::CAPTION_ASSET_ID_NOT_FOUND
	 */
	public function setAsDefaultAction($captionAssetId)
	{
		$captionAsset = assetPeer::retrieveById($captionAssetId);
		if (!$captionAsset)
			throw new KalturaAPIException(KalturaCaptionErrors::CAPTION_ASSET_ID_NOT_FOUND, $captionAssetId);
		
		$entry = $captionAsset->getentry();
						
		$entryKuserId = $entry->getKuserId();
		$thisKuserId = $this->getKuser()->getId();
		$isNotAdmin = !kCurrentContext::$ks_object->isAdmin();
		
		KalturaLog::debug("entryKuserId [$entryKuserId], thisKuserId [$thisKuserId], isNotAdmin [$isNotAdmin ]");

		if(!$entry || ($isNotAdmin && !is_null($entryKuserId) && $entryKuserId != $thisKuserId))  
			throw new KalturaAPIException(KalturaErrors::ENTRY_ID_NOT_FOUND, $captionAsset->getEntryId());
			
		$entryCaptionAssets = assetPeer::retrieveByEntryId($captionAsset->getEntryId(), array(self::getAssetTypeCoreValue(CaptionAssetType::CAPTION)));
		foreach($entryCaptionAssets as $entryCaptionAsset)
		{
			if($entryCaptionAsset->getId() == $captionAsset->getId())
				$entryCaptionAsset->setDefault(true);
			else
				$entryCaptionAsset->setDefault(false);
				
			$entryCaptionAsset->save();
		}
	}

	/**
	 * @action get
	 * @param string $captionAssetId
	 * @return KalturaCaptionAsset
	 * 
	 * @throws KalturaCaptionErrors::CAPTION_ASSET_ID_NOT_FOUND
	 */
	public function getAction($captionAssetId)
	{
		$captionAssetsDb = assetPeer::retrieveById($captionAssetId);
		if(!$captionAssetsDb)
			throw new KalturaAPIException(KalturaCaptionErrors::CAPTION_ASSET_ID_NOT_FOUND, $captionAssetId);
		
		$captionAssets = new KalturaCaptionAsset();
		$captionAssets->fromObject($captionAssetsDb);
		return $captionAssets;
	}
	
	/**
	 * List caption Assets by filter and pager
	 * 
	 * @action list
	 * @param KalturaAssetFilter $filter
	 * @param KalturaFilterPager $pager
	 * @return KalturaCaptionAssetListResponse
	 */
	function listAction(KalturaAssetFilter $filter = null, KalturaFilterPager $pager = null)
	{
		if (!$filter)
			$filter = new KalturaAssetFilter();

		if (!$pager)
			$pager = new KalturaFilterPager();
			
		$captionAssetFilter = new AssetFilter();
		
		$filter->toObject($captionAssetFilter);

		$c = new Criteria();
		$captionAssetFilter->attachToCriteria($c);
		
		$types = KalturaPluginManager::getExtendedTypes(assetPeer::OM_CLASS, self::getAssetTypeCoreValue(CaptionAssetType::CAPTION));
		$c->add(assetPeer::TYPE, $types, Criteria::IN);
		
		$totalCount = assetPeer::doCount($c);
		
		$pager->attachToCriteria($c);
		$dbList = assetPeer::doSelect($c);
		
		$list = KalturaCaptionAssetArray::fromDbArray($dbList);
		$response = new KalturaCaptionAssetListResponse();
		$response->objects = $list;
		$response->totalCount = $totalCount;
		return $response;    
	}
	
	/**
	 * @action delete
	 * @param string $captionAssetId
	 * 
	 * @throws KalturaCaptionErrors::CAPTION_ASSET_ID_NOT_FOUND
	 */
	public function deleteAction($captionAssetId)
	{
		$captionAssetDb = assetPeer::retrieveById($captionAssetId);
		if(!$captionAssetDb)
			throw new KalturaAPIException(KalturaCaptionErrors::CAPTION_ASSET_ID_NOT_FOUND, $captionAssetId);
	
		if($captionAssetDb->getDefault())
			throw new KalturaAPIException(KalturaCaptionErrors::CAPTION_ASSET_IS_DEFAULT, $captionAssetId);
		
		$captionAssetDb->setStatus(CaptionAsset::FLAVOR_ASSET_STATUS_DELETED);
		$captionAssetDb->setDeletedAt(time());
		$captionAssetDb->save();
	}
}
