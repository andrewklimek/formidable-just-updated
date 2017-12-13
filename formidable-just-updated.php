<?php
namespace ajk_frm_just_updated;
/*
Plugin Name: Formidable “Just Updated” Trigger
Description: Trigger an action only when specified fields were JUST updated
Version:     1.1
Author:      Andrew J Klimek
Author URI:  https://github.com/andrewklimek
Plugin URI:  https://github.com/andrewklimek/formidable-just-updated
License:     GPL2
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
add_filter( 'frm_pre_update_entry', __NAMESPACE__ .'\cache_old_values', 10, 2 );
add_action( 'wp_ajax_frm_entries_update_field_ajax', __NAMESPACE__ .'\cache_old_value_ajax');
add_action( 'wp_ajax_nopriv_frm_entries_update_field_ajax', __NAMESPACE__ .'\cache_old_value_ajax');




function skip( $message, $atts ) {
	
	// If used with formidable’s autoresponder,
	// prevent 'check_update_actions' from triggering this action later on during 'frm_after_update_entry'
	if ( function_exists( '\FrmAutoresponderAppController::remove_action_from_global' ) ) {
		\FrmAutoresponderAppController::remove_action_from_global( $atts );
	}
	
	//error_log( "Skip because {$message}: " . $atts['action']->post_content['email_subject'] );// for debugging
	
	return "Skipped because " . $message;
}

/**
 * main function
 * 
 * Note, this whole check runs prior to the built-in Formidable conditional checks... This is sort of unfortunate.
 * I could use $stop = FrmFormAction::action_conditions_met( $action, $entry ); but it would only run again later...
 */ 
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
	
	// error_log( 'doing ' . $action->post_content['email_subject'] );
	
	
	$prev_value_cond = $changed_setting = array();
	
	// Check for "shortcodes"
	if ( false !== strpos( $action->post_content['just_changed_fields'], '=' ) ) {
		
		// currently doesn't allow for commas in the shortcode arg values...
		$shortcodes = explode( ',', $action->post_content['just_changed_fields'] );
		
		foreach ( $shortcodes as $shortcode ) {
			
			if ( false !== strpos( $shortcode, '=' ) ) {
				
				$shortcode_atts = shortcode_parse_atts( trim( $shortcode, ' []' ) );// returns array of arg => value from shortcode
				$field_id_for_key = array_shift($shortcode_atts);// removes first element which is just the field ID, leaves array of conditionals
				$prev_value_cond[$field_id_for_key] = $shortcode_atts;// add to a special array that holds conditional checks
				$changed_setting[] = $field_id_for_key;// also add it to the basic check array
				
			} else {
				
				// this one isn't a shortcode, just remove everything except digits and commas.
				$changed_setting[] = preg_replace( '/[^,\d]/', '', $shortcode );
				
			}
			
		}
		
	} else {// no shortcodes, just a simple parse:

		// retrieve IDs from the settings field. Remove everything except digits and commas.
		$changed_setting = explode( ',', preg_replace( '/[^,\d]/', '', $action->post_content['just_changed_fields'] ) );
	
	}

	// was this triggered by an "update field" shortcode [frm-entry-update-field]
	if ( isset( $_POST['action'] ) && $_POST['action'] === 'frm_entries_update_field_ajax' ) {
		
		// error_log( '$changed_setting:  ' . print_r( $changed_setting, true ) );
		// error_log( '$_POST:  ' . print_r( $_POST, true ) );
		// error_log( '$prev_value_cond:  ' . print_r( $prev_value_cond, true ) );
		
		// changed field setting needs to only have one field and match the field in the shortcode
		if ( count( $changed_setting ) !== 1 || $changed_setting[0] !== $_POST['field_id'] ) {
			
			return skip( "this was an single-field AJAX update, and it wasn’t the right field or all the fields required.", $atts );
		
		} elseif ( $prev_value_cond && !empty( $prev_value_cond[ $changed_setting[0] ] ) ) {// OK, the correct field was changed, is there a conditional?
			
			if ( $previous_values = wp_cache_get( 'frm_changed_fields' ) ) {
				
				if ( $cond_result = check_conditionals( $prev_value_cond, $previous_values ) ) {
					return skip( $cond_result, $atts );// failed a conditional
				}
				
			} else {
				return skip( "the cache returned nothing so we don’t know!", $atts );
			}
		}
		
	} elseif ( $previous_values = wp_cache_get( 'frm_changed_fields' ) ) {// normal, full, non-AJAX entry update
		
		// error_log( 'didn’t pass as an ajax request... $_POST:  ' . print_r( $_POST, true ) );
		

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
			return skip( "some of the required fields were not changed.", $atts );
		
		} elseif ( $prev_value_cond ) {// any previous value conditionals to check?
			
			if ( $cond_result = check_conditionals( $prev_value_cond, $previous_values ) ) {
				return skip( $cond_result, $atts );// failed a conditional
			}
			// apparently this action is OK to proceed wiht the original $skip value			
		}
		
		
	} else {
		return skip( "either there were no changes or the cache returned nothing so we don’t know!", $atts );
	}
	
	return $skip;
}

function check_conditionals( $prev_value_cond, $previous_values ) {
	
	foreach ( $prev_value_cond as $field_id => $prev_value_cond_el ) {
	
		foreach ( $prev_value_cond_el as $cond => $val ) {
	
			if (
				( 'equals' === $cond && $val !== $previous_values[ $field_id ] ) ||
				( 'not_equal' === $cond && $val === $previous_values[ $field_id ] ) ||
				( 'greater_than' === $cond && $val <= $previous_values[ $field_id ] ) ||
				( 'less_than' === $cond && $val >= $previous_values[ $field_id ] ) 
				)
				return "conditional was not met, i.e. {$previous_values[ $field_id ]} is not '{$cond}' {$val}";
		}
	}
	return false;// conditions passed, don’t skip
}

// cache old values before update
function cache_old_values($values, $id) {
	
	if ( wp_cache_get( 'frm_changed_fields' ) ) error_log("already had frm_changed_fields cache ??");

	// get ID => value array of old values
	global $wpdb;
	$field_ids = $wpdb->get_col( "SELECT field_id, meta_value FROM {$wpdb->prefix}frm_item_metas WHERE item_id={$id}" );
	$meta_values = $wpdb->get_col( null , 1 );
	$meta = array_combine( $field_ids, $meta_values );

	wp_cache_set( 'frm_changed_fields', $meta );

	return $values;
}
// different caching function for the AJAX, single-field, 1-click update buttons, since the other hook isn’t fired
function cache_old_value_ajax() {
	
	// $entry_id = FrmAppHelper::get_param( 'entry_id', 0, 'post', 'absint' );
	// $field_id = FrmAppHelper::get_param( 'field_id', 0, 'post', 'sanitize_title' );
	
	if ( empty( $_POST['entry_id'] ) || empty( $_POST['field_id'] ) ) {
		error_log('couldn’t get $_POST[\'entry_id\'] or $_POST[\'entry_id\'] during cache_old_value_ajax in formidable-just-updated.php');
		return;
	}
	
	$old_value = \FrmEntryMeta::get_entry_meta_by_field( (int) $_POST['entry_id'], (int) $_POST['field_id'] );
	
	wp_cache_set( 'frm_changed_fields', array( $_POST['field_id'] => $old_value ) );

}

// add field to the form action settings
function add_settings( $form_action, $atts = array() ) {	

	$setting = !empty( $form_action->post_content['just_changed_fields'] ) ? $form_action->post_content['just_changed_fields'] : '';

	echo '<p><label>Only if all of these fields were just changed: </label> <input type="text" id="'
		 . esc_attr( $atts['action_control']->get_field_id('just_changed_fields') ) . '" value="' . esc_attr( $setting )
		 . '" name="' . esc_attr( $atts['action_control']->get_field_name('just_changed_fields') ) . '" placeholder="comma-seperated IDs"></p>';

}