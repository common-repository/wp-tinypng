<?php
/*
  Plugin Name: WP TinyPNG
  Plugin URI: http://www.optimising.com.au/wp-tinypng
  Description: Compresses images on your posts, pages and media library using the TinyPNG compression API.
  Version: 0.0.1
  Author: Optimising
  Author URI: http://www.optimising.com.au/
*/


add_filter('https_ssl_verify', '__return_false');
add_filter('https_local_ssl_verify', '__return_false');


// Ensure we can decode JSON
if ( ! function_exists( 'json_decode' ) ) {
	require_once( 'lib/JSON/JSON.php' );
}

// Ensure we can download files from external servers easily.
if ( !function_exists( 'download_url' ) ) {
	require_once( ABSPATH . 'wp-admin/includes/file.php' );
}



class WP_TinyPNG {
	
	
	/* Constants */
	
	const RESULT_FAILURE = 0;
	const RESULT_SUCCESS = 1;
	
	
	
	/** Variables **/
	
	protected $version = '0.0.1';
	protected $api_host = 'api.tinypng.com';
	protected $api_url = 'https://api.tinypng.com/shrink';
	
	protected $debugging = false;
	protected $process_children = true;
	
	protected $tmp_dir = null;
	protected $api_key = null;
	protected $curl = null;
	
	protected $upload_dir = null;
	
	protected $last_result = null;
	
	protected $force_compression = false;
	
	// This was from the TinyPNG documentation, but I'm not sure it is of any use...
	protected $errors = array (
		'Unauthorized' => 'The request was not authorized with a valid API key.',
		'InputMissing' => 'The file that was uploaded is empty or no data was posted.',
		'BadSignature' => 'The file was not recognised as a PNG file. It may be corrupted or it is a different file type.',
		'DecodeError' => 'The file had a valid PNG signature, but could not be decoded. It may be corrupted or is of an unsupported type.',
		'TooManyRequests' => 'Your monthly upload limit has been exceeded. Either wait until the next calendar month, or upgrade your subscription.',
		'InternalServerError' => 'An internal error occurred during conversion. This error is usually temporary. If the uploaded file is a valid PNG file, you can try again later.',	
	);
	
	
	
	
	/** Methods **/
	
	function __construct() {
		
		$this->keep_original = get_option( 'wp_tinypng_duplicate', true);
		$this->debugging = get_option( 'wp_tinypng_debug', false);
		$this->process_children = get_option( 'wp_tinypng_children', true);
		$this->force_compression = ( ( isset ( $_REQUEST[ 'force-compression' ] ) && ( $_REQUEST[ 'force-compression' ] == '1' ) ) ? true : false );
		
		$this->api_key = get_option( 'wp_tinypng_api', true); 'AAAAAA-BBBBBB-CCCCCCC-DDDDDDD';

		$this->cacert_path = dirname( __FILE__ ) . '/cacert.pem';
		
		$upload_dir = wp_upload_dir();
		$this->upload_dir = $upload_dir[ 'baseurl' ];
		
		$this->backup_dir = ABSPATH . 'wp-content/uploads/tinypng-backup/';
		
		add_filter( 'manage_media_columns', array( &$this, 'columns' ) );
		add_action( 'manage_media_custom_column', array( &$this, 'custom_column' ), 10, 2 );
		add_action( 'admin_init', array( &$this, 'admin_init' ) );
		add_action( 'admin_menu', array( &$this, 'admin_menu' ) );
		add_action( 'admin_init', array( &$this, 'register_settings' ) );
		
	}
	
	
	/**
	 * Plugin setting functions
	 */
	function register_settings( ) {
		add_settings_section( 'wp_tinypng_settings', 'TinyPNG Compression', array( &$this, 'settings_cb' ), 'media' );
			
		add_settings_field( 'wp_tinypng_api', __( 'TinyPNG API Key', 'wp-tinypng' ), 
			array( &$this, 'render_api_opts' ), 'media', 'wp_tinypng_settings' );
			
		add_settings_field( 'wp_tinypng_duplicate', __( 'Keep original file', 'wp-tinypng' ), 
			array( &$this, 'render_duplicate_opts' ), 'media', 'wp_tinypng_settings' );

		add_settings_field( 'wp_tinypng_children', __( 'Compress children (thumbnails)', 'wp-tinypng' ), 
			array( &$this, 'render_children_opts' ), 'media', 'wp_tinypng_settings' );
			
		add_settings_field( 'wp_tinypng_debug', __( 'Enable debug processing', 'wp-tinypng' ), 
			array( &$this, 'render_debug_opts' ), 'media', 'wp_tinypng_settings' );

		register_setting( 'media', 'wp_tinypng_api' );
		register_setting( 'media', 'wp_tinypng_duplicate' );
		register_setting( 'media', 'wp_tinypng_children' );
		register_setting( 'media', 'wp_tinypng_debug' );	
	}
	
	
	function settings_cb( ) {
	}
	
	
	function render_api_opts() {
		$key = 'wp_tinypng_api';
		$val = get_option( $key );
		?><input type="text" name="<?php echo $key ?>" value="<?php if ($val) { echo esc_attr( $val ); } ?>" /><br />
		<?php _e( 'Visit <a href="https://tinypng.com/developers" target="_blank">TinyPNG Developer section</a> to get an API key.', 'wp-tinypng' );
	}
	
	function render_duplicate_opts() {
		$key = 'wp_tinypng_duplicate';
		$val = get_option( $key );
		?><input type="checkbox" name="<?php echo $key ?>" <?php if ($val) { echo ' checked="checked" '; } ?>/> <?php _e( 'This allows you to keep the original, uncompressed file in your uploads folder for later use', 'wp-tinypng' );
	}
	
	function render_children_opts() {
		$key = 'wp_tinypng_children';
		$val = get_option( $key );
		?><input type="checkbox" name="<?php echo $key ?>" <?php if ($val) { echo ' checked="checked" '; } ?>/> <?php _e( 'Compress all thumbnail versions of each attachment', 'wp-tinypng' );
	}
	
	function render_debug_opts() {
		$key = 'wp_tinypng_debug';
		$val = get_option( $key );
		?><input type="checkbox" name="<?php echo $key ?>" <?php if ($val) { echo ' checked="checked" '; } ?>/> <?php _e( 'If you are having trouble with the plugin enable this option can reveal some information about your system needed for support.', 'wp-tinypng' );
	}
	
	
	function admin_init( ) {
		//load_plugin_textdomain(WP_SMUSHIT_DOMAIN, false, dirname(plugin_basename(__FILE__)).'/languages/');
		//wp_enqueue_script( 'common' );
	}


	// Add administration sections and menu item
	function admin_menu( ) {
		add_media_page( 'PNG Compression', 'PNG Compression', 'edit_others_posts', 'wp-tinypng', array( &$this, 'admin_page' ) );
	}
	
	
	// Admin page HTML and processing 
	function admin_page() {		
		?><div class="wrap"> 
			<div id="icon-upload" class="icon32"><br /></div>
			<h2><?php _e( 'PNG Compression', 'wp_tinypng' ) ?></h2><?php 
			if ( ! isset ( $_REQUEST[ 'ids' ] ) && isset ( $_REQUEST[ 'id' ] ) ) {
				$_REQUEST[ 'ids' ] = $_REQUEST[ 'id' ];
			}
			if ( isset ( $_REQUEST[ 'ids' ] ) ) {
				// Get information only for the chosen attachment IDs
				$attachments = get_posts( array(
					'numberposts' => -1,
					'include' => ( is_array( $_REQUEST['ids'] ) ? $_REQUEST['ids'] : explode(',', $_REQUEST['ids'] ) ),
					'post_type' => 'attachment',
					'post_mime_type' => 'image'
				));
				if ( sizeof($attachments) < 1 ) {
					_e( "<p>Requested images not found.</p>", 'wp-tinypng' );
				} else {
					if ( isset($_REQUEST['_wpnonce']) ) {
						// We've been passed IDs, so process them 
						if ( stristr( $_REQUEST[ 'action' ], 'Compress Images' ) ) {
							$this->process_attachments( $attachments );
						} elseif ( stristr( $_REQUEST[ 'action' ], 'Revert' ) ) {
							$this->revert_attachments( $attachments );
						} else {
							_e( "<p>Request error occured.</p>", 'wp-tinypng' );
						}
						?><p><a href="">&lt;&lt; Back to list</a></p><?php
					} else {
						// Might be an attack - double check the deletion
						$this->confirm_process_attachments( $attachments );
					}
				}
			} else { 
				// Get information for all image attachments in library
				$attachments = get_posts( array(
					'numberposts' => -1,
					'post_type' => 'attachment',
					'post_mime_type' => 'image'
				));
				if ( sizeof($attachments) < 1 ) {
					_e( "<p>You don't appear to have uploaded any images yet.</p>", 'wp-tinypng' );
				} else {
					// We haven't been sent IDs, so show the file list
					$this->display_file_list_form( $attachments );
				}
			}
		?></div>
			<?php
	}
	
	
	
	function display_file_list_form( $attachments = null ) {
		?><p>Choose images: 
			<a href="#" class="select-all-items">Select All</a> |
			<a href="#" class="select-no-items">Select None</a> |
			<a href="#" class="select-compressed-items">Select Compressed</a> | 
			<a href="#" class="select-uncompressed-items">Select Uncompressed</a>
		</p>
		<form method="POST" action="">
			<style type="text/css">
				.attachment-list {
					
				}
				.attachment-list .attachment-item {
					padding:.5em .7em;
					background:#f9f9f9;
					margin:0;
					border-bottom:1px solid #fff;
				}
				.attachment-item.compressed {
					color:#090;
					opacity:.7;
				}
			</style>
			<script type="text/javascript">
				jQuery(document).ready(function($){
					$('.select-all-items').click(function() {
						$('.attachment-list input[type=checkbox]').attr('checked','checked');
						
					});
					$('.select-no-items').click(function() {
						$('.attachment-list input[type=checkbox]').removeAttr('checked');
						
					});
					$('.select-compressed-items').click(function() {
						$('.attachment-list li input[type=checkbox]').removeAttr('checked');
						$('.attachment-list li.compressed').find('input[type=checkbox]').attr('checked','checked');
						
					});
					$('.select-uncompressed-items').click(function() {
						$('.attachment-list li input[type=checkbox]').removeAttr('checked');
						$('.attachment-list li').not('.compressed').find('input[type=checkbox]').attr('checked','checked');
						
					});
				});
			</script>
			<?php wp_nonce_field( 'wp-tinypng', '_wpnonce'); ?>
			<ul class="attachment-list"><?php foreach( $attachments as $attachment ) : 
				
				// Retreive information about the attachment. 
				$original_meta = wp_get_attachment_metadata( $attachment->ID, true );
				
				// If it's not a PNG file, skip it.
				if ( ! stristr( $original_meta[ 'file' ], '.png' ) ) {
					continue;
				}
				
				// Display attachment
				?><li class="attachment-item <?php echo ( isset ( $original_meta[ 'wp-tinypng' ] ) ? 'compressed' : null ); ?>">
					<input type="checkbox" name="ids[]" value="<?php echo $attachment->ID; ?>" />
					<?php echo $original_meta[ 'file' ]; /*<img src="<?php echo $upload_base . '/' . $original_meta[ 'file' ]; ?>" width="50" />*/ 
				
					if ( isset ( $original_meta[ 'wp-tinypng' ] ) ) {
						?> (Already compressed)<?php
					}
				
				?></li><?php 
				
			endforeach; 
			
			?>
			</ul>
			<p><input type="checkbox" name="force-compression" value="1" /> Force Compression for already compressed images</p>
			<p><input type="submit" name="action" value="Compress Images" /> <input type="submit" name="action" value="Revert to Backup" /></p>
		</form><?php 
	}
	
	
	
	function confirm_process_attachments( $attachments ) {
		?><form method="POST" action="">
			<?php wp_nonce_field( 'wp-tinypng', '_wpnonce'); ?>
			<p>Are you sure you want to compress these attachments?</p>
			<p>
				<input type="submit" name="action" value="Compress Images" />
				<a href="">No</a>
			</p><?php		
		
		?></form><?php		
	}
	

	
	function process_attachments( $attachments = null ) {
		if ( !isset($_REQUEST['_wpnonce']) || !wp_verify_nonce( $_REQUEST['_wpnonce'], 'wp-tinypng' ) || !current_user_can( 'edit_others_posts' ) ) {
			wp_die( __( 'Error occurred. Reload this page and try again.' ) );
		}
		
		$this->debug( '$_REQUEST[ids]: ', $_REQUEST[ 'ids' ] );
		$this->debug( '$attachments = ', $attachments );
		
		foreach( $attachments as $attachment ) : 
			$this->process_attachment( $attachment, $this->process_children );
			
			// Give everyone's servers a quick break
			sleep(0.5); 
			
		endforeach; // Go to next attachment
	}
	
	
	function process_attachment( $attachment, $process_children = false ) {
		if ( $attachment->ID && wp_attachment_is_image( $attachment->ID ) === false ) {
			return $meta;
		}	
		$attachment_local_path = get_attached_file( $attachment->ID );
		$attachment_remote_url = wp_get_attachment_url( $attachment->ID );
		
		$this->debug( '$attachment_local_path = "' . $attachment_local_path . '"');
		$this->debug( '$attachment_remote_url = "' . $attachment_remote_url . '"');

		// Get infomation about the attachment
		$original_meta = wp_get_attachment_metadata( $attachment->ID, true );
		
		$new_meta = $this->process_image( $attachment_local_path, $original_meta, $this->process_children, $this->force_compression );
		
		// Update attachment data to reflect changes
		wp_update_attachment_metadata( $attachment->ID, $new_meta );
	}
	
	
	
	function process_image( $local_path, $meta, $process_children = true, $force_compression = false ) {
		
		if ( ! isset ( $meta[ 'wp-tinypng' ] ) || ( $force_compression ) ) {	
			// Send the image to TinyPNG
			if ( $success = $this->compress_png( $local_path ) ) {
				$this->debug( 'JSON result:', $this->last_result );
				
				$temp_file = $this->get_tinypng_file();
				
				if ( $this->keep_original ) {
					
					// Store original file in backup folder
					$local_backup_path = $this->get_backup_path( $local_path );
				
					$this->debug( 'local_backup_path:', $local_backup_path );
					
					rename( $local_path, $local_backup_path );
				}
				
				// Put new image where old image was
				$success = $this->rename( $temp_file, $local_path );
				
			} 
			
			$json_result = $this->get_json_result();				
			$output_data = $json_result->output;	
			
				
			// If after all that, we've succeeded..
			if ( $success ) {
				
				if ( ! $this->keep_original ) {
					// Delete original if everything went well and we're not keeping it.
					@unlink( $local_path );					
				}
			
				// Mark the attachment as compressed so we can check next time
				$meta['wp-tinypng'] = '1';
				
				// Display result of our compression to user
				$this->display_compression_success_message( $meta, $output_data );
	
			} else {
			
				// Display failure result
				$this->display_compression_failure_message( $meta, 'Unknown Failure' );
				
				unset( $meta['wp-tinypng'] );
				
				$this->debug( '<ul>
					<li>Last Code: ' . $this->last_code . '</li>
					<li>Last Error: ' . $this->last_error . '</li>
					<li>Last Result: ' . $this->last_result . '</li></ul>' );
			}
		} else {
			$this->display_compression_failure_message( $meta, 'Already compressed' );
		}
		
		if ( $process_children && isset( $meta['sizes'] ) ) {
			// No resized versions, so we can exit
			foreach($meta['sizes'] as $size_key => &$size_data) {
				$local_size_path = trailingslashit(dirname($local_path)) . $size_data['file'];
				$this->debug( 'local_size_path=['. $local_size_path .']' );
				$size_data = $this->process_image( $local_size_path, $size_data, false, $force_compression );
			}
			
		}
		
		return $meta;
	}
	
	
	
	
	
	function revert_attachments( $attachments = null ) {
		if ( !isset($_REQUEST['_wpnonce']) || !wp_verify_nonce( $_REQUEST['_wpnonce'], 'wp-tinypng' ) || !current_user_can( 'edit_others_posts' ) ) {
			wp_die( __( 'Error occurred. Reload this page and try again.' ) );
		}
		
		$this->debug( '$_REQUEST[ids] = ', $_REQUEST[ 'ids' ] );
		$this->debug( '$attachments = ', $attachments );
		
		foreach( $attachments as $attachment ) : 
			$this->revert_attachment( $attachment, $this->process_children );
			
		endforeach;
	}
	
	
	
	
	function revert_attachment( $attachment, $process_children = false ) {
		if ( $attachment->ID && wp_attachment_is_image( $attachment->ID ) === false ) {
			return $meta;
		}	
		$attachment_local_path = get_attached_file( $attachment->ID );
		$attachment_remote_url = wp_get_attachment_url( $attachment->ID );
		
		$this->debug( '$attachment_local_path = "' . $attachment_local_path . '"');
		$this->debug( '$attachment_remote_url = "' . $attachment_remote_url . '"');

		// Get infomation about the attachment
		$original_meta = wp_get_attachment_metadata( $attachment->ID, true );
		
		$new_meta = $this->revert_image( $attachment_local_path, $original_meta, $this->process_children );
		
		// Update attachment data to reflect changes
		wp_update_attachment_metadata( $attachment->ID, $new_meta );
	}
	
	
	
	function revert_image( $local_path, $meta, $process_children = true ) {
		
		if ( isset ( $meta[ 'wp-tinypng' ] ) ) {	
		
		
			$local_backup_path = $this->get_backup_path( $local_path );
			$local_backup_backup_path = $this->get_backup_path( $local_backup_path );
			$delete_compressed_on_revert = true; // todo - do we want this?
				
			$this->debug( 'local_backup_path = "', $local_backup_path . '"' );
			$this->debug( 'local_backup_backup_path = "', $local_backup_backup_path . '"' );
			
			// Make sure we still have the original file (the backup)..
			if ( $success = file_exists( $local_backup_path ) ) {
				
				// Move backed up file out of the way
				if ( $success = $this->rename( $local_backup_path, $local_backup_backup_path ) ) {
				
					// Put current image in it's place
					if ( $success = $this->rename( $local_path, $local_backup_path ) ) {	
				
						// Move original file to where compressed image was
						if ( $success = $this->rename( $local_backup_backup_path, $local_path ) ) {	
						
							if ( $delete_compressed_on_revert ) {
								// Delete compressed image
								@unlink( $local_backup_backup_path );
							} else {
								if ( $success = $this->rename( $local_backup_backup_path, $local_backup_path ) ) {
									// worked!
								}
							}
						} else {							
						}
					} else {
					}
				} else {
				}
			}
		
			// If after all that, we've succeeded..
			if ( $success ) {
			
				// Act like we never compressed it in the first place
				unset( $meta['wp-tinypng'] );
				
				// Display result of our compression to user
				$this->display_revert_success_message( $meta );
	
			} else {
			
				// Display failure result
				$this->display_revert_failure_message( $meta, 'Unknown Failure' );
				
			}
		} else {
			$this->display_revert_failure_message( $meta, 'Not already compressed' );
		}
		
		if ( $process_children && isset( $meta['sizes'] ) ) {
			// No resized versions, so we can exit
			foreach($meta['sizes'] as $size_key => &$size_data) {
				$local_size_path = trailingslashit(dirname($local_path)) . $size_data['file'];
				$this->debug( 'local_size_path=['. $local_size_path .']' );
				$size_data = $this->revert_image( $local_size_path, $size_data, false );
			}
			
		}
		
		return $meta;
	}
	
	
	function rename( $original_path, $new_path ) {	// Put new file where old file was
		if ( ! ( $success = rename( $original_path, $new_path ) ) ) { //@
			// If that way didn't work, copy temporary file then delete old version. Check for success.
			$success = copy( $original_path, $new_path ) && @unlink( $original_path );
		}
		return $success;
	}
	
	
	
	function get_tinypng_file() {
		$json_result = $this->get_json_result();				
		$output_data = $json_result->output;	
		
		// Download the file provided by TinyPNG			
		$temp_file = download_url( $output_data->url );
		
		// Check temporary file
		if ( ! is_string( $temp_file ) ) {
			return false; //__("Error downloading file", 'wp-tinypng' );
		}
		if ( is_wp_error( $temp_file ) ) {
			unlink($temp_file); //@
			return false; //sprintf( __("Error downloading file (%s)", 'wp-tinypng' ), $temp_file->get_error_message() );
		}
		if (!file_exists($temp_file)) {
			return false; //sprintf( __("Unable to locate file from TinyPNG (%s)", 'wp-tinypng' ), $temp_file);
		}
		return $temp_file;
	}
	
	
	function get_backup_path( $local_path ) {
		
		$last_slash = strrpos( $local_path, '/' );
		
		// Insert 'backup-' just before the filename
		$local_backup_path = substr_replace( $local_path, '_tinypng-backup-', $last_slash + 1, 0 ); 
		
		return $local_backup_path;
	}
	
	
	
	
	/* Messages */
	
	function display_compression_success_message( $meta, $output_data = null ) {
		echo '<p>Attachment "<strong>' . $meta[ 'file' ] . '</strong>" compressed by <strong>' . ( 100 - ( $output_data->ratio * 100 ) ) . '%</strong></p>';
	}
	
	function display_compression_failure_message( $meta, $message = null ) {
		echo '<p>Failed to compress "<strong>' . $meta[ 'file' ] . '</strong>" - "' . $message . '"</p>';
	}
	
	
	function display_revert_success_message( $meta ) {
		echo '<p>Attachment "<strong>' . $meta[ 'file' ] . '</strong>" reverted to original.</p>';
	}
	
	function display_revert_failure_message( $meta, $message = null ) {
		echo '<p>Failed to revert "<strong>' . $meta[ 'file' ] . '</strong>" - "' . $message . '"</p>';
	}
	
	
	
	
	function debug( $text = null, $var_dump = null ) {
		if ( $this->debugging ) {
			echo '<p class="debug">DEBUG: ' . $text;
			if ( $var_dump ) {
				var_dump( $var_dump );
			}
			echo '</p>';
		}
	}
	
	
	/**
	 * Print column header for compression results in the media library using
	 * the `manage_media_columns` hook.
	 */
	function columns( $defaults ) {
		$defaults['wp-tinypng'] = 'PNG Compression';
		return $defaults;
	}
	function custom_column( $column_name, $id ) {
		if( 'wp-tinypng' == $column_name ) {
			$data = wp_get_attachment_metadata($id);
			if ( stristr( $data[ 'file' ], '.png' ) === false ) {
				echo '<span class="faded" style="opacity:.5">' . __( 'Not PNG file', 'wp-tinypng' ) . '</span>';
				return;
			}
			if ( isset( $data['wp-tinypng'] ) && ( $data['wp-tinypng'] == '1' ) ) {
				print __( 'Processed', 'wp-tinypng' );
				printf( "<br><a href=\"upload.php?page=wp-tinypng&id=%d\">%s</a>",
					 $id,
					 __( 'Re-compress', 'wp-tinypng' ) );
			} else {
			  if ( wp_attachment_is_image( $id ) ) {
				print __( 'Not processed', 'wp-tinypng' );
				printf( "<br><a href=\"upload.php?page=wp-tinypng&id=%d\">%s</a>",
					 $id,
					 __('Compress now', 'wp-tinypng'));
				}
			}
		}
	}
	
	
	
	function create_connection() {
		if ($this->curl !== null ) {
			return true;
		}
		$this->curl = curl_init();
		
		curl_setopt ($this->curl, CURLOPT_SSL_VERIFYPEER, TRUE); 
		curl_setopt ($this->curl, CURLOPT_CAINFO, $this->cacert_path);

		$curlOpts = array(
			CURLOPT_RETURNTRANSFER => 1,
			CURLOPT_URL => $this->api_url,
			CURLOPT_USERAGENT => 'TinyPNG PHP API v1',
			CURLOPT_POST => 1,
			CURLOPT_USERPWD => 'api:' . $this->api_key,
			CURLOPT_BINARYTRANSFER => 1
		);
		curl_setopt_array($this->curl, $curlOpts);
	}
	
	
	
	function compress_png( $file ) {
    	if ( ! $this->curl ) {
            $this->create_connection(); 
			if ( ! $this->curl ) {
				throw new Exception( 'CURL has not been initialised' );
			}
        }
    	if ( ! file_exists( $file ) ) {
            throw new Exception( 'File "' . $file . '" does not exist' );
        }
    	if ( ! is_readable( $file ) ) {
            throw new Exception( 'File "' . $file . '" is not readable' );
        }
        curl_setopt( $this->curl, CURLOPT_POSTFIELDS, file_get_contents( $file ) );
        $this->last_result = curl_exec( $this->curl );
        $this->last_code = curl_getinfo( $this->curl, CURLINFO_HTTP_CODE );
		$this->last_error = curl_error($this->curl);
        return ( ( $this->last_code >= 200 ) && ( $this->last_code < 300 ) );
	}
	
	
	function get_last_result() {
		return $this->last_result;		
	}
	
	
	
	/*
	*	Return a human-readable error for a given error response code.
	*/
	function get_error( $code = null ) {
		if ( isset ( $this->errors[ $code ] ) ) {
			return $this->errors[ $code ];
		}
		return 'Unknown error';
	}
	
	
    /*
    * 	Return API response as JSON
    */
    function get_json_result() {
		$data = $this->get_last_result();
		if ( function_exists('json_decode') ) {
			$data = json_decode( $data );
		} else {
			$json = new Services_JSON( );
			$data = $json->decode( $data );
		}
		return $data;
    }	
	
}


$wptinypng = new WP_TinyPNG();