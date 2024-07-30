<?php
namespace ajk_frm_just_updated;
/*
Plugin Name: Formidable “Just Updated” Trigger
Description: Trigger an action only when specified fields were JUST updated
Version:     1.2.1
Author:      Andrew J Klimek
Author URI:  https://github.com/andrewklimek
Plugin URI:  https://github.com/andrewklimek/formidable-just-updated
License:     GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Requires Formidable 2.01.02 or higher, because I had to add a new hook to formidable for this to work:
https://github.com/Strategy11/formidable-forms/commit/d81c0c03a3d5e7fc866bdfb0d932c4683d3b4602
$skip_this_action = apply_filters( 'frm_skip_form_action', $skip_this_action, compact( 'action', 'entry', 'form', 'event' ) );
*/

add_action( 'frm_additional_action_settings', __NAMESPACE__ .'\add_settings', 100, 2 );
add_action( 'frm_skip_form_action', __NAMESPACE__ .'\intercept', 11, 2 );
add_filter( 'frm_pre_update_entry', __NAMESPACE__ .'\cache_old_values', 10, 2 );
add_action( 'wp_ajax_frm_entries_update_field_ajax', __NAMESPACE__ .'\cache_old_value_ajax');
add_action( 'wp_ajax_nopriv_frm_entries_update_field_ajax', __NAMESPACE__ .'\cache_old_value_ajax');
add_filter('frm_form_options_before_update', __NAMESPACE__ .'\options_update', 20, 2);


function skip( $message, $atts ) {
	
	// If used with formidables autoresponder,
	// prevent 'check_update_actions' from triggering this action later on during 'frm_after_update_entry'
	global $frm_vars;
	if ( isset( $frm_vars['action_check'] ) ) {
		unset( $frm_vars['action_check'][ $atts['action']->ID ] );
	}
	
	// error_log( "Skip because {$message} - entry: {$atts['entry']->id} action: {$atts['action']->post_name} {$atts['action']->post_title}"  );// for debugging
	
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

	// error_log( "Processing: {$atts['action']->post_name}"  );// for debugging

	if ( $event !== 'update' ) {
		error_log("skip because this wasnt an update... probably shouldnt happen!!");
		return "skip because this wasnt an update.";
	}

	if ( ! is_object( $entry ) ) {// apparently $entry can be integer or object!
		$entry = \FrmEntry::getOne( $entry, true );
		$atts['entry'] = $entry;
	}
	
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
		
		// error_log( 'just updated via ajax');
		// error_log( '$changed_setting:  ' . print_r( $changed_setting, true ) );
		// error_log( '$_POST:  ' . print_r( $_POST, true ) );
		// error_log( '$prev_value_cond:  ' . print_r( $prev_value_cond, true ) );
		
		// changed field setting needs to only have one field and match the field in the shortcode
		if ( count( $changed_setting ) !== 1 || $changed_setting[0] !== $_POST['field_id'] ) {
			
			return skip( "this was an single-field AJAX update, and it wasnt the right field or all the fields required.", $atts );
		
		} elseif ( $prev_value_cond && !empty( $prev_value_cond[ $changed_setting[0] ] ) ) {// OK, the correct field was changed, is there a conditional?
			
			if ( $previous_values = $GLOBALS['ajk_frm_just_updated'] ) {
				
				if ( $cond_result = check_conditionals( $prev_value_cond, $previous_values ) ) {
					return skip( $cond_result, $atts );// failed a conditional
				}
				
			} else {
				return skip( "the cache returned nothing so we dont know!", $atts );
			}
		}
		
	} elseif ( $previous_values = $GLOBALS['ajk_frm_just_updated'] ) {// normal, full, non-AJAX entry update
		
		// error_log( 'didnt pass as an ajax request... $_POST:  ' . print_r( $_POST, true ) );
		
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
		return skip( "cache returned nothing so we dont know!", $atts );
	}
	// error_log("not skipped");
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
	return false;// conditions passed, dont skip
}

// cache old values before update
function cache_old_values($values, $id) {

	// see if this is even a form that uses the just-upated functionality, via stored array of form IDs (ids are the array keys; all values are 1)
	$forms = get_option('frm_forms_using_just_updated_option', array());
	if ( empty( $forms[ (int) $values['form_id'] ] ) ) return $values;

	// if ( wp_cache_get( 'frm_changed_fields' ) ) error_log("already had frm_changed_fields cache ??");

	// get ID => value array of old values
	global $wpdb;
	$field_ids = $wpdb->get_col( "SELECT field_id, meta_value FROM {$wpdb->prefix}frm_item_metas WHERE item_id={$id}" );
	$meta_values = $wpdb->get_col( null , 1 );
	$meta = array_combine( $field_ids, $meta_values );

	// wp_cache_set( 'frm_changed_fields', $meta );

	$GLOBALS['ajk_frm_just_updated'] = $meta;

	return $values;
}
// different caching function for the AJAX, single-field, 1-click update buttons, since the other hook isnt fired
function cache_old_value_ajax() {

	if ( empty( $_POST['entry_id'] ) || empty( $_POST['field_id'] ) ) {
		error_log('couldnt get $_POST[\'entry_id\'] or $_POST[\'entry_id\'] during cache_old_value_ajax in formidable-just-updated.php');
		return;
	}

	// need to get form id from entry id or field id... is it really faster?
	// if ( $field = \FrmField::getOne( $_POST['field_id'] ) ) {
	// 	$form_id = $field->form_id;
	// 	// see if this is even a form that uses the just-upated functionality, via stored array of form IDs (ids are the array keys; all values are 1)
	// 	$forms = get_option('frm_forms_using_just_updated_option', array());
	// 	if ( empty( $forms[ (int) $form_id ] ) ) {
	// 		return;
	// 	}
	// }
	
	$old_value = \FrmEntryMeta::get_entry_meta_by_field( (int) $_POST['entry_id'], (int) $_POST['field_id'] );
	
	// wp_cache_set( 'frm_changed_fields', array( $_POST['field_id'] => $old_value ) );

	$GLOBALS['ajk_frm_just_updated'] = [ $_POST['field_id'] => $old_value ];

}


/**
 * store an array of forms which actually use an action with the "just updated" functionality
 * this allows me to not waste db call caching the old values on every form submit
 * even though the hook is called "before update" it seems to get the correct new values.
 * 'frm_update_form' runs later but it runs also on "build" tab saves add_action('frm_update_form', function($form_id, $value){}, 10, 2);
 */

function options_update( $options, $values ){

	$form_id = (int) $values['id'];

	$forms = get_option('frm_forms_using_just_updated_option', array());

	if ( ! $forms ) {// initialize the option and pupulate it with all forms using this feature
		global $wpdb;
		$results = $wpdb->get_col("SELECT DISTINCT menu_order FROM {$wpdb->prefix}posts WHERE post_type = 'frm_form_actions' AND post_status = 'publish'
		 AND post_content LIKE '%just_changed_fields%' AND post_content NOT LIKE '%\"just_changed_fields\":\"\"%'");
		foreach ( $results as $f ) { $forms[$f] = 1; }
	}

	// get actions from the formidable cache as done in FrmFormAction::get_action_for_form() see https://github.com/Strategy11/formidable-forms/blob/37326483db3b83148e4836e33803f2bb6b04c174/classes/models/FrmFormAction.php#L456
	$args = \FrmFormAction::action_args( $form_id );// 2nd arg is limit
	$actions = \FrmDb::check_cache( json_encode( $args ), 'frm_actions', $args, 'get_posts' );
	$in_use = false;
	foreach( $actions as $a ) {
		$content = json_decode($a->post_content);
		if ( !empty( $content->just_changed_fields ) ) {
			$in_use = true;
			break;
		}
	}

	if ( $in_use ) {
		$forms[ $form_id ] = 1;
	} else {
		unset( $forms[ $form_id ] );
	}

	update_option( 'frm_forms_using_just_updated_option', $forms );

	return $options;
}


// add field to the form action settings
function add_settings( $form_action, $atts = array() ) {	

	$setting = !empty( $form_action->post_content['just_changed_fields'] ) ? $form_action->post_content['just_changed_fields'] : '';

	echo '<p><label>Only if all of these fields were just changed: </label> <input type="text" id="'
		 . esc_attr( $atts['action_control']->get_field_id('just_changed_fields') ) . '" value="' . esc_attr( $setting )
		 . '" name="' . esc_attr( $atts['action_control']->get_field_name('just_changed_fields') ) . '" placeholder="comma-seperated IDs"></p>';

}