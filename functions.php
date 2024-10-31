<?php

function getQueueTimeFromSetting() {
	$iQueueTimeFromSetting = get_option('queue_posts_time_from');
	if ( !$iQueueTimeFromSetting ) {
		$iQueueTimeFromSetting = '08';
	}
	return $iQueueTimeFromSetting;
}

function getQueueTimeToSetting() {
	$iQueueTimeToSetting = get_option('queue_posts_time_to');
	if ( !$iQueueTimeToSetting ) {
		$iQueueTimeToSetting = '22';
	}
	return $iQueueTimeToSetting;
}

function getQueueMinimumInterval() {
	$iMinimumInterval = get_option('queue_posts_minimum_interval');
	if ( $iMinimumInterval == '' ) {
		$iMinimumInterval = '3';
	}
	return $iMinimumInterval;
}

function getQueueMinimumIntervalType() {
	$iMinimumIntervalType = get_option('queue_posts_minimum_interval_type');
	if ( !$iMinimumIntervalType ) {
		$iMinimumIntervalType = 'h';
	}
	return $iMinimumIntervalType;
}

function getQueueLastQueued() {
	return get_option('queue_posts_last_queued', false);
}

?>