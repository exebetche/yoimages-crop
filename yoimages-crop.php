<?php

if ( ! defined ( 'ABSPATH' ) ) {
	die ( 'No script kiddies please!' );
}

function yoimg_crop_image(){
	$_required_args = array(
		'post',
		'size',
		'width',
		'height',
		'x',
		'y',
		'quality',
	);
	$_args          = array();
	foreach( $_required_args as $_key ){
		$_args[ $_key ] = esc_html( $_POST[ $_key ] );
	}
	do_action( 'yoimg_pre_crop_image' );
	$result = yoimg_crop_this_image( $_args );
	do_action( 'yoimg_post_crop_image' );
	wp_send_json( $result );
}

function yoimg_crop_this_image( $args ){
	$req_post = esc_html( $args['post'] );
	if ( current_user_can( 'edit_post', $req_post ) ) {
		$req_size                  = esc_html( $args[ 'size' ] );
		$req_width                 = esc_html( $args[ 'width' ] );
		$req_height                = esc_html( $args[ 'height' ] );
		$req_x                     = esc_html( $args[ 'x' ] );
		$req_y                     = esc_html( $args[ 'y' ] );
		$req_quality               = esc_html( $args[ 'quality' ] );
		$yoimg_retina_crop_enabled = yoimg_is_retina_crop_enabled_for_size( $req_size );
		$img_path = _load_image_to_edit_path( $req_post );
		$attachment_metadata = maybe_unserialize( wp_get_attachment_metadata( $req_post ) );

		// Append a timestamp to images to clear external caches.
		$crop_options = get_option ( 'yoimg_crop_settings' );
		// Extract path and file information to use for resizing file
		$filepath = pathinfo($attachment_metadata['file']);
		// Postfix the current timestamp to cache
		$new_filename_postfix = '-crop-' . time();
		// Iterate through Square, Landscape, Portait, Letterbox
		foreach ($attachment_metadata['sizes'] as $crop_type => &$size) {
		  // Only update the filename on the crop we're updating...
		  if( $crop_type == $args['size'] ) {
			// Save pre crop filename to pass to frontend preview
			$pre_crop_filename = $size['file'];
			// Replace the file of this crop with the new name including the cachebusting extension
			// Only if the cachebusting setting is on in the YoImages admin_enqueue_scripts    // Only save if cachebusting has been enabled in the YoImages settings.
			if( isset($crop_options ['cachebusting_is_active']) && $crop_options ['cachebusting_is_active'] ) {
			  $size['file'] = $filepath['filename'] . $new_filename_postfix . '-' . $req_width . 'x' . $req_height . '.' . $filepath['extension'];
			}
		  }
		}
		// Save crop urls to post metadata
		wp_update_attachment_metadata($req_post, $attachment_metadata);

		if ( isset( $attachment_metadata['yoimg_attachment_metadata']['crop'][$req_size]['replacement'] ) ) {
			$replacement = $attachment_metadata['yoimg_attachment_metadata']['crop'][$req_size]['replacement'];
		} else {
			$replacement = null;
		}
		$has_replacement = ! empty ( $replacement ) && get_post( $replacement );
		if ( $has_replacement ) {
			$replacement_path = _load_image_to_edit_path( $replacement );
			$img_editor = wp_get_image_editor( $replacement_path );
			$full_image_attributes = wp_get_attachment_image_src( $replacement, 'full' );
		} else {
			$img_editor = wp_get_image_editor( $img_path );
			$full_image_attributes = wp_get_attachment_image_src( $req_post, 'full' );
		}
		if ( is_wp_error( $img_editor ) ) {
			yoimg_log( $img_editor );
			return false;
		}
		$cropped_image_sizes = yoimg_get_image_sizes( $req_size );
		$is_crop_smaller = $full_image_attributes[1] < $cropped_image_sizes['width'] || $full_image_attributes[2] < $cropped_image_sizes['height'];
		$crop_width = $cropped_image_sizes['width'];
		$crop_height = $cropped_image_sizes['height'];
		$img_editor->crop( $req_x, $req_y, $req_width, $req_height, $crop_width, $crop_height, false );
		$img_editor->set_quality( $req_quality );
		$img_path_parts = pathinfo($img_path);
		if ( empty( $attachment_metadata['sizes'][$req_size] ) || empty( $attachment_metadata['sizes'][$req_size]['file'] ) ) {
			$cropped_image_filename = yoimg_get_cropped_image_filename( $img_path_parts['filename'], $crop_width, $crop_height, $img_path_parts['extension'] );
			$attachment_metadata['sizes'][$req_size] = array(
				'file' => $cropped_image_filename,
				'width' => $crop_width,
				'height' => $crop_height,
				'mime-type' => $attachment_metadata['sizes']['thumbnail']['mime-type']
			);
		} else {
			$cropped_image_filename = $attachment_metadata['sizes'][$req_size]['file'];
		}
		$img_editor->save( $img_path_parts['dirname'] . '/' . $cropped_image_filename );
		if ( $yoimg_retina_crop_enabled ) {
			$img_editor_retina = wp_get_image_editor( $has_replacement ? $replacement_path : $img_path );
			if ( is_wp_error( $img_editor_retina ) ) {
				yoimg_log( $img_editor_retina );
				return false;
			}
			$crop_width_retina = $crop_width * 2;
			$crop_height_retina = $crop_height * 2;
			$is_crop_retina_smaller = $full_image_attributes[1] < $crop_width_retina || $full_image_attributes[2] < $crop_height_retina;
			if ( ! $is_crop_retina_smaller ) {
				$img_editor_retina->crop( $req_x, $req_y, $req_width, $req_height, $crop_width_retina, $crop_height_retina, false );
				$img_editor_retina->set_quality( $req_quality );
				$img_retina_path_parts = pathinfo($cropped_image_filename);
				$cropped_image_retina_filename = $img_retina_path_parts['filename'] . '@2x.' . $img_retina_path_parts['extension'];
				$img_editor_retina->save( $img_path_parts['dirname'] . '/' . $cropped_image_retina_filename );
			}
		}
		$attachment_metadata['sizes'][$req_size]['width'] = $crop_width;
		$attachment_metadata['sizes'][$req_size]['height'] = $crop_height;
		if ( empty( $attachment_metadata['yoimg_attachment_metadata']['crop'] ) ) {
			$attachment_metadata['yoimg_attachment_metadata']['crop'] = array();
		}
		$attachment_metadata['yoimg_attachment_metadata']['crop'][$req_size] = array(
				'x' => $req_x,
				'y' => $req_y,
				'width' => $req_width,
				'height' => $req_height
		);
		if ( $has_replacement ) {
			$attachment_metadata['yoimg_attachment_metadata']['crop'][$req_size]['replacement'] = $replacement;
		}
		wp_update_attachment_metadata( $req_post, $attachment_metadata );
		
		$mime_type = $attachment_metadata['sizes'][$req_size]['mime-type'];
		
		$img_dir = dirname($img_path);
		$img_path2 = $img_dir.'/'.$cropped_image_filename;
		
		switch($mime_type){
			case 'image/png':
				$gd_img = @imagecreatefrompng($img_path2);
				imagepng($gd_img, $img_path2);
			break;
			case 'image/jpeg':
				$gd_img = @imagecreatefromjpeg($img_path2);
				imagejpeg($gd_img, $img_path2);
			break;
			case 'image/gif':
				$gd_img = @imagecreatefromgif($img_path2);
				imagegif($gd_img, $img_path2);
			break;
		}
		
		if( $yoimg_retina_crop_enabled ){
			return array(
				'previous_filename' => $pre_crop_filename,
				'filename'        => $cropped_image_filename,
				'smaller'         => $is_crop_smaller,
				'retina_filename' => $cropped_image_retina_filename,
				'retina_smaller'  => $is_crop_retina_smaller,
				//~ 'mime_type'  => $mime_type,
			);
		} else {
			return array(
				'previous_filename' => $pre_crop_filename,
				'filename' => $cropped_image_filename,
				'smaller'  => $is_crop_smaller,
				//~ 'mime_type'  => $mime_type,
				//~ 'img_dir'  => $img_dir,
			);
		}

	}
	return false;
}

function yoimg_edit_thumbnails_page() {
	global $yoimg_image_id;
	global $yoimg_image_size;
	$yoimg_image_id = esc_html( $_GET ['post'] );
	$yoimg_image_size = esc_html( $_GET ['size'] );

	$sizes = yoimg_get_image_sizes ();
	$size = false;
	foreach ( $sizes as $size_key => $size_value ) {
		if ( $size_value['crop'] == 1 && $size_value['active'] ) {
			if ( $size_key == $yoimg_image_size ) {
				$size = $size_key;
				break;
			} else if ( ! $size ) {
				$size = $size_key;
			}
		}
	}
	$yoimg_image_size = $size;

	if (current_user_can ( 'edit_post', $yoimg_image_id ) ) {
		include (YOIMG_CROP_PATH . '/html/edit-image-size.php');
	} else {
		die ();
	}
}

function yoimg_replace_image_for_size() {
	$id = esc_html( $_POST['image'] );
	$size = esc_html( $_POST['size'] );
	if (current_user_can ( 'edit_post', $id ) ) {
		$attachment_metadata = wp_get_attachment_metadata( $id );
		$attachment_metadata['yoimg_attachment_metadata']['crop'][$size]['replacement'] = esc_html( $_POST['replacement'] );
		wp_update_attachment_metadata( $id, $attachment_metadata );
	}
	die();
}

function yoimg_restore_original_image_for_size() {
	$id = esc_html( $_POST['image'] );
	$size = esc_html( $_POST['size'] );
	if (current_user_can ( 'edit_post', $id ) ) {
		$attachment_metadata = wp_get_attachment_metadata( $id );
		unset( $attachment_metadata['yoimg_attachment_metadata']['crop'][$size]['replacement'] );
		wp_update_attachment_metadata( $id, $attachment_metadata );
	}
	die();
}

add_action( 'wp_ajax_yoimg_edit_thumbnails_page', 'yoimg_edit_thumbnails_page' );
add_action( 'wp_ajax_yoimg_restore_original_image_for_size', 'yoimg_restore_original_image_for_size' );
add_action( 'wp_ajax_yoimg_crop_image', 'yoimg_crop_image' );
add_action( 'wp_ajax_yoimg_replace_image_for_size', 'yoimg_replace_image_for_size' );
