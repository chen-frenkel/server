<?php

/**
 * This class centralizes job suspension utilities
 * 
 * @package Core
 * @subpackage Batch
 *
 */
class kJobsSuspender {
	
	/** The maximum number of jobs we will handle for each (dc,partner_id,job_type,job_sub_type) */
	const MAX_PROCESSED_ROWS = 500;
	
	/**
	 * Entry point to job balancing.
	 * - Move jobs from status '0' to status '11' in case there are too many pending jobs
	 * - move jobs back from status '11' to status '0' in case the load is over.
	 * 
	 */
	public static function balanceJobsload() {
	
		$minPendingJobs = self::getSuspenderMinPendingJobs();
		$maxPendingJobs = self::getSuspenderMaxPendingJobs();
		
		$dcId = kDataCenterMgr::getCurrentDcId();
		$loadedKeys = array();
	
		// Suspend Jobs
		$c = self::createJobBalanceQuery($dcId, $minPendingJobs);
		$stmt = BatchJobLockPeer::doSelectStmt($c);
		$rows= $stmt->fetchAll(PDO::FETCH_ASSOC);
	
		foreach ($rows as $row) {
			$partnerId = $row['PARTNER_ID'];
			$jobType = $row['JOB_TYPE'];
			$jobSubType = $row['JOB_SUB_TYPE'];
				
			$loadedKeys[] = $partnerId . "#" . $jobType . "#" . $jobSubType;
			$jobCount = $row[BatchJobLockPeer::COUNT];
				
			if($jobCount > $maxPendingJobs)
				self::suspendJobs(($jobCount - $maxPendingJobs), $dcId, $partnerId, $jobType, $jobSubType);
		}
	
		// Unsuspend jobs
		$c = self::createReturnBalancedJobsQuery($dcId);
		$stmt = BatchJobLockSuspendPeer::doSelectStmt($c);
		$rows= $stmt->fetchAll(PDO::FETCH_ASSOC);
	
		foreach ($rows as $row) {
			$partnerId = $row['PARTNER_ID'];
			$jobType = $row['JOB_TYPE'];
			$jobSubType = $row['JOB_SUB_TYPE'];
			$key = $partnerId . "#" . $jobType . "#" . $jobSubType;
				
			if(!in_array($key, $loadedKeys))
				self::unsuspendJobs(($maxPendingJobs - $minPendingJobs), $dcId, $partnerId, $jobType, $jobSubType);
		}
	}
	
	/**
	 * This function generates the query that gets all the jobs that needs to be suspended.
	 */
	private static function createJobBalanceQuery($dc, $value) {
		$c = new Criteria();
	
		// Where
		$c->add(BatchJobLockPeer::STATUS, BatchJob::BATCHJOB_STATUS_PENDING, Criteria::EQUAL);
		$c->add(BatchJobLockPeer::DC, $dc, Criteria::EQUAL);
		$c->add(BatchJobLockPeer::SCHEDULER_ID, null, Criteria::ISNULL);
		// Group by
		$c->addGroupByColumn(BatchJobLockPeer::DC);
		$c->addGroupByColumn(BatchJobLockPeer::PARTNER_ID);
		$c->addGroupByColumn(BatchJobLockPeer::JOB_TYPE);
		$c->addGroupByColumn(BatchJobLockPeer::JOB_SUB_TYPE);
		// Having
		$c->addHaving($c->getNewCriterion(BatchJobLockPeer::ID, BatchJobLockPeer::COUNT . '>' . $value, Criteria::CUSTOM));
		// Select
		$c->addSelectColumn(BatchJobLockPeer::COUNT);
		foreach($c->getGroupByColumns() as $column)
			$c->addSelectColumn($column);
		return $c;
	}
	
	/**
	 * This function generates the query that gets all the jobs that needs to be unsuspended.
	 * All those jobs will appear in batch_job_lock_suspend table
	 */
	private static function createReturnBalancedJobsQuery($dc) {
		$c = new Criteria();
	
		// Where
		$c->add(BatchJobLockSuspendPeer::DC, $dc, Criteria::EQUAL);
		// Group by
		$c->addGroupByColumn(BatchJobLockSuspendPeer::DC);
		$c->addGroupByColumn(BatchJobLockSuspendPeer::PARTNER_ID);
		$c->addGroupByColumn(BatchJobLockSuspendPeer::JOB_TYPE);
		$c->addGroupByColumn(BatchJobLockSuspendPeer::JOB_SUB_TYPE);
		foreach($c->getGroupByColumns() as $column)
			$c->addSelectColumn($column);
		return $c;
	}
	
	/**
	 * This function suspends up to ($limit) jobs of a given ($dc, $partnerId, $jobType, $jobSubType) 
	 */
	private static function suspendJobs($limit, $dc, $partnerId, $jobType, $jobSubType) {
	
		$limit = min(self::MAX_PROCESSED_ROWS, $limit);
		
		// Find IDs
		$c = new Criteria();
		$c->addSelectColumn(BatchJobLockPeer::ID);
		$c->add( BatchJobLockPeer::STATUS, BatchJob::BATCHJOB_STATUS_PENDING, Criteria::EQUAL);
		$c->add( BatchJobLockPeer::DC, $dc, Criteria::EQUAL);
		$c->add( BatchJobLockPeer::PARTNER_ID, $partnerId, Criteria::EQUAL);
		$c->add( BatchJobLockPeer::JOB_TYPE, $jobType, Criteria::EQUAL);
		$c->add( BatchJobLockPeer::JOB_SUB_TYPE, $jobSubType, Criteria::EQUAL);
		$c->add( BatchJobLockPeer::SCHEDULER_ID, null, Criteria::ISNULL);
	
		$c->addDescendingOrderByColumn(BatchJobLockPeer::PRIORITY);
		$c->addDescendingOrderByColumn(BatchJobLockPeer::URGENCY);
		$c->addDescendingOrderByColumn(BatchJobLockPeer::ESTIMATED_EFFORT);
		$c->setLimit($limit);
	
		$stmt = BatchJobLockPeer::doSelectStmt($c);
		$rows= $stmt->fetchAll(PDO::FETCH_ASSOC);
	
		$jobIds = array();
		foreach ($rows as $row)
			$jobIds[] = $row['ID'];
	
		// Suspend chosen ids
		$res = self::setJobsStatus($jobIds, BatchJob::BATCHJOB_STATUS_DONT_PROCESS, BatchJob::BATCHJOB_STATUS_PENDING, true);
		self::moveToSuspendedJobsTable($jobIds);
		KalturaLog::info("$res jobs of partner ($partnerId) job type ($jobType / $jobSubType) on DC ($dc) were suspended");
	}
	
	/**
	 * This function unsuspends up to ($limit) jobs of a given ($dc, $partnerId, $jobType, $jobSubType)
	 */
	private static function unsuspendJobs($limit, $dc, $partnerId, $jobType, $jobSubType) {
	
		$limit = min(self::MAX_PROCESSED_ROWS, $limit);
		
		// Find IDs from Batch Job Suspend Table
		$c = new Criteria();
		$c->addSelectColumn(BatchJobLockSuspendPeer::ID);
		$c->add( BatchJobLockSuspendPeer::DC, $dc, Criteria::EQUAL);
		$c->add( BatchJobLockSuspendPeer::PARTNER_ID, $partnerId, Criteria::EQUAL);
		$c->add( BatchJobLockSuspendPeer::JOB_TYPE, $jobType, Criteria::EQUAL);
		$c->add( BatchJobLockSuspendPeer::JOB_SUB_TYPE, $jobSubType, Criteria::EQUAL);
	
		$c->addAscendingOrderByColumn(BatchJobLockSuspendPeer::PRIORITY);
		$c->addAscendingOrderByColumn(BatchJobLockSuspendPeer::URGENCY);
		$c->addAscendingOrderByColumn(BatchJobLockSuspendPeer::ESTIMATED_EFFORT);
		$c->setLimit($limit);
	
		$stmt = BatchJobLockSuspendPeer::doSelectStmt($c);
		$rows= $stmt->fetchAll(PDO::FETCH_ASSOC);
	
		$jobIds = array();
		foreach ($rows as $row)
			$jobIds[] = $row['ID'];
	
		// Return the jobs from batch_job_lock_suspend table
		self::moveFromSuspendedJobsTable($jobIds);
		// Update the jobs status to pending
		$res = self::setJobsStatus($jobIds, BatchJob::BATCHJOB_STATUS_PENDING, BatchJob::BATCHJOB_STATUS_DONT_PROCESS, false);
		KalturaLog::info("$res jobs of partner ($partnerId) job type ($jobType / $jobSubType) on DC ($dc) were unsuspended");
	}
	
	/**
	 * This function sets the status of the given $jobIds to the given $status.
	 * We're saved from the kFlowManager::updatedJob callback since we work directly through the DB. 
	 * No callbacks are called.
	 * Returns the number of the affected rows.
	 */
	private static function setJobsStatus($jobIds, $status, $oldStatus, $addSchedulerCond) {
	
		$suspenderUpdateChunk = self::getSuspenderUpdateChunk();
		
		$affectedRows = 0;
		$con = Propel::getConnection();
		$update = new Criteria();
		$update->add(BatchJobLockPeer::STATUS, $status);
	
		$start = 0;
		$end = sizeof($jobIds);
		while($start < $end) {
			$updateCond = new Criteria();
			$updateCond->add(BatchJobLockPeer::ID, array_slice($jobIds, $start, min($suspenderUpdateChunk, $end - $start)), Criteria::IN);
			$updateCond->add(BatchJobLockPeer::STATUS, $oldStatus, Criteria::EQUAL);
			if($addSchedulerCond)
				$updateCond->add(BatchJobLockPeer::SCHEDULER_ID, null, Criteria::ISNULL);
			
			$affectedRows += BasePeer::doUpdate($updateCond, $update, $con);
			$start += $suspenderUpdateChunk;
		}
		return $affectedRows;
	}
	
	/**
	 * This function moves a known list of suspended job ids from batch_job_lock table to batch_job_lock_suspended table
	 * and deletes them from the batch_job_lock table
	 * @param array $jobIds The jobs we want to move from batch_job_lock table
	 */
	private static function moveToSuspendedJobsTable($jobIds) {
		
		$suspenderUpdateChunk = self::getSuspenderUpdateChunk();
		$con = Propel::getConnection();
		
		$start = 0;
		$end = sizeof($jobIds);
		while($start < $end) {
			
			// Insert into batch_job_lock_suspended table
			$insertQuery = "INSERT INTO " . BatchJobLockSuspendPeer::TABLE_NAME .
				" (SELECT * FROM " . BatchJobLockPeer::TABLE_NAME .
				" WHERE " . BatchJobLockPeer::STATUS . " = " . BatchJob::BATCHJOB_STATUS_DONT_PROCESS .
				" AND " . BatchJobLockPeer::ID . " IN ( " .  
				implode(",", array_slice($jobIds, $start, min($suspenderUpdateChunk, $end - $start))) . 
				"))";
			
			$con->exec($insertQuery);
			
			// Delete from batch_job_lock table
			$deleteQuery = "DELETE FROM " . BatchJobLockPeer::TABLE_NAME .
				" WHERE " . BatchJobLockPeer::STATUS . " = " . BatchJob::BATCHJOB_STATUS_DONT_PROCESS .
				" AND " . BatchJobLockPeer::ID . " IN ( " .
				implode(",", array_slice($jobIds, $start, min($suspenderUpdateChunk, $end - $start))) .
				")";
				
			$con->exec($deleteQuery);
			$start += $suspenderUpdateChunk;
		}
	}
	
	/**
	 * This function returns a known list of suspended job ids from batch_job_lock_suspended table to batch_job_lock table
	 * and deletes them from the batch_job_lock_suspended table.
	 * @param array $jobIds The jobs we want to move from batch_job_lock_suspended table
	 */
	private static function moveFromSuspendedJobsTable($jobIds) {
	
		$suspenderUpdateChunk = self::getSuspenderUpdateChunk();
		$con = Propel::getConnection();
	
		$start = 0;
		$end = sizeof($jobIds);
		while($start < $end) {
				
			// Move job from batch_job_lock_suspended to batch_job_lock
			$insertQuery = "INSERT INTO " . BatchJobLockPeer::TABLE_NAME .
			" (SELECT * FROM " . BatchJobLockSuspendPeer::TABLE_NAME .
			" WHERE " . BatchJobLockSuspendPeer::ID . " IN ( " .
			implode(",", array_slice($jobIds, $start, min($suspenderUpdateChunk, $end - $start))) .
			"))";
				
			$con->exec($insertQuery);
				
			// Delete from batch_job_lock_suspended table
			$deleteQuery = "DELETE FROM " . BatchJobLockSuspendPeer::TABLE_NAME .
			" WHERE " . BatchJobLockSuspendPeer::ID . " IN ( " .
			implode(",", array_slice($jobIds, $start, min($suspenderUpdateChunk, $end - $start))) .
			")";
	
			$con->exec($deleteQuery);
			$start += $suspenderUpdateChunk;
		}
	}
	
	
	/**
	 * This function returns the 'suspender_update_chunk' from the configuration files.
	 * This value is a number that defines the chunk size in which we will suspend and unsuspend jobs
	 */
	private static function getSuspenderUpdateChunk()
	{
		return kConf::get('suspender_update_chunk');
	}
	
	/**
	 * This function returns the 'suspender_max_pending_jobs' from the configuration files.
	 * This value is a number that defines the value from which we will start suspending jobs
	 */
	private static function getSuspenderMaxPendingJobs()
	{
		return kConf::get('suspender_max_pending_jobs');
	}
	
	/**
	 * This function returns the 'suspender_min_pending_jobs' from the configuration files.
	 * This value is a number that defines the value from which we will start returning pending
	 * jobs to the queue
	 */
	private static function getSuspenderMinPendingJobs()
	{
		return kConf::get('suspender_min_pending_jobs');
	}
}

?>