<?php
/**
 * @namespace
 */
namespace Test;

use Kaltura\Client\Type\MediaEntryFilterForPlaylist;
use Kaltura\Client\Type\MediaEntryFilter;
use Kaltura\Client\Type\UploadedFileTokenResource;
use Kaltura\Client\Enum\EntryStatus;
use Kaltura\Client\Enum\MediaType;
use Kaltura\Client\Type\MediaEntry;
use Kaltura\Client\Enum\UploadTokenStatus;
use Kaltura\Client\Type\UploadToken;

class Zend2ClientTester
{
	const UPLOAD_VIDEO_FILENAME = 'DemoVideo.flv';
	const UPLOAD_IMAGE_FILENAME = 'DemoImage.jpg';
	const ENTRY_NAME = 'Media entry uploaded from Zend Framework 2 client library';
	
	/**
	 * @var \Kaltura\Client\Client
	 */
	protected $_client;
	
	public function __construct(\Kaltura\Client\Client $client)
	{
		$this->_client = $client;
	}
	
	public function run()
	{
		$methods = get_class_methods($this);
		foreach($methods as $method)
		{
			if (strpos($method, 'test') === 0)
			{
				try 
				{
					// use the client logger interface to log
					$this->_client->getConfig()->getLogger()->log('Running '.$method);
					$this->$method();
				}
				catch(Exception $ex)
				{
					
					$this->_client->getConfig()->getLogger()->log($method . ' failed with error: ' . $ex->getMessage());
					return;
				}
			}
		}
		echo "\nFinished running client library tests\n";
	}
	
	public function testSyncFlow()
	{
		$this->_client->getSystemService();
		
		// add upload token
		$uploadToken = new UploadToken();
		$uploadToken->fileName = self::UPLOAD_VIDEO_FILENAME;
		$uploadToken = $this->_client->getUploadTokenService()->add($uploadToken);
		$this->assertTrue(strlen($uploadToken->id) > 0);
    	$this->assertEqual($uploadToken->fileName, self::UPLOAD_VIDEO_FILENAME);
    	$this->assertEqual($uploadToken->status, UploadTokenStatus::PENDING);
    	$this->assertEqual($uploadToken->partnerId, $this->_client->getConfig()->getPartnerId());
    	$this->assertEqual($uploadToken->fileSize, null);
    	
    	// add media entry
    	$entry = new MediaEntry();
    	$entry->name = self::ENTRY_NAME;
    	$entry->mediaType = MediaType::VIDEO;
    	$entry = $this->_client->getMediaService()->add($entry);
    	$this->assertTrue(strlen($entry->id) > 0);
    	$this->assertTrue($entry->status === EntryStatus::NO_CONTENT);
    	$this->assertTrue($entry->name === self::ENTRY_NAME);
    	$this->assertTrue($entry->partnerId === $this->_client->getConfig()->getPartnerId());
    	
    	// add uploaded token as resource
    	$resource = new UploadedFileTokenResource();
    	$resource->token = $uploadToken->id;
    	$entry = $this->_client->getMediaService()->addContent($entry->id, $resource);
    	$this->assertTrue($entry->status === EntryStatus::IMPORT);
    	
    	// upload file using the upload token
    	$uploadFilePath = dirname(__FILE__) . '/../resources/' . self::UPLOAD_VIDEO_FILENAME;
    	$uploadToken = $this->_client->getUploadTokenService()->upload($uploadToken->id, $uploadFilePath);
    	$this->assertTrue($uploadToken->status === UploadTokenStatus::CLOSED);
    	
    	// get flavor by entry
    	$flavorArray = $this->_client->getFlavorAssetService()->getByEntryId($entry->id);
    	$this->assertTrue(count($flavorArray) > 0);
    	$foundSource = false;
    	foreach($flavorArray as $flavor)
    	{
    		if ($flavor->flavorParamsId !== 0)
    			continue;
    			
    		$this->assertTrue($flavor->isOriginal);
    		$this->assertTrue($flavor->entryId === $entry->id);
    		$foundSource = true;
    	}
    	$this->assertTrue($foundSource);
    	
    	// count media entries
    	$mediaFilter = new MediaEntryFilter();
    	$mediaFilter->idEqual = $entry->id;
    	$mediaFilter->statusNotEqual = EntryStatus::DELETED;
    	$entryCount = $this->_client->getMediaService()->count($mediaFilter);
    	$this->assertTrue($entryCount === 1);
    	
    	// delete media entry
    	$this->_client->getMediaService()->delete($entry->id);
    	
    	sleep(5); // wait for the status to update
    	
    	// count media entries again
		$entryCount = $this->_client->getMediaService()->count($mediaFilter);
    	$this->assertEqual($entryCount, 0);
	}
	
	public function testReturnedArrayObjectUsingPlaylistExecute()
	{
		// add image entry
    	$imageEntry = $this->addImageEntry();
    	
    	// execute playlist from filters
    	$playlistFilter = new MediaEntryFilterForPlaylist();
    	$playlistFilter->idEqual = $imageEntry->id;
    	$filterArray = array();
    	$filterArray[] = $playlistFilter;
    	$playlistExecute = $this->_client->getPlaylistService()->executeFromFilters($filterArray, 10);
    	$this->assertEqual(count($playlistExecute), 1);
    	$firstPlaylistEntry = $playlistExecute[0];
    	$this->assertEqual($firstPlaylistEntry->id, $imageEntry->id);
    	
    	$this->_client->getMediaService()->delete($imageEntry->id);
	}
	
	public function testServeUrl()
	{
		$serveUrl = $this->_client->getDataService()->serve("12345", 5, true);
		$expectedArray = array(
			'service' => 'data',
			'action' => 'serve',
			'apiVersion' => $this->_client->getApiVersion(),
			'format' => 2,
			'clientTag' => $this->_client->getConfig()->getClientTag(),
			'entryId' => '12345',
			'version' => 5,
			'forceProxy' => 1,
			'partnerId' => $this->_client->getConfig()->getPartnerId(),
			'ks' => $this->_client->getKs());
		$expected = http_build_query($expectedArray);
		
	    	echo($serveUrl.PHP_EOL);
	    	echo($expected.PHP_EOL);
    	$this->assertTrue(strpos($serveUrl, $expected) !== false);
	}
	
	public function addImageEntry()
	{
		$entry = new MediaEntry();
    	$entry->name = self::ENTRY_NAME;
    	$entry->mediaType = MediaType::IMAGE;
    	$entry = $this->_client->getMediaService()->add($entry);
    	
    	$uploadToken = new UploadToken();
		$uploadToken->fileName = self::UPLOAD_IMAGE_FILENAME;
		$uploadToken = $this->_client->getUploadTokenService()->add($uploadToken);

    	$uploadFilePath = dirname(__FILE__) . '/../resources/' . self::UPLOAD_IMAGE_FILENAME;
    	$uploadToken = $this->_client->getUploadTokenService()->upload($uploadToken->id, $uploadFilePath);
    	
		$resource = new UploadedFileTokenResource();
    	$resource->token = $uploadToken->id;
    	$entry = $this->_client->getMediaService()->addContent($entry->id, $resource);
    	
    	return $entry;
	}
	
	protected function assertTrue($v)
	{
		if ($v !== true)
		{
			$backtrace = debug_backtrace();
			$msg = 'Assert failed on line: ' . $backtrace[0]['line'];
			throw new \Exception($msg);
		}
	}

	protected function assertEqual($actual, $expected)
	{
		if ($actual !== $expected)
		{
			$backtrace = debug_backtrace();
			$msg = sprintf(
				"Assert failed on line: {$backtrace[0]['line']}, expecting [%s] of type [%s], actual is [%s] of type [%s]",
				$expected,
				gettype($expected),
				$actual,
				gettype($actual));
			throw new \Exception($msg);
		}
	}
}