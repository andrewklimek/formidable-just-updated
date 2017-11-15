<?php
namespace ajk_frm_just_updated;
/*
Plugin Name: Formidable “Just Updated” Trigger
Description: Trigger an action only when specified fields were JUST updated
Version:     0.2
Author:      Andrew J Klimek
Author URI:  
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
add_filter( 'frm_pre_update_entry', __NAMESPACE__ .'\compare_values_for_updates', 10, 2 );
// add_action( 'frm_after_update_field', __NAMESPACE__ .'\ajax_field_add_global', 0, 1 );
// add_action( 'frm_after_update_field', __NAMESPACE__ .'\ajax_field_run_update', 9999999, 1 );


// Taken from formidable's autoresponder so I can stop them sabotaging the "Only when just updated" functionality
function remove_action_from_global( $atts ) {
    global $frm_vars;
    if ( isset( $frm_vars['action_check'] ) && isset( $frm_vars['action_check'][ $atts['action']->ID ] ) ) {
        unset( $frm_vars['action_check'][ $atts['action']->ID ] );
    }
}


/* function ajax_field_add_global( $atts ){
    define( 'JUST_UPDATED_BY_AJAX', $atts['field']->id );
} */

// disabled... i think we should leave it up to users to add this snippet
function ajax_field_run_update( $atts ){

    if ( did_action( 'frm_after_update_entry' ) === 0 ) {// This is a common snippet, so we're checking if it's been done already
        poo("running do_action from “Just updated” plugin");

        do_action( 'frm_after_update_entry', $atts['entry_id'], $atts['field']->form_id );
    }
}


function intercept( $skip, $atts ) {

    if ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) {// TODO Test this
        return $skip;
    }

    extract( $atts );// $action (obj), $entry (int or obj), $form (obj), $event (str)

//     poo($event);

    if ( empty( $action->post_content['just_changed_fields'] ) ) {
        return $skip;
    }

    if ( $event !== 'update' ) {// does formidable's autoresponder ignore this?
//         poo("skip because this wasn’t an update.");
        return "skip because this wasn’t an update.";
    }

    $changed_setting = explode( ',', preg_replace( '/[^,\d]/', '', $action->post_content['just_changed_fields'] ) );

    global $wpdb;

    if ( ! is_object( $entry ) ) {// apparently $entry can be integer or object!
        $entry = \FrmEntry::getOne( $entry, true );
    }

    // was this triggered by an "update field" shortcode [frm-entry-update-field]
    if ( isset( $_POST['action'] ) && $_POST['action'] === 'frm_entries_update_field_ajax' ) {
        // changed field setting needs to only have one field and match the field in the shortcode
        if ( count( $changed_setting ) !== 1 || $changed_setting[0] !== $_POST['field_id'] ) {
            remove_action_from_global( $atts );
            $skip = "skip because this was an single-field AJAX update, and it wasn’t the right field or all the fields required.";
        }
    } elseif ( $previous_values = wp_cache_get( 'frm_changed_fields' ) ) {

        $field_ids = $wpdb->get_col( "SELECT field_id, meta_value FROM {$wpdb->prefix}frm_item_metas WHERE item_id={$entry->id}", 0 );
        $meta_values = $wpdb->get_col( "SELECT field_id, meta_value FROM {$wpdb->prefix}frm_item_metas WHERE item_id={$entry->id}", 1 );
        $meta = array_combine( $field_ids, $meta_values );
//         poo($meta, '$meta');
//         poo($previous_values, '$previous_values');
        $changed = array_diff_assoc( $meta, $previous_values ) + array_diff_assoc( $previous_values, $meta );
//         poo($changed, '$changed');
        $changed_setting = array_flip( $changed_setting );// flip field IDs from value to key
        // $matches = array_intersect_key( $changed_setting, $previous_values );// Any... would return true if (!$matches)
        $matches = array_diff_key( $changed_setting, $changed );// All. Removes all fields from the required fields which are found in the changed fields
        if ( $matches ) {// any required fields left without a match?
//             poo($matches, '$matches');
            remove_action_from_global( $atts );
            $skip = "skip because some of the required fields were not changed.";
        }
    } else {
        remove_action_from_global( $atts );
        $skip = "skip because either there were no changes or the cache returned nothing so we don’t know!";
    }

//     poo($skip);

    return $skip;
}

function add_settings( $form_action, $atts = array() ) {	

    $setting = !empty( $form_action->post_content['just_changed_fields'] ) ? $form_action->post_content['just_changed_fields'] : '';

?>
<p><label>Only if all of these fields were just changed: </label> <input type="text" id="<?php echo esc_attr( $atts['action_control']->get_field_id('just_changed_fields') ) ?>" value="<?php echo esc_attr( $setting ) ?>" name="<?php echo esc_attr( $atts['action_control']->get_field_name('just_changed_fields') ) ?>"></p>
<?php
}



function compare_values_for_updates($values, $id) {
    global $wpdb;

    $field_ids = $wpdb->get_col( "SELECT field_id, meta_value FROM {$wpdb->prefix}frm_item_metas WHERE item_id={$id}" );
    $meta_values = $wpdb->get_col( null , 1 );// returns the second column from cached results of last query (meta_value)
    $meta = array_combine( $field_ids, $meta_values );

    wp_cache_set( 'frm_changed_fields', $meta );

    return $values;
}


if(!function_exists('poo')){function poo($v,$l=''){if(WP_DEBUG_LOG){error_log("***$l***\n".var_export($v,true));}}}