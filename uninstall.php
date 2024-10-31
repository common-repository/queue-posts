<?php

// If uninstall not called from WordPress exit
if ( !defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit ();
}

delete_option('queue_posts_time_from');
delete_option('queue_posts_time_to');
delete_option('queue_posts_minimum_interval');
delete_option('queue_posts_minimum_interval_type');
delete_option('queue_posts_last_queued');

?>