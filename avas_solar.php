<?php
/**
*Plugin Name: Avas Solar WebApp
*Description: Avas Solar Web Application for Control of Home Power
*Version: 2023021200
*Author: Madhu Avasarala
*Author URI: http://sritoni.org
*Text Domain: avas_solar_webapp
*Domain Path:
*/
defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

// define a unique constant to check inside of config
define('AVAS_SOLAR_MYCONSTANT', TRUE);

define('AVAS_SOLAR_VERSION', '2.0');

// load the class. Loading also initializes the class static functions
require_once( __DIR__. "/class_avas_solar.php" ); 
// no need to instantiate the class 

$user_readings_array = [];

// add action for scheduled cron task
add_action ( 'shellystuder_task_hook', array( 'class_avas_solar', 'shellystuder_cron_exec' ) );

// wait for all plugins to be loaded before initializing our code
add_action('plugins_loaded', 'this_plugin_init');

// add action for the ajax handler on server side for user prompted burst of 5 x 10s updates
// the 1st argument is in update.js, action: "get_studer_readings"
// the 2nd argument is the local callback function as the ajax handler
add_action('wp_ajax_my_solar_update',       array( 'class_avas_solar', 'ajax_my_solar_update_handler' ) );

// This is action for Ajax handler for updating screen using data from minutely cron readings
add_action('wp_ajax_my_solar_cron_update',  array( 'class_avas_solar', 'ajax_my_solar_cron_update_handler' ) );


add_filter( 'cron_schedules',  'shelly_studer_add_new_cron_interval' );

if (!wp_next_scheduled('shellystuder_task_hook')) 
{
    wp_schedule_event( time(), 'sixty_seconds', 'shellystuder_task_hook' );
}


/**
 * 
 */
function shelly_studer_add_new_cron_interval( $schedules ) 
{ 
    $schedules['sixty_seconds'] = array(
                                    'interval' => 1*60,
                                    'display'  => esc_html__( 'Every 60 seconds' ),
                                    );
    return $schedules;
}


/**
 *  Instantiate the main class that the plugin uses
 *  Setup webhook to be cauught when Order is COmpleted on the WooCommerce site.
 *  Setup wp-cron schedule and eveent for hourly checking to see if SriToni Moodle Accounts have been created
 *  Setup wp-cron schedule and event for hourly checking to see if user has replied to ticket with payment UTR
 */
function this_plugin_init()
{
    // add_action('init','custom_login');
    // add action to load the javascripts on non-admin page
    add_action( 'wp_enqueue_scripts', 'add_my_scripts' );

    // no initialization needed, this is done in the class itlsef afer loading
    // run the static initialization function of the main class. Loads the config and sets constants
    // class_avas_solar::init();
}


/**
*   register and enque jquery scripts with nonce for ajax calls. Load only for desired page
*   called by add_action( 'wp_enqueue_scripts', 'add_my_scripts' );
*/
function add_my_scripts($hook)
// register and enque jquery scripts wit nonce for ajax calls
{
    // if not the intended page then return and do nothing.
    if ( ! is_page( 'mysolar' ) ) return;

    // https://developer.wordpress.org/plugins/javascript/enqueuing/
    //wp_register_script($handle            , $src                                 , $deps         , $ver, $in_footer)
    wp_register_script('my_solar_app_script', plugins_url('update.js', __FILE__), array('jquery'),'1.0', true);

    wp_enqueue_script('my_solar_app_script');

    $my_solar_app_nonce = wp_create_nonce('my_solar_app_script');
    // note the key here is the global my_ajax_obj that will be referenced by our Jquery in update.js
    //  wp_localize_script( string $handle,       string $object_name, associative array )
    wp_localize_script('my_solar_app_script', 'my_ajax_obj', array(
                                                                   'ajax_url' => admin_url( 'admin-ajax.php' ),
                                                                   'nonce'    => $my_solar_app_nonce,
                                                                   'wp_user_ID' => wp_get_current_user()->ID,
                                                                   )
                      );
}


