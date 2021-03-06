<?php
/**
 * @package    Core
 * @subpackage KMC
 */
class kmcAction extends kalturaAction
{
	public function execute ( ) 
	{
		// Prevent the page fron being embeded in an iframe
		header( 'X-Frame-Options: DENY' );

		// Check if user already logged in and redirect to kmc2
		if( $this->getRequest()->getCookie('kmcks') ) {
			$this->redirect('kmc/kmc2');
		}

		$this->www_host = kConf::get('www_host');
		$https_enabled = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? true : false;
		$this->securedLogin = (kConf::get('kmc_secured_login') || $https_enabled) ? true : false;

		$swfUrl = ($this->securedLogin) ? 'https://' : 'http://';
		$swfUrl .= $this->www_host . myContentStorage::getFSFlashRootPath ();
		$swfUrl .= '/kmc/login/' . kConf::get('kmc_login_version') . '/login.swf';
		$this->swfUrl = $swfUrl;
		
		$this->beta = $this->getRequestParameter( "beta" );
		$this->setPassHashKey = $this->getRequestParameter( "setpasshashkey" );
		$this->hashKeyErrorCode = null;
		$this->displayErrorFromServer = false;
		if ($this->setPassHashKey) {
			try {
				$loginData = UserLoginDataPeer::isHashKeyValid($this->setPassHashKey);
				$partnerId = $loginData->getConfigPartnerId();
				$partner = PartnerPeer::retrieveByPK($partnerId);
				if ($partner && $partner->getPasswordStructureValidations())
					$this->displayErrorFromServer = true;  			
				
			}
			catch (kCoreException $e) {
				$this->hashKeyErrorCode = $e->getCode();
			}
		}		
		sfView::SUCCESS;
	}
}
