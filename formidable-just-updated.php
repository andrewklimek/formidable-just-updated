<?php
namespace ajk_frm_just_updated;
/*
Plugin Name: Formidable “Just Updated” Trigger
Description: Trigger an action only when specified fields were JUST updated
Version:	 1.0
Author:	  Andrew J Klimek
Author URI:  
License:	 GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Requires Formidable 2.01.02 or higher, because I had to add a new hook to formidable for this to work:
https://github.com/Strategy11/formidable-forms/commit/d81c0c03a3d5e7fc866bdfb0d932c4683d3b4602
$skip_this_action = apply_filters( 'frm_skip_form_action', $skip_this_action, compact( 'action', 'entry', 'form', 'event' ) );

Formidable “Just Updated” Trigger is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 2 of the License, or
any later version.
 
Formidable “Just Updated” Trigger is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.
 
You should have received a copy of the GNU General Public License along with 
Formidable “Just Updated” Trigger. If not, see https://www.gnu.org/licenses/gpl-2.0.html.
*/

add_action( 'frm_additional_action_settings', __NAMESPACE__ .'\add_settings', 100, 2 );
add_action( 'frm_skip_form_action', __NAMESPACE__ .'\intercept', 11, 2 );
add_filter( 'frm_pre_update_entry', __NAMESPACE__ .'\compare_values_for_updates', 10, 2 );


// If used with formidable’s autoresponder,
// prevent 'check_update_actions' from triggering this action later on during 'frm_after_update_entry'
function remove_action_from_global( $atts ) {
	if ( function_exists( '\FrmAutoresponderAppController::remove_action_from_global' ) )
		\FrmAutoresponderAppController::remove_action_from_global( $atts );
}

// main function
function intercept( $skip, $atts ) {

	if ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) {// is this needed?
		return $skip;
	}

	extract( $atts );// $action (obj), $entry (int or obj), $form (obj), $event (str)

	if ( empty( $action->post_content['just_changed_fields'] ) ) {// exit if there are no “Just Updated” settings
		return $skip;
	}

	if ( $event !== 'update' ) {// does formidable's autoresponder ignore this?
		return "skip because this wasn’t an update.";
	}

	if ( ! is_object( $entry ) ) {// apparently $entry can be integer or object!
		$entry = \FrmEntry::getOne( $entry, true );
	}

	// retrieve IDs from the settings field. Remove everything except digits and commas.
	$changed_setting = explode( ',', preg_replace( '/[^,\d]/', '', $action->post_content['just_changed_fields'] ) );

	// was this triggered by an "update field" shortcode [frm-entry-update-field]
	if ( isset( $_POST['action'] ) && $_POST['action'] === 'frm_entries_update_field_ajax' ) {
		
		// changed field setting needs to only have one field and match the field in the shortcode
		if ( count( $changed_setting ) !== 1 || $changed_setting[0] !== $_POST['field_id'] ) {
			remove_action_from_global( $atts );
			$skip = "skip because this was an single-field AJAX update, and it wasn’t the right field or all the fields required.";
		}
		
	} elseif ( $previous_values = wp_cache_get( 'frm_changed_fields' ) ) {

		// get ID => value array of new values
		global $wpdb;
		$field_ids = $wpdb->get_col( "SELECT field_id, meta_value FROM {$wpdb->prefix}frm_item_metas WHERE item_id={$entry->id}" );
		$meta_values = $wpdb->get_col( null, 1 );
		$meta = array_combine( $field_ids, $meta_values );

		$changed = array_diff_assoc( $meta, $previous_values ) + array_diff_assoc( $previous_values, $meta );

		$changed_setting = array_flip( $changed_setting );// flip field IDs from value to key
		
		// $matches = array_intersect_key( $changed_setting, $previous_values );// Could inplement "ANY" logic like this. would return true if (!$matches)
		$matches = array_diff_key( $changed_setting, $changed );// "ALL" logic. Removes all fields from the required fields which are found in the changed fields
		
		if ( $matches ) {// any required fields left without a match?
			remove_action_from_global( $atts );
			$skip = "skip because some of the required fields were not changed.";
		}
		
	} else {
		remove_action_from_global( $atts );
		$skip = "skip because either there were no changes or the cache returned nothing so we don’t know!";
	}
	
	return $skip;
}

// cache old values before update
function compare_values_for_updates($values, $id) {

	// get ID => value array of old values
	global $wpdb;
	$field_ids = $wpdb->get_col( "SELECT field_id, meta_value FROM {$wpdb->prefix}frm_item_metas WHERE item_id={$id}" );
	$meta_values = $wpdb->get_col( null , 1 );
	$meta = array_combine( $field_ids, $meta_values );

	wp_cache_set( 'frm_changed_fields', $meta );

	return $values;
}

// add field to the form action settings
function add_settings( $form_action, $atts = array() ) {	

	$setting = !empty( $form_action->post_content['just_changed_fields'] ) ? $form_action->post_content['just_changed_fields'] : '';

	echo '<p><label>Only if all of these fields were just changed: </label> <input type="text" id="'
		 . esc_attr( $atts['action_control']->get_field_id('just_changed_fields') ) . '" value="' . esc_attr( $setting )
		 . '" name="' . esc_attr( $atts['action_control']->get_field_name('just_changed_fields') ) . '" placeholder="comma-seperated IDs"></p>';

}