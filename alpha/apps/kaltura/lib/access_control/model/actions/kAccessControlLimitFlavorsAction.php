<?php
/**
 * @package Core
 * @subpackage model.data
 */
class kAccessControlLimitFlavorsAction extends kAccessControlAction 
{
	/**
	 * @var array
	 */
	protected $flavorParamsIds = array();
	
	/**
	 * @var bool
	 */
	protected $isBlockedList;
	
	
	public function __construct() 
	{
		parent::__construct(accessControlActionType::LIMIT_FLAVORS);
	}
	
	/**
	 * @return string
	 */
	public function getFlavorParamsIds() 
	{
		return implode(',', $this->flavorParamsIds);
	}

	/**
	 * @param string $flavorParamsIds
	 */
	public function setFlavorParamsIds($flavorParamsIds) 
	{
		$this->flavorParamsIds = explode(',', $flavorParamsIds);
	}
	
	/**
	 * @param string $flavorParamsId
	 */
	public function addFlavorParamsId($flavorParamsId) 
	{
		$this->flavorParamsIds[] = $flavorParamsId;
	}
	
	/**
	 * @return the $isBlockedList
	 */
	public function getIsBlockedList() 
	{
		return $this->isBlockedList;
	}

	/**
	 * @param bool $isBlockedList
	 */
	public function setIsBlockedList($isBlockedList) 
	{
		$this->isBlockedList = $isBlockedList;
	}

	
	
}
