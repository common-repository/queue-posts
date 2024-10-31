<?php
/*
Plugin Name: Queue posts - by Wonder
Plugin URI: http://WeAreWonder.dk/wp-plugins/queue-posts/
Description: Queue posts and pages for later publishing with the press of a button.
Version: 1.6.7
Author: Wonder
Author URI: http://WeAreWonder.dk
Donate link: https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=5B6TDUTW2JVX8
License: GPL2
	
	Copyright 2024 Wonder  (email : tobias@WeAreWonder.dk)
	
    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as 
    published by the Free Software Foundation.
	
    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.
	
    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
	
*/

require_once('functions.php');

function queue_posts_load_textdomain() {
	load_plugin_textdomain( 'queue-posts', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'queue_posts_load_textdomain' );

// Make sure we don't expose any info if called directly
if ( !function_exists( 'add_action' ) ) {
	echo __( 'Hi there! I\'m just a plugin, not much I can do when called directly.', 'queue-posts' );
	exit;
}

// Allow redirection, even if my theme starts to send output to the browser
add_action('init', 'queue_posts_output_buffer');
function queue_posts_output_buffer() {
	ob_start();
}

add_action('admin_menu', 'queue_posts_admin_menu');
function queue_posts_admin_menu() {
	add_submenu_page('options-general.php', __( 'Queue Posts settings', 'queue-posts' ), __( 'Queue Posts', 'queue-posts' ), 'manage_options', 'queue-posts-settings', 'queue_posts_admin_page');
}

add_action('admin_head', 'queue_posts_head');
function queue_posts_head() {
	echo '<link rel="stylesheet" type="text/css" href="' . plugin_dir_url( __FILE__ ) . 'style.css">';
}

// Create or destroy settings in database on activation / deactivation
register_activation_hook(__FILE__, 'queue_posts_activate');
register_deactivation_hook(__FILE__, 'queue_posts_deactivate');
function queue_posts_activate() { update_option('queue_posts_last_queued', false); }
function queue_posts_deactivate() { delete_option('queue_posts_last_queued'); }

add_action('admin_footer', 'queue_posts_footer');
function queue_posts_footer() { ?>
<script type="text/javascript">
jQuery(document).ready(function($) {
	var oSubmitQueue = jQuery('<input type="button" name="publish-queue" id="publish-queue" class="button button-primary button-large button-queue" value="<?php echo __( 'Queue', 'queue-posts' ); ?>"><input type="hidden" name="queue-posts-plugin-future-date">');
	
	// Display add to queue button on new posts
	jQuery('body.post-new-php #publishing-action').append( oSubmitQueue );
	
	// If in edit screen and post is unpublished then give add to queue button
	var state = jQuery('body.post-php #publishing-action #publish').val();
	if (state != 'Update'){
		jQuery('body.post-php #publishing-action').append( oSubmitQueue );
	}
	
	jQuery('#publish-queue').click(function() {
		jQuery('body').css('cursor', 'wait');
		
		var data = {
			action: 'get_next_publish_time'
		};
		
		$.post(ajaxurl, data, function(response) {
			jQuery('input[name=queue-posts-plugin-future-date]').val( response );
			jQuery('form[name=post]').submit();
		});
	});
});
</script>
<?php }

add_action('wp_ajax_get_next_publish_time', 'get_next_publish_time_callback');
function get_next_publish_time_callback() {
	
	$last_queued = getQueueLastQueued();
	
	// If we are using database value to queue posts
	if ($last_queued) {
		
		// If we've surpassed the queue
		if (mktime() >= $last_queued){
			$latest_date = mktime();
		} else {
			$latest_date = $last_queued;
		}
		
		// Save last queued + interval to database
		$iMinimumInterval     = getQueueMinimumInterval();
		$iMinimumIntervalType = getQueueMinimumIntervalType();
		
		if ($iMinimumIntervalType === 'm') {
			$update = $iMinimumInterval * 60;
		} else {
			$update = $iMinimumInterval * 60 * 60;
		}
		
		$last_queued = $latest_date + $update;
		
		update_option('queue_posts_last_queued', $last_queued);
		
	// If we are queuing posts after the last scheduled post…
	} else {
		
		$posts = array_merge( get_posts('post_status=future'), get_pages('post_status=future') );
		
		$latest_date = 0;
		
		foreach ($posts as $post) {
			
			$date = strtotime($post->post_date);
			
			if ( $date > $latest_date ) {
				$latest_date = $date;
			}
		}
		
		if ( $latest_date == 0 ) {
			$latest_date = mktime();
		}
		
	}
	
	echo $latest_date;
	
	die(); // This is required to return a proper result
	
}

add_filter('plugin_action_links', 'queue_posts_plugin_action_links', 10, 2);
function queue_posts_plugin_action_links($links, $file) {
	static $this_plugin;
	
	if (!$this_plugin) {
		$this_plugin = plugin_basename(__FILE__);
	}
	
	// check to make sure we are on the correct plugin
	if ($file == $this_plugin) {
		$settings_link = '<a href="https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=AHE8UEBKSYJCA">' . __( 'Donate', 'queue-posts' ) . '</a>';
		array_unshift($links, $settings_link);
		
		$settings_link = '<a href="' . get_bloginfo('wpurl') . '/wp-admin/options-general.php?page=queue-posts-settings.php">' . __( 'Settings', 'queue-posts' ) . '</a>';
		array_unshift($links, $settings_link);
	}
	
	return $links;
}

add_filter('wp_insert_post_data', 'queue_posts_insert_post_data', '99', 2);
function queue_posts_insert_post_data($data, $postarr) {
	
	if (( @$data['post_status'] == 'draft' || @$data['post_status'] == 'publish' || @$data['post_status'] == 'future' )) {
		
		$transferred_future_date = @floatval( $postarr['queue-posts-plugin-future-date'] );
		$future_date             = @floatval( $postarr['queue-posts-plugin-future-date'] );
		
		if ( intval($future_date) != 0 && is_numeric($future_date) ) {
			
			$iQueueTimeFrom       = @intval(getQueueTimeFromSetting());
			$iQueueTimeTo         = @intval(getQueueTimeToSetting());
			$iMinimumInterval     = getQueueMinimumInterval();
			$iMinimumIntervalType = getQueueMinimumIntervalType();
			
			if ( $future_date < mktime() ) {
				/* Make sure the future date is not in the past. */
				$future_date = mktime();
			}
			
			if ( $iMinimumIntervalType === 'h' ) {
				/* Convert hours to minutes. */
				$iMinimumInterval   = $iMinimumInterval * 60;
				$iAddRandomInterval = rand(0, (60 * 60)); // Minutes
			} else {
				$iAddRandomInterval = rand(0, ($iMinimumInterval * 60) * 0.2) + rand(0, 60); // Minutes + seconds
			}
			$iMinimumInterval = intval($iMinimumInterval);

			/* Convert interval from seconds to minutes/hours, and add a random number of minutes to avoid posting at exact times. */
			$future_date = $future_date + ($iMinimumInterval * 60) + $iAddRandomInterval;
			
			/* If future date and time is not within the "Publish time" period... */
			if ( date('G', $future_date) < $iQueueTimeFrom || date('G', $future_date) > $iQueueTimeTo ) {
				$new_future_date = $transferred_future_date + (60 * 60 * 24);
				
				$future_date = mktime($iQueueTimeFrom, rand(0, 59), rand(0, 59), date('n', $new_future_date), date('j', $new_future_date), date('Y', $new_future_date));
			}
			
			$future_date_gmt = $future_date - (get_option('gmt_offset') * 60);
			
			$data['post_status']   = 'future';
			$data['post_date']     = date('Y-m-d H:i:s', $future_date);
			$data['post_date_gmt'] = date('Y-m-d H:i:s', $future_date_gmt);
			
		}
		
	}
	
	return $data;
	
}

function queue_posts_admin_page() {
	
	if ( isset($_POST['queue-time-from']) ) {
		$iLastQueued	      = $_POST['queue-posts-last-queued'];
		$iQueueTimeFrom       = $_POST['queue-time-from'];
		$iQueueTimeTo         = $_POST['queue-time-to'];
		$iMinimumInterval     = $_POST['minimum-interval'];
		$iMinimumIntervalType = $_POST['minimum-interval-type'];
		
		$iMinimumInterval     = str_replace( ',', '.', $iMinimumInterval );
		if ( !is_numeric($iMinimumInterval) ) {
			$iMinimumInterval = 0;
		}
		
		if ($iLastQueued == 'false') {
			$iLastQueued = false;
		}
		
		update_option('queue_posts_time_from',             $iQueueTimeFrom);
		update_option('queue_posts_time_to',               $iQueueTimeTo);
		update_option('queue_posts_minimum_interval',      $iMinimumInterval);
		update_option('queue_posts_minimum_interval_type', $iMinimumIntervalType);
		update_option('queue_posts_last_queued',           $iLastQueued);
		
		wp_redirect('?page=' . $_GET['page'] . '&msg=saved');
		
		exit();
	}
	
	$iLastQueued	      = getQueueLastQueued();
	$iQueueTimeFrom       = getQueueTimeFromSetting();
	$iQueueTimeTo         = getQueueTimeToSetting();
	$iMinimumInterval     = getQueueMinimumInterval();
	$iMinimumIntervalType = getQueueMinimumIntervalType();
	
	/* Is user using 12 hour time format? */
	$timeformat       = strtolower( get_option('time_format') );
	$bUsing12HourTime = str_replace('\a', '', $timeformat);
	$bUsing12HourTime = (strpos($bUsing12HourTime, 'a') !== FALSE);
	?>
	
	<style type="text/css">
	.donate-box {
		float: right;
		width: 200px;
		padding: 25px;
		margin:  25px;
		border: 2px solid #bbb;
		background-color: #e7e7e7;
	}
	
	.donate-box h2 {
		margin-top: 0;
	}
	
	.donate-box hr {
		height: 1px;
		border-width: 2px 0 0 0;
		border-style: solid;
		border-color: #bbb;
	}
	
	h2 img {
		float: left;
		margin-right: 5px;
	}
	h2 small {
		display: block;
		margin-left: 38px;
		font-size: 12px;
	}
	</style>
	
	<div class="donate-box">
	<h2><?php echo __( 'Donate', 'queue-posts' ); ?></h2>
		
		<p>
			<?php echo __( 'Enjoying this plugin? Your support helps us continue enhancing and maintaining it. Consider a $5 contribution to keep the updates coming!', 'queue-posts' ); ?>
		</p>
		
		<p>
			<a title="<?php echo __( 'Donate', 'queue-posts' ); ?>" href="https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=AHE8UEBKSYJCA"><?php echo __( 'Donate', 'queue-posts' ); ?> &raquo;</a>
		</p>
		
		<hr>
		
		<h2><?php echo __( 'See also…', 'queue-posts' ); ?></h2>
		
		<p>
			<a title="Simple MailChimp plugin" href="http://wordpress.org/plugins/simple-mailchimp/">Simple MailChimp plugin</a><br>
			<a title="Embed Image Links plugin" href="http://wordpress.org/plugins/embed-image-links/">Embed Image Links plugin</a>
		</p>
	</div>
	
	<div class="wrap">
		
		<h2>
			<img alt="<?php echo __( 'Settings for Queue Posts', 'queue-posts' ); ?>" src="<?php echo plugin_dir_url( __FILE__ ); ?>img/settings-icon.png" width="32" height="32" style="margin-bottom: -7px;">
			<?php echo __( 'Settings for Queue Posts', 'queue-posts' ); ?>
			<small><?php echo __( 'by', 'queue-posts' ); ?> <a href="http://wearewonder.dk" target="_blank">Wonder</a></small>
		</h2>
		
		<form method="post" action="" onsubmit="return queuePostsPluginValidateSettings();">
			
			<p style="margin: 15px 0;">
				<label for="queue-posts-time-from">
					<?php echo __( 'Publish between', 'queue-posts' ); ?>:
				</label>
				
				<br><br>
				
				<select id="queue-posts-time-from" name="queue-time-from">
					<option value="00"><?php echo ($bUsing12HourTime ? '12 AM' : '00'); ?></option>
					<option value="01"><?php echo ($bUsing12HourTime ? ' 1 AM' : '01'); ?></option>
					<option value="02"><?php echo ($bUsing12HourTime ? ' 2 AM' : '02'); ?></option>
					<option value="03"><?php echo ($bUsing12HourTime ? ' 3 AM' : '03'); ?></option>
					<option value="04"><?php echo ($bUsing12HourTime ? ' 4 AM' : '04'); ?></option>
					<option value="05"><?php echo ($bUsing12HourTime ? ' 5 AM' : '05'); ?></option>
					<option value="06"><?php echo ($bUsing12HourTime ? ' 6 AM' : '06'); ?></option>
					<option value="07"><?php echo ($bUsing12HourTime ? ' 7 AM' : '07'); ?></option>
					<option value="08"><?php echo ($bUsing12HourTime ? ' 8 AM' : '08'); ?></option>
					<option value="09"><?php echo ($bUsing12HourTime ? ' 9 AM' : '09'); ?></option>
					<option value="10"><?php echo ($bUsing12HourTime ? '10 AM' : '10'); ?></option>
					<option value="11"><?php echo ($bUsing12HourTime ? '11 AM' : '11'); ?></option>
					<option value="12"><?php echo ($bUsing12HourTime ? '12 PM' : '12'); ?></option>
					<option value="13"><?php echo ($bUsing12HourTime ? ' 1 PM' : '13'); ?></option>
					<option value="14"><?php echo ($bUsing12HourTime ? ' 2 PM' : '14'); ?></option>
					<option value="15"><?php echo ($bUsing12HourTime ? ' 3 PM' : '15'); ?></option>
					<option value="16"><?php echo ($bUsing12HourTime ? ' 4 PM' : '16'); ?></option>
					<option value="17"><?php echo ($bUsing12HourTime ? ' 5 PM' : '17'); ?></option>
					<option value="18"><?php echo ($bUsing12HourTime ? ' 6 PM' : '18'); ?></option>
					<option value="19"><?php echo ($bUsing12HourTime ? ' 7 PM' : '19'); ?></option>
					<option value="20"><?php echo ($bUsing12HourTime ? ' 8 PM' : '20'); ?></option>
					<option value="21"><?php echo ($bUsing12HourTime ? ' 9 PM' : '21'); ?></option>
					<option value="22"><?php echo ($bUsing12HourTime ? '10 PM' : '22'); ?></option>
					<option value="23"><?php echo ($bUsing12HourTime ? '11 PM' : '23'); ?></option>
				</select>
			
				&mdash;
			
				<select id="queue-posts-time-to" name="queue-time-to">
					<option value="01"><?php echo ($bUsing12HourTime ? ' 1 AM' : '01'); ?></option>
					<option value="02"><?php echo ($bUsing12HourTime ? ' 2 AM' : '02'); ?></option>
					<option value="03"><?php echo ($bUsing12HourTime ? ' 3 AM' : '03'); ?></option>
					<option value="04"><?php echo ($bUsing12HourTime ? ' 4 AM' : '04'); ?></option>
					<option value="05"><?php echo ($bUsing12HourTime ? ' 5 AM' : '05'); ?></option>
					<option value="06"><?php echo ($bUsing12HourTime ? ' 6 AM' : '06'); ?></option>
					<option value="07"><?php echo ($bUsing12HourTime ? ' 7 AM' : '07'); ?></option>
					<option value="08"><?php echo ($bUsing12HourTime ? ' 8 AM' : '08'); ?></option>
					<option value="09"><?php echo ($bUsing12HourTime ? ' 9 AM' : '09'); ?></option>
					<option value="10"><?php echo ($bUsing12HourTime ? '10 AM' : '10'); ?></option>
					<option value="11"><?php echo ($bUsing12HourTime ? '11 AM' : '11'); ?></option>
					<option value="12"><?php echo ($bUsing12HourTime ? '12 PM' : '12'); ?></option>
					<option value="13"><?php echo ($bUsing12HourTime ? ' 1 PM' : '13'); ?></option>
					<option value="14"><?php echo ($bUsing12HourTime ? ' 2 PM' : '14'); ?></option>
					<option value="15"><?php echo ($bUsing12HourTime ? ' 3 PM' : '15'); ?></option>
					<option value="16"><?php echo ($bUsing12HourTime ? ' 4 PM' : '16'); ?></option>
					<option value="17"><?php echo ($bUsing12HourTime ? ' 5 PM' : '17'); ?></option>
					<option value="18"><?php echo ($bUsing12HourTime ? ' 6 PM' : '18'); ?></option>
					<option value="19"><?php echo ($bUsing12HourTime ? ' 7 PM' : '19'); ?></option>
					<option value="20"><?php echo ($bUsing12HourTime ? ' 8 PM' : '20'); ?></option>
					<option value="21"><?php echo ($bUsing12HourTime ? ' 9 PM' : '21'); ?></option>
					<option value="22"><?php echo ($bUsing12HourTime ? '10 PM' : '22'); ?></option>
					<option value="23"><?php echo ($bUsing12HourTime ? '11 PM' : '23'); ?></option>
				</select>
			</p>
			
			<p style="margin: 15px 0;">
				<label for="queue-posts-minimum-interval">
					<?php echo __( 'Minimum time between posts', 'queue-posts' ); ?>:
				</label>
				
				<br><br>
				
				<input id="queue-posts-minimum-interval" name="minimum-interval" type="text" maxlength="4" value="<?php echo $iMinimumInterval; ?>" style="width: 50px; text-align: center;">
				<select id="queue-posts-minimum-interval-type" name="minimum-interval-type">
					<option value="h"><?php echo __( 'hour(s)', 'queue-posts' ); ?></option>
					<option value="m"><?php echo __( 'minute(s)', 'queue-posts' ); ?></option>
				</select>
			</p>
			
			<p style="margin: 15px 0;">
				<label for="queue-posts-method">
					<?php echo __( 'Queueing method', 'queue-posts' ); ?>:
				</label>
				
				<br><br>
				
				<select id="queue-posts-last-queued" name="queue-posts-last-queued">
					<option value="<?php if ( $iLastQueued ) { echo $iLastQueued; } else { echo mktime(); } ?>"<?php if ( $iLastQueued ) { echo ' selected=selected'; } ?>><?php echo __( 'Insert next post after previously queued item', 'queue-posts' ); ?></option>
					<option value="false"<?php if ( !$iLastQueued ) { echo ' selected=selected'; } ?>><?php echo __( 'Insert next post after the last scheduled post', 'queue-posts' ); ?></option>
				</select>
			</p>
			
			<p>
				<input type="submit" value="<?php echo __( 'Save', 'queue-posts' ); ?>">
			</p>
			
		</form>
		
	</div>
	
	<script type="text/javascript">
	jQuery(document).ready(function() {
		
		jQuery('#queue-posts-time-from').val('<?php echo $iQueueTimeFrom; ?>');
		jQuery('#queue-posts-time-to').val('<?php echo $iQueueTimeTo; ?>');
		jQuery('#queue-posts-minimum-interval').val('<?php echo $iMinimumInterval; ?>');
		jQuery('#queue-posts-minimum-interval-type').val('<?php echo $iMinimumIntervalType; ?>');
		
		<?php if ( @$_GET['msg'] == 'saved' ) : ?>
			showMessage('<?php echo __( 'Your settings have been saved.', 'queue-posts' ); ?>');
		<?php endif; ?>
		
		/* Allow only numeric characters typed in the Minimum Interval field. */
		jQuery('#queue-posts-minimum-interval').keydown(function(event) {
			// Allow: backspace, delete, decimal, left navigation, right navigation, tab, escape, and enter
			if ( event.keyCode == 46 || event.keyCode == 8 || event.keyCode == 190 || event.keyCode == 188 || event.keyCode == 37 || event.keyCode == 39 || event.keyCode == 9 || event.keyCode == 27 || event.keyCode == 13 || 
				// Allow: Ctrl+A
				(event.keyCode == 65 && event.ctrlKey === true) || 
				// Allow: home, end, left, right
				(event.keyCode >= 35 && event.keyCode <= 39)) {
					// let it happen, don't do anything
				return;
			} else {
				// Ensure that it is a number and stop the keypress
				if (event.shiftKey || (event.keyCode < 48 || event.keyCode > 57) && (event.keyCode < 96 || event.keyCode > 105 )) {
					event.preventDefault();
				}
			}
		});
		
	});
	
	function queuePostsPluginValidateSettings() {
		
		var oTimeFrom     = jQuery('#queue-posts-time-from');
		var oTimeTo       = jQuery('#queue-posts-time-to');
		var oInterval     = jQuery('#queue-posts-minimum-interval');
		var oIntervalType = jQuery('#queue-posts-minimum-interval-type');
		
		if ( parseInt(oTimeFrom.val()) >= parseInt(oTimeTo.val()) ) {
			showError('<?php echo esc_js( __( '&quot;Publish from&quot; time cannot be larger than or equal to &quot;Publish to&quot; time.', 'queue-posts' ) ); ?>');
			oTimeFrom.focus();
			return false;
		}
		
		var iIntervalHours = oInterval.val();
		if ( oIntervalType.val() == 'm' ) {
			iIntervalHours = iIntervalHours / 60;
		}
		
		if ( iIntervalHours >= ( parseInt(oTimeTo.val()) - parseInt(oTimeFrom.val()) ) ) {
			showError('<?php echo __( 'The &quot;Minimum time between posts&quot; cannot be larger than or equal to the &quot;Publish between&quot; time.', 'queue-posts' ); ?>');
			oInterval.focus();
			return false;
		}
		
		return true;
		
	}
	
	function showError(s) {
		
		var oMsgBox = jQuery('#message');
		
		if ( oMsgBox.length ) {
			oMsgBox.addClass('error');
			oMsgBox.removeClass('updated');
		} else {
			oMsgBox = jQuery('<div id="message" class="error"></div>');
			jQuery('.wrap form').prepend( oMsgBox );
		}
		
		oMsgBox.html(s);
		
	}
	
	function showMessage(s) {
		
		var oMsgBox = jQuery('#message');
		
		if ( oMsgBox.length ) {
			oMsgBox.addClass('updated');
			oMsgBox.removeClass('error');
		} else {
			oMsgBox = jQuery('<div id="message" class="updated"></div>');
			jQuery('.wrap form').prepend( oMsgBox );
		}
		
		oMsgBox.html(s);
		
	}
	</script>
	
	<?php
	
}

?>