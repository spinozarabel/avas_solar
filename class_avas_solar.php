<?php

  if ( ( ! defined( 'ABSPATH' ) ) || ( ! defined( 'AVAS_SOLAR_MYCONSTANT' ) ) ) 
  {
    exit; // Exit if accessed directly!
  }
/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 * Ver 2.0
 */

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @since      1.0.0
 * @author     Madhu Avasarala
 */

require_once(__DIR__."/studer_api.php");              // contains studer api class
require_once(__DIR__."/shelly_cloud_api.php");        // contains Shelly Cloud API class
require_once(__DIR__."/class_solar_calculation.php"); // contains studer api class
require_once(__DIR__."/openweather_api.php");         // contains openweather class

class class_avas_solar
{
	// The loader that's responsible for maintaining and registering all hooks that power
	protected $loader;

	// The unique identifier of this plugin.
	public static $plugin_name;

	// The current version of the plugin.
	public static $version;

  //
  public static $config;

  public static $verbose;

  public static $lat, $lon, $utc_offset, $timezone;

  public $bv_avg_arr;
  public $psolar_avg_arr;
  public $pload_avg;
  public $count_for_averaging;
  public $counter;
  public $datetime;

  public $valid_shelly_config;

  public $do_soc_cal_now_arr;


    /**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks for the admin area and
	 * the public-facing side of the site.
	 */
	

    /**
     *  Sets the constants and actions and loads the configuration
     */
    public static function init()
    {
      if ( defined( 'AVAS_SOLAR_VERSION' ) )
      {
          self::$version = AVAS_SOLAR_VERSION;
      }
      else
      {
          self::$version = '2.0';
      }

      self::$plugin_name = 'avas_solar';

      // load actions only if admin
      if (is_admin()) self::define_admin_hooks();

      // load public facing actions
      self::define_public_hooks();

      self::$config = self::get_config();

      // set the logging
      self::$verbose = false;

      // lat and lon at Trans Indus from Google Maps
      self::$lat        = 12.83463;
      self::$lon        = 77.49814;
      self::$utc_offset = 5.5;

      self::$timezone = new DateTimeZone("Asia/Kolkata");

      date_default_timezone_set("Asia/Kolkata");

      self::manage_transient_cloudiness_forecast();
    }

    /**
     * 
     */
    public static function manage_transient_cloudiness_forecast()
    {
      date_default_timezone_set("Asia/Kolkata");

      if ( self::nowIsWithinTimeLimits("05:00", "06:00") )
      {   // Get the weather forecast if time is between 5 to 6 in the morning.
        self::$cloudiness_forecast = self::check_if_forecast_is_cloudy();

        // write the weatehr forecast to a transient valid for 24h
        set_transient( 'cloudiness_forecast', self::$cloudiness_forecast, 24*60*60 );
      }
      else  
      {   // Read the transient for the weatehr forecast that has already been read between 5 and 6 AM
        if ( false === get_transient( 'cloudiness_forecast' ) )
        {
          // Transient does not exist or has expired, so regenerate the cloud forecast
          self::$cloudiness_forecast = self::check_if_forecast_is_cloudy();

          // write the weatehr forecast to a transient valid for 24h
          set_transient( 'cloudiness_forecast', self::$cloudiness_forecast, 24*60*60 );
        }
        else
        {
          self::$cloudiness_forecast = get_transient( 'cloudiness_forecast' );
        }
      }
    }

    /**
     *  reads in a config php file and gets the API secrets. The file has to be in gitignore and protected
     *  The information is read into an associative arrray automatically by the nature of the process
     *  1. Key and Secret of Payment Gateway involved needed to ccheck/create VA and read payments
     *  2. Moodle token to access Moodle Webservices
     *  3. Woocommerce Key and Secret for Woocommerce API on payment server
     *  4. Webhook secret for order completed, from payment server
     */
    public static function get_config()
    {
      $config = include( __DIR__."/" . self::$plugin_name . "_config.php");

      return $config;
    }

    /**
     * Define all of the admin facing hooks and filters required for this plugin
     * @return null
     */
    private static function define_admin_hooks()
    {   // create a sub menu called Admissions in the Tools menu
        add_action('admin_menu', array( __CLASS__, 'add_my_menu' ) );
    }

    /**
     * 
     * Define all of the public facing hooks and filters required for this plugin
     * @return null
     */
    private static function define_public_hooks()
    {
        // register shortcode for pages. This is for showing the page with studer readings
        add_shortcode( 'transindus-studer-readings',  array( __CLASS__, 'studer_readings_page_render' ) );

        // Action to process submitted data from a Ninja Form.
        add_action( 'ninja_forms_after_submission',   array( __CLASS__, 'my_ninja_forms_after_submission' ) );

        // This is the page that displays the Individual Studer with All powers, voltages, currents, and SOC% and Shelly Status
        add_shortcode( 'my-studer-readings',          array( __CLASS__, 'my_studer_readings_page_render' ) );

        // Define shortcode to prepare for my-studer-settings page
        add_shortcode( 'my-studer-settings',          array( __CLASS__, 'my_studer_settings' ) );
    }

    /**
     *  This shoercode checks the user meta for studer settings to see if they are set.
     *  If not set the user meta are set using defaults.
     *  When the Ninja Forms opens it uses the user meta. If a user meta was not set, now it will be, with programmed defaults
     */
    public static function my_studer_settings()
    {
      $defaults     = [];   // Initialize the defaults array
      $current_user = wp_get_current_user();
      $wp_user_ID   = $current_user->ID;

      if ( empty(self::$user_meta_defaults_arr) || in_array(null, self::$user_meta_defaults_arr, true) )
      {

        $defaults['soc_percentage_lvds_setting']                      = ['default' => 30,   'lower_limit' =>10,   'upper_limit' =>90];  // lower Limit of SOC for LVDS
        $defaults['battery_voltage_avg_lvds_setting']                 = ['default' => 48.3, 'lower_limit' =>47,   'upper_limit' =>54];  // lower limit of BV for LVDS
        $defaults['soc_percentage_rdbc_setting']                      = ['default' => 85,   'lower_limit' =>30,   'upper_limit' =>90];  // upper limit of SOC for RDBC activation
        $defaults['soh_percentage_setting']                           = ['default' => 100,  'lower_limit' =>0,    'upper_limit' =>100]; // Current SOH of battery
        $defaults['soc_percentage_switch_release_setting']            = ['default' => 95,   'lower_limit' =>90,   'upper_limit' =>100]; // Upper limit of SOC for switch release
        $defaults['min_soc_percentage_for_switch_release_after_rdbc'] = ['default' => 32,   'lower_limit' =>20,   'upper_limit' =>90];  // Lower limit of SOC for switch release after RDBC
        $defaults['min_solar_surplus_for_switch_release_after_rdbc']  = ['default' => 0.2,  'lower_limit' =>0,    'upper_limit' =>4];   // Lower limit of Psurplus for switch release after RDBC
        $defaults['battery_voltage_avg_float_setting']                = ['default' => 51.9, 'lower_limit' =>50.5, 'upper_limit' =>54];  // Upper limit of BV for SOC clamp/recal takes place
        $defaults['acin_min_voltage_for_rdbc']                        = ['default' => 199,  'lower_limit' =>190,  'upper_limit' =>210]; // Lower limit of ACIN for RDBC
        $defaults['acin_max_voltage_for_rdbc']                        = ['default' => 241,  'lower_limit' =>230,  'upper_limit' =>250]; // Upper limit of ACIN for RDBC
        $defaults['psolar_surplus_for_rdbc_setting']                  = ['default' => -0.5, 'lower_limit' =>-4,   'upper_limit' =>0];   // Lower limit of Psurplus for surplus for RDBC
        $defaults['psolar_min_for_rdbc_setting']                      = ['default' => 0.3,  'lower_limit' =>0.1,  'upper_limit' =>4];   // lower limit of Psolar for RDBC activation
        $defaults['do_minutely_updates']                              = ['default' => true,  'lower_limit' =>true,  'upper_limit' =>true];
        $defaults['do_shelly']                                        = ['default' => false,  'lower_limit' =>true,  'upper_limit' =>true];
        $defaults['keep_shelly_switch_closed_always']                 = ['default' => false,  'lower_limit' =>true,  'upper_limit' =>true];

        // save the data in a transient indexed by the user ID. Expiration is 30 minutes
        set_transient( $wp_user_ID . 'user_meta_defaults_arr', $defaults, 30*60 );

        foreach ($defaults as $user_meta_key => $default_row) {
          $user_meta_value  = get_user_meta($wp_user_ID, $user_meta_key,  true);
  
          if ( empty( $user_meta_value ) ) {
            update_user_meta( $wp_user_ID, $user_meta_key, $default_row['default']);
          }
        }
      }
      add_action( 'nf_get_form_id', function( $form_id )
      {

        // Check for a specific Form ID.
        if( 2 !== $form_id ) return;
      
        /**
         * Change a field's settings when localized to the page.
         *   ninja_forms_localize_field_{$field_type}
         *
         * @param array $field [ id, settings => [ type, key, label, etc. ] ]
         * @return array $field
         */
        add_filter( 'ninja_forms_localize_field_checkbox', function( $field )
        {
          $wp_user_ID = get_current_user_id();

          switch ( true )
            {
              case ( stripos( $field[ 'settings' ][ 'key' ], 'keep_shelly_switch_closed_always' )!== false ):
                // get the user's metadata for this flag
                $user_meta_value = get_user_meta($wp_user_ID, 'keep_shelly_switch_closed_always',  true);

                // Change the `default_value` setting of the checkbox field based on the retrieved user meta
                if ($user_meta_value == true)
                {
                  $field[ 'settings' ][ 'default_value' ] = 'checked';
                }
                else
                {
                  $field[ 'settings' ][ 'default_value' ] = 'unchecked';
                }
              break;

              case ( stripos( $field[ 'settings' ][ 'key' ], 'do_minutely_updates' )!== false ):
                // get the user's metadata for this flag
                $user_meta_value = get_user_meta($wp_user_ID, 'do_minutely_updates',  true);

                // Change the `default_value` setting of the checkbox field based on the retrieved user meta
                if ($user_meta_value == true)
                {
                  $field[ 'settings' ][ 'default_value' ] = 'checked';
                }
                else
                {
                  $field[ 'settings' ][ 'default_value' ] = 'unchecked';
                }
              break;

              case ( stripos( $field[ 'settings' ][ 'key' ], 'do_shelly' )!== false ):
                // get the user's metadata for this flag
                $user_meta_value = get_user_meta($wp_user_ID, 'do_shelly',  true);

                // Change the `default_value` setting of the checkbox field based on the retrieved user meta
                if ($user_meta_value == true)
                {
                  $field[ 'settings' ][ 'default_value' ] = 'checked';
                }
                else
                {
                  $field[ 'settings' ][ 'default_value' ] = 'unchecked';
                }
              break;

              case ( stripos( $field[ 'settings' ][ 'key' ], 'do_soc_cal_now' )!== false ):
                // get the user's metadata for this flag
                $user_meta_value = get_user_meta($wp_user_ID, 'do_soc_cal_now',  true);

                // Change the `default_value` setting of the checkbox field based on the retrieved user meta
                if ($user_meta_value == true)
                {
                  $field[ 'settings' ][ 'default_value' ] = 'checked';
                }
                else
                {
                  $field[ 'settings' ][ 'default_value' ] = 'unchecked';
                }
              break;
            }
          return $field;
        } );  // Add filter to check for checkbox field and set the default using user meta
      } );    // Add Action to check form ID
      
    }


    /**
     * 
     */
    public static function get_user_index_of_logged_in_user()
    {  // get my user index knowing my login name

        $current_user = wp_get_current_user();
        $wp_user_name = $current_user->user_login;

        $config       = self::$config;  // this does not change with users

        // Now to find the index in the config array using the above
        $user_index = array_search( $wp_user_name, array_column($config['accounts'], 'wp_user_name')) ;

        self::$user_index   = $user_index;
        self::$wp_user_name = $wp_user_name;
        self::$wp_user_obj  = $current_user;

        return $user_index;
    }

    /**
     * 
     */
    public static function get_index_from_wp_user_ID( int $wp_user_ID ): int
    {
        $wp_user_object = get_user_by( 'id', $wp_user_ID);

        $wp_user_name   = $wp_user_object->user_login;

        $config         = self::get_config();

        // Now to find the index in the config array using the above
        $user_index     = array_search( $wp_user_name, array_column($config['accounts'], 'wp_user_name')) ;

        return $user_index;
    }


    /**
     * @param int:user_index
     * @return object:wp_user_obj
     */
    public static function get_wp_user_from_user_index( int $user_index) : ?object
    {
        $config       = self::get_config();
        $wp_user_name = $config['accounts'][$user_index]['wp_user_name'];

        // Get the wp user object given the above username
        $wp_user_obj  = get_user_by('login', $wp_user_name);

        return $wp_user_obj;
    }

    /**
     * 
     */
    public static function add_my_menu()
    {
        // add submenu page for testing various application API needed
        add_submenu_page(
            'tools.php',	                    // parent slug
            'My API Tools',                     // page title
            'My API Tools',	                    // menu title
            'manage_options',	                // capability
            'my-api-tools',	                    // menu slug
            array( __CLASS__, 'my_api_tools_render' )
        );
    }


    /**
     *  This function is called by the scheduler  every minute or so.
     *  Its job is to get the needed set of studer readings and the state of the ACIN shelly switch
     *  For every user in the config array who has the do_shelly variable set to TRUE.
     *  The ACIN switch is turned ON or OFF based on a complex algorithm. and user meta settings
     *  A data object is created and stored as a transient to be accessed by an AJAX request running asynchronously to the CRON
     */
    public static function shellystuder_cron_exec()
    {                        // Loop over all of the eligible users
        $config = self::get_config();

        // rerun the class init since there are some time dependednt state changes possible
        self::init();

        foreach ($config['accounts'] as $user_index => $account)
        {
            $wp_user_name = $account['wp_user_name'];

                            // Get the wp user object given the above username
            $wp_user_obj          = get_user_by('login', $wp_user_name);

            if ( empty($wp_user_obj) ) continue;

            $wp_user_ID           = $wp_user_obj->ID;

                            // extract the control flag as set in user meta
            $do_shelly_user_meta  = get_user_meta($wp_user_ID, "do_shelly", true) ?? false;

            // extract the control flag as set in user meta
            $do_minutely_updates  = get_user_meta($wp_user_ID, "do_minutely_updates", true) ?? false;

            // Check if the control flag for minutely updates is TRUE. If so get the readings
            if( $do_minutely_updates ) {

                // get all the readings for this user. This will write the data to a transient for quick retrieval
                self::get_readings_and_servo_grid_switch( $user_index, $wp_user_ID, $wp_user_name, $do_shelly_user_meta );
            }
            // loop for all users
        }

        return true;
    }



    /**
     * Gets all readings from Shelly and Studer and servo's AC IN shelly switch based on conditions
     * @param int:user_index
     * @param int:wp_user_ID
     * @param string:wp_user_name
     * @param bool:do_shelly_user_meta
     * @return object:studer_readings_obj
     */
    public static function get_readings_and_servo_grid_switch( int $user_index, int $wp_user_ID, string $wp_user_name, bool $do_shelly_user_meta) : ?object
    {
        $config         = self::get_config();

        $valid_shelly_config  = ! empty( $config['accounts'][$user_index]['shelly_device_id_acin']   )  &&
                                ! empty( $config['accounts'][$user_index]['shelly_device_id_homepwr'] ) &&
                                ! empty( $config['accounts'][$user_index]['shelly_server_uri']  )       &&
                                ! empty( $config['accounts'][$user_index]['shelly_auth_key']    );
        // Get limits from user meta
        // SOC percentage needed to trigger LVDS
        $soc_percentage_lvds_setting            = get_user_meta($wp_user_ID, "soc_percentage_lvds_setting",  true) ?? 30;

        // SOH of battery currently. 
        $soh_percentage_setting                 = get_user_meta($wp_user_ID, "soh_percentage_setting",  true) ?? 100;

        // Avg Battery Voltage lower threshold for LVDS triggers
        $battery_voltage_avg_lvds_setting       = get_user_meta($wp_user_ID, "battery_voltage_avg_lvds_setting",  true) ?? 48.3;

        // RDBC active only if SOC is below this percentage level.
        $soc_percentage_rdbc_setting            = get_user_meta($wp_user_ID, "soc_percentage_rdbc_setting",  true) ?? 80.0;

        // Switch releases if SOC is above this level 
        $soc_percentage_switch_release_setting  = get_user_meta($wp_user_ID, "soc_percentage_switch_release_setting",  true) ?? 95.0; 

        // SOC needs to be higher than this to allow switch release after RDBC
        $min_soc_percentage_for_switch_release_after_rdbc 
                                      = get_user_meta($wp_user_ID, "min_soc_percentage_for_switch_release_after_rdbc",  true) ?? 32;

        // min KW of Surplus Solar to release switch after RDBC
        $min_solar_surplus_for_switch_release_after_rdbc 
                                      = get_user_meta($wp_user_ID, "min_solar_surplus_for_switch_release_after_rdbc",  true) ?? 0.2; 

        // battery float voltage setting. Only used for SOC clamp for 100%
        $battery_voltage_avg_float_setting  = get_user_meta($wp_user_ID, "battery_voltage_avg_float_setting",  true) ?? 51.9; 

        // Min VOltage at ACIN for RDBC to switch to GRID
        $acin_min_voltage_for_rdbc          = get_user_meta($wp_user_ID, "acin_min_voltage_for_rdbc",  true) ?? 199;  

        // Max voltage at ACIN for RDBC to switch to GRID
        $acin_max_voltage_for_rdbc          = get_user_meta($wp_user_ID, "acin_max_voltage_for_rdbc",  true) ?? 241; 

        // KW of deficit after which RDBC activates to GRID. Usually a -ve number
        $psolar_surplus_for_rdbc_setting    = get_user_meta($wp_user_ID, "psolar_surplus_for_rdbc_setting",  true) ?? -0.5;  

        // Minimum Psolar before RDBC can be actiated
        $psolar_min_for_rdbc_setting        = get_user_meta($wp_user_ID, "psolar_min_for_rdbc_setting",  true) ?? 0.3;  

        // get operation flags from user meta. Set it to false if not set
        $keep_shelly_switch_closed_always = get_user_meta($wp_user_ID, "keep_shelly_switch_closed_always",  true) ?? false;

        if( $do_shelly_user_meta && $valid_shelly_config) {  // Cotrol Shelly TRUE if usermeta AND valid config

            $control_shelly = true;
        }
        else {    // Cotrol Shelly FALSE if usermeta AND valid config FALSE
          $control_shelly = false;
        }

        // get the current ACIN Shelly Switch Status. This returns null if not a valid response or device offline
        if ( $valid_shelly_config ) {   //  get shelly device status ONLY if valid config for switch

            $shelly_api_device_response = self::get_shelly_device_status_acin( $user_index );

            if ( is_null($shelly_api_device_response) ) { // switch status is unknown

                error_log("Shelly cloud not responding and or device is offline");

                $shelly_api_device_status_ON = null;

                $shelly_switch_status             = "OFFLINE";
                $shelly_api_device_status_voltage = "NA";
            }
            else {  // Switch is ONLINE - Get its status and Voltage
                
                $shelly_api_device_status_ON      = $shelly_api_device_response->data->device_status->{"switch:0"}->output;
                $shelly_api_device_status_voltage = $shelly_api_device_response->data->device_status->{"switch:0"}->voltage;

                if ($shelly_api_device_status_ON)
                    {
                        $shelly_switch_status = "ON";
                    }
                else
                    {
                        $shelly_switch_status = "OFF";
                    }
            }
        }
        else {  // no valid configuration for shelly switch set variables for logging info

            $shelly_api_device_status_ON = null;

            $shelly_switch_status             = "Not Configured";
            $shelly_api_device_status_voltage = "NA";    
        }

        // Check if it is just after midnight. If so, capture the SOC and the energy values and timestep from Shelly Pro 4PM
        if ( self::nowIsWithinTimeLimits("00:00", "00:05") )
        {
        
          // it is just after midnight
          // check if transient for the time step exists and it is today
          if (  true === get_transient( $wp_user_name . 'timestamp_00' )  && 
                true === get_transient( $wp_user_name . 'energy_00' )     && 
                true === self::is_timestamp_todays( get_transient( $wp_user_name . 'timestamp_00' ) )
              )
          {
            // transient exists and it is todays's
            $timestamp_00 = get_transient( $wp_user_name . 'timestamp_00' ); 
            $energy_00    = get_transient( $wp_user_name . 'energy_00' );
          }
          else
          {
            // Valid transient's for midnight energy and timestamp don;t exist, read them in freshly
            $timestamp_00 = self::get_shelly_device_status_homepwr( $user_index )->minute_ts;

            // set the transient for 24h
            set_transient( $wp_user_name . 'timestamp_00', $timestamp_00, 24*60*60 );

            // read the energy counter at midnight. This is just some running counter of WHs
            $energy_00 = self::get_shelly_device_status_homepwr( $user_index )->energy_total_to_home_ts;

            // set the transient for 24h
            set_transient( $wp_user_name . 'energy_00', $energy_00, 24*60*60 );

            // Get the SOC at present as calculated using Studer values, as saved in user meta
            $soc_just_after_midnight = get_user_meta($wp_user_ID, "soc_percentage_now",  true) ?? 60.0;

            // set transient for 24h
            set_transient( $wp_user_name . 'soc_just_after_midnight', $soc_just_after_midnight, 24*60*60 );
          }   
        }     // turn of day energy and timestep  from Pro 4PM device
        
        
        // this is dark still after midnight and transents exist calcultae SOC based on shelly 4PM energy readings
        if (  true === get_transient( $wp_user_name . 'timestamp_00' )                                && 
              true === get_transient( $wp_user_name . 'energy_00' )                                   && 
              true === self::is_timestamp_todays( get_transient( $wp_user_name . 'timestamp_00' ) )  &&
              true === self::nowIsWithinTimeLimits("00:00", "06:30")
            )
        {
          // transient exists and it is todays's and it is  after midnight and still dark so no solar to worry about.
          $timestamp_00 = get_transient( $wp_user_name . 'timestamp_00' ); 
          $energy_00    = get_transient( $wp_user_name . 'energy_00' );

          // Get the readings from the Shelly Pro 4 PM for energy, power, and timestamp
          $current_energy_counter_wh  = self::get_shelly_device_status_homepwr( $user_index )->energy_total_to_home_ts;
          $current_power_to_home_wh   = self::get_shelly_device_status_homepwr( $user_index )->power_total_to_home;
          $current_timestamp          = self::get_shelly_device_status_homepwr( $user_index )->minute_ts;
          
          $energy_consumed_since_midnight_kwh = ( $current_energy_counter_wh - $energy_00 ) * 0.001;

        }

        

        

        // get the smaller set of Studer readings
        $studer_readings_obj  = self::get_studer_min_readings($user_index);

        $soc_computed_using_shellypro4pm = self::soc_computed_using_shellypro4pm();

        // check for valid studer values. Return if not valid
        if( empty(  $studer_readings_obj )                          ||
            empty(  $studer_readings_obj->battery_voltage_vdc )     ||
                    $studer_readings_obj->battery_voltage_vdc < 40  ||
            empty(  $studer_readings_obj->pout_inverter_ac_kw ) ) 
        {
            // cannot trust this Studer reading, do not update
            error_log($wp_user_name . ": " . "Could not get Studer Reading");

            return null;
        }

        // Load the voltage array that might have been pushed into transient space
        $bv_arr_transient = get_transient( $wp_user_name . '_bv_avg_arr' );

        if ( ! is_array($bv_arr_transient))
        {
          $bv_avg_arr = [];
        }
        else
        {
          $bv_avg_arr = $bv_arr_transient;
        }
        
        // push the new voltage to the holding array
        array_push($bv_avg_arr, $studer_readings_obj->battery_voltage_vdc);

        // If the array has more than 3 elements then drop the earliest one
        // We are averaging for only 3 minutes
        if ( sizeof($bv_avg_arr) > 3 )  {   // drop the earliest reading
            array_shift($bv_avg_arr);
        }
        // Write it to this object for access elsewhere easily
        self::$bv_avg_arr = $bv_avg_arr;

        // Setup transiet to keep previous state for averaging
        set_transient( $wp_user_name . '_bv_avg_arr', $bv_avg_arr, 5*60 );

        // average the battery voltage over last 6 readings of about 6 minutes.
        $battery_voltage_avg  = self::get_battery_voltage_avg();

        // get the estimated solar power from calculations for a clear day
        $est_solar_kw         = self::estimated_solar_power($user_index);

        // Solar power Now
        $psolar               = $studer_readings_obj->psolar_kw;

        // Check if it is cloudy AT THE MOMENT. Yes if solar is less than half of estimate
        $it_is_cloudy_at_the_moment = $psolar <= 0.5 * array_sum($est_solar_kw);

        // Solar Current into Battery Junction at present moment
        $solar_pv_adc         = $studer_readings_obj->solar_pv_adc;

        // Inverter readings at present Instant
        $pout_inverter        = $studer_readings_obj->pout_inverter_ac_kw;    // Inverter Output Power in KW
        $grid_input_vac       = $studer_readings_obj->grid_input_vac;         // Grid Input AC Voltage to Studer
        $inverter_current_adc = $studer_readings_obj->inverter_current_adc;   // DC current into Inverter to convert to AC power

        // Surplus power from Solar after supplying the Load
        $surplus              = $psolar - $pout_inverter;

        $aux1_relay_state     = $studer_readings_obj->aux1_relay_state;

        // Boolean values for checking is present time is within defined time intervals
        $now_is_daytime       = self::nowIsWithinTimeLimits("08:30", "16:30"); // changed from 17:30  on 7/28/22
        $now_is_sunset        = self::nowIsWithinTimeLimits("16:31", "16:41");

        // Boolean Variable to designate it is a cloudy day. This is derived from a free external API service
        $it_is_a_cloudy_day   = self::$cloudiness_forecast->it_is_a_cloudy_day_weighted_average;

        // Weighted percentage cloudiness
        $cloudiness_average_percentage_weighted = round(self::$cloudiness_forecast->cloudiness_average_percentage_weighted, 0);

        // Get the SOC percentage at beginning of Dayfrom the user meta. This gets updated only at beginning of day, once.
        $SOC_percentage_beg_of_day       = get_user_meta($wp_user_ID, "soc_percentage",  true) ?? 50;

        // get the installed battery capacity in KWH from config
        $SOC_capacity_KWH     = self::$config['accounts'][$user_index]['battery_capacity'];

        // get the current Measurement values from the Stider Readings Object
        $KWH_solar_today      = $studer_readings_obj->KWH_solar_today;  // Net SOlar Units generated Today
        $KWH_grid_today       = $studer_readings_obj->KWH_grid_today;   // Net Grid Units consumed Today
        $KWH_load_today       = $studer_readings_obj->KWH_load_today;   // Net Load units consumed Today

        // Units of Solar Energy converted to percentage of Battery Capacity Installed
        $KWH_solar_percentage_today = round( $KWH_solar_today / $SOC_capacity_KWH * 100, 1);

        // Battery discharge today in terms of SOC capacity percventage
        $KWH_batt_percent_discharged_today = round( $studer_readings_obj->KWH_batt_discharged_today / $SOC_capacity_KWH * 100, 1);

        if (true)
        {

            error_log("username: "             . $wp_user_name . ' Switch: ' . $shelly_switch_status . ' ' . 
                                                 $battery_voltage_avg . ' V, ' . $studer_readings_obj->battery_charge_adc . 'A ' .
                                                 $shelly_api_device_status_voltage . ' VAC');
            error_log("Psolar_calc: " . array_sum($est_solar_kw) . " Psolar_act: " . $psolar . " - Psurplus: " . 
                       $surplus . " KW - Is it a Cloudy Day?: " . $it_is_a_cloudy_day);
        
            // error_log("Solar Units Today: "    . $KWH_solar_today                          . "KWH");
            // error_log("Grid Units Today: "     . $KWH_grid_today                           . "KWH");
            // error_log("Load Units Today: "     . $KWH_load_today                           . "KWH");
        }

        // get the SOC % from the previous reading from user meta
        $SOC_percentage_previous = get_user_meta($wp_user_ID, "soc_percentage_now",  true) ?? 50.0;

        // Net battery charge in KWH (discharge if minus)
        $KWH_batt_charge_net_today  = $KWH_solar_today * 0.96 + (0.988 * $KWH_grid_today - $KWH_load_today) * 1.07;

        $batt_disc_percentage_calc_from_load = (0.988 * $KWH_grid_today - $KWH_load_today) * 1.10;
        $batt_disc_percentage_calc_from_load = round( $batt_disc_percentage_calc_from_load / $SOC_capacity_KWH * 100, 1);

        // Calculate in percentage of  installed battery capacity
        $SOC_batt_charge_net_percent_today = round( $KWH_batt_charge_net_today / $SOC_capacity_KWH * 100, 1);

        //  Update SOC  number
        $SOC_percentage_now = $SOC_percentage_beg_of_day + $SOC_batt_charge_net_percent_today;

        // set a clamp if the update is bad
        if ( $SOC_percentage_now < 25 ) {
          error_log("SOC now bad update: " .  $SOC_percentage_now . " %");
          $SOC_percentage_now = 25;
        }

        // Check to see if new day accounting has begun. Check for reset of Solar and Load units reset to 0
        // 
        if (  ( $KWH_solar_today <= 0.01 )                                &&    // Solar has been reset to 0
              ( $KWH_load_today  <= 1 )                                   &&
              ( abs($SOC_percentage_previous - $SOC_percentage_now) > 4 ) &&    // if difference is small we don't care
              ( self::nowIsWithinTimeLimits("00:00", "00:59") || 
                self::nowIsWithinTimeLimits("23:00", "23:59:59") )          )    {

          // Since new day accounting has begun, update user meta for SOC at beginning of new day
          // This update only happens at beginning of day and also during battery float
          update_user_meta( $wp_user_ID, 'soc_percentage', $SOC_percentage_previous);

          error_log("SOC new day rollover activated: " . $SOC_percentage_previous  . " %");

          // since the battery nett charge for the new day is 0, SOC now is same as SOC previous
          $SOC_percentage_now = $SOC_percentage_previous;
        }
        
        // Update user meta so this becomes the previous value for next cycle
        update_user_meta( $wp_user_ID, 'soc_percentage_now', $SOC_percentage_now);

        // update the object and user meta
        $studer_readings_obj->SOC_percentage_now = $SOC_percentage_now;

        if (true)
        {
          error_log("S%: " . $KWH_solar_percentage_today . " Dis.%: " . $KWH_batt_percent_discharged_today . 
                    " SOC_0: " . $SOC_percentage_beg_of_day . "%, SOC Now: " . $SOC_percentage_now . " %" );
          error_log('Battery Dis %: ' . $batt_disc_percentage_calc_from_load . ' %');
        }
        

        // define all the conditions for the SWITCH - CASE tree

        // AC input voltage is being sensed by Studer even though switch status is OFF meaning manual MCB before Studer is ON
        // In this case, since grid is manually switched ON there is nothing we can do
        $switch_override =  ($shelly_switch_status == "OFF")               &&
                            ($studer_readings_obj->grid_input_vac >= 190);

        // Independent of Servo Control Flag  - Switch Grid ON due to Low SOC - Don't care about Grid Voltage     
        $LVDS =             ( $battery_voltage_avg  <= $battery_voltage_avg_lvds_setting || 
                              $SOC_percentage_now   <= $soc_percentage_lvds_setting           )  &&
                            ( $shelly_switch_status == "OFF" );					  // The switch is OFF

        // Keep Grid Switch CLosed Untless Solar charges Battery to $soc_percentage_switch_release_setting - 5 or say 90%
        // So between this and switch_release_float_state battery may cycle up and down by 5 points
        // Ofcourse if the Psurplus is too much it will charge battery to 100% inspite of this.
        // Obviously after sunset the battery will remain at 90% till sunrise the next day
        $keep_switch_closed_always =  ( $shelly_switch_status == "OFF" )             &&
                                      ( $keep_shelly_switch_closed_always == true )  &&
                                      ( $SOC_percentage_now <= ($soc_percentage_switch_release_setting - 5) )	&&  // OR SOC reached 90%
                                      ( $control_shelly == true );


        $reduce_daytime_battery_cycling = ( $shelly_switch_status == "OFF" )              &&  // Switch is OFF
                                          ( $SOC_percentage_now <= $soc_percentage_rdbc_setting )	&&	// Battery NOT in FLOAT state
                                          ( $shelly_api_device_status_voltage >= $acin_min_voltage_for_rdbc	)	&&	// ensure Grid AC is not too low
                                          ( $shelly_api_device_status_voltage <= $acin_max_voltage_for_rdbc	)	&&	// ensure Grid AC is not too high
                                          ( $now_is_daytime )                             &&   // Now is Daytime
                                          ( $psolar  >= $psolar_min_for_rdbc_setting )    &&   // at least some solar generation
                                          ( $surplus <= $psolar_surplus_for_rdbc_setting ) &&  // Solar Deficit is negative
                                          ( $it_is_cloudy_at_the_moment )                 &&   // Only when it is cloudy
                                          ( $control_shelly == true );                         // Control Flag is SET
        // switch release typically after RDBC when Psurplus is positive.
        $switch_release =  ( $SOC_percentage_now >= ( $soc_percentage_lvds_setting + 0.3 ) ) &&  // SOC ?= LBDS + offset
                           ( $shelly_switch_status == "ON" )  														  &&  // Switch is ON now
                           ( $surplus >= $min_solar_surplus_for_switch_release_after_rdbc ) &&  // Solar surplus is >= 0.2KW
                           ( $keep_shelly_switch_closed_always == false )                   &&	// Emergency flag is False
                           ( $control_shelly == true );                                         // Control Flag is SET                              

        // In general we want home to be on Battery after sunset
        $sunset_switch_release			=	( $keep_shelly_switch_closed_always == false )  &&  // Emergency flag is False
                                      ( $shelly_switch_status == "ON" )               &&  // Switch is ON now
                                      ( $now_is_sunset )                              &&  // around sunset
                                      ( $control_shelly == true );

        // This is needed when RDBC or always ON was triggered and Psolar is charging battery beyond 95%
        // independent of keep_shelly_switch_closed_always flag status
        $switch_release_float_state	= ( $shelly_switch_status == "ON" )  							&&  // Switch is ON now
                                      ( $SOC_percentage_now >= $soc_percentage_switch_release_setting )	&&  // OR SOC reached 95%
                                      // ( $keep_shelly_switch_closed_always == false )  &&  // Always ON flag is OFF
                                      ( $control_shelly == true );                        // Control Flag is False

        // write back new values to the readings object
        $studer_readings_obj->battery_voltage_avg               = $battery_voltage_avg;
        $studer_readings_obj->now_is_daytime                    = $now_is_daytime;
        $studer_readings_obj->now_is_sunset                     = $now_is_sunset;
        $studer_readings_obj->shelly_api_device_status_ON       = $shelly_api_device_status_ON;
        $studer_readings_obj->shelly_api_device_status_voltage  = $shelly_api_device_status_voltage;

        $studer_readings_obj->LVDS                              = $LVDS;
        $studer_readings_obj->reduce_daytime_battery_cycling    = $reduce_daytime_battery_cycling;
        $studer_readings_obj->switch_release                    = $switch_release;
        $studer_readings_obj->sunset_switch_release             = $sunset_switch_release;
        $studer_readings_obj->switch_release_float_state        = $switch_release_float_state;
        $studer_readings_obj->control_shelly                    = $control_shelly;

        $studer_readings_obj->cloudiness_average_percentage_weighted  = $cloudiness_average_percentage_weighted;
        $studer_readings_obj->est_solar_kw  = round( array_sum($est_solar_kw), 1);
        

        switch(true)
        {
            // if Shelly switch is OPEN but Studer transfer relay is closed and Studer AC voltage is present
            // it means that the ACIN is manually overridden at control panel
            // so ignore attempting any control and skip this user
            
            case (  $switch_override ):
                  // No action possible since manual override of ACIN switch so exit
                  error_log("ACIN Switch Manual Override)");
                  $cron_exit_condition = "ACIN switch Manual Override";
            break;
            


            // <1> If switch is OPEN AND running average Battery voltage from 5 readings is lower than limit
            //      AND control_shelly = TRUE. Note that a valid config and do_shelly user meta need to be TRUE.
            case ( $LVDS ):

                self::turn_on_off_shelly_switch($user_index, "on");

                error_log("LVDS - Grid ON.  SOC: " . $SOC_percentage_now . " % and Vbatt(V): " . $battery_voltage_avg);
                $cron_exit_condition = "Low SOC - Grid ON";
            break;


            // <3> If switch is OPEN and the keep shelly closed always is TRUE then close the switch
            case ( $keep_switch_closed_always ):

                self::turn_on_off_shelly_switch($user_index, "on");

                error_log("keep_switch_closed_always - Grid ON");
                $cron_exit_condition = "keep_switch_closed_always";
            break;


            // <4> Daytime, reduce battery cycling, turn SWITCH ON
            case ( $reduce_daytime_battery_cycling ):

                self::turn_on_off_shelly_switch($user_index, "on");

                error_log("RDBC - Grid ON");
                $cron_exit_condition = "RDBC-Grid ON";
            break;


            // <5> Release - Switch OFF for normal Studer operation
            case ( $switch_release ):

                self::turn_on_off_shelly_switch($user_index, "off");

                error_log("SOC ok-Grid Off");
                $cron_exit_condition = "SOC ok-Grid Off";
            break;


            // <6> Turn switch OFF at 5:30 PM if emergency flag is False so that battery can supply load for the night
            case ( $sunset_switch_release ):

                self::turn_on_off_shelly_switch($user_index, "off");

                error_log("Sunset-Grid Off");
                $cron_exit_condition = "Sunset-Grid Off";
            break;


            case ( $switch_release_float_state ):

                self::turn_on_off_shelly_switch($user_index, "off");

                error_log("SOC Float-Grid Off");
                $cron_exit_condition = "SOC Float-Grid Off";
            break;


            default:
                
                error_log("NO ACTION");
                $cron_exit_condition = "No Action";
            break;

        }   // end witch statement

        $now = new DateTime();

        $array_for_json = [ 'unixdatetime'        => $now->getTimestamp() ,
                            'cron_exit_condition' => $cron_exit_condition ,
                          ];

        // save the data in a transient indexed by the user name. Expiration is 5 minutes
        set_transient( $wp_user_name . '_studer_readings_object', $studer_readings_obj, 5*60 );

        // Update the user meta with the CRON exit condition only fir definite ACtion not for no action
        if ($cron_exit_condition !== "No Action") 
          {
              update_user_meta( $wp_user_ID, 'studer_readings_object',  json_encode( $array_for_json ));
          }

        if (  $SOC_percentage_now > 100.0 || $battery_voltage_avg  >=  $battery_voltage_avg_float_setting )
          {
            // Since we know that the battery SOC is 100% use this knowledge along with
            // Energy data to recalibrate the soc_percentage user meta
            $SOC_percentage_beg_of_day_recal = 100 - $SOC_batt_charge_net_percent_today;

            update_user_meta( $wp_user_ID, 'soc_percentage', $SOC_percentage_beg_of_day_recal);

            error_log("SOC 100% clamp activated: " . $SOC_percentage_beg_of_day_recal  . " %");
          }
        

        return $studer_readings_obj;
    }

    /**
     *  @param int:timestamp_00
     *  @return bool
     */
    public function is_timestamp_todays( int $timestamp_00 ):bool
    {
      date_default_timezone_set("Asia/Kolkata");

      // get datetime object with today but time set to midnight
      $today = new DateTime("today");

      // get a new datetime objectt
      $date_from_ts = new DateTime();

      // set its timestamp based on  passed in Unix timestamp
      $date_from_ts->setTimestamp($timestamp_00);

      // set the time part to 0 to ensure comparing only days
      $date_from_ts->setTime( 0, 0, 0 ); // set time part to midnight

      // form the intervel object
      $diff = $today->diff( $date_from_ts );

      // extract days only from the interval object
      $diffDays = (integer)$diff->format( "%R%a" ); // Extract days count in interval

      if ( $diffDays === 0 )
      {
        return true;
      }
      else
      {
        return false;
      }
    }

    /**
     *  Ninja form data is checked for proper limits.
     *  If data is changed the corresponding user meta is updated to trhe new form data.
     */
    public function my_ninja_forms_after_submission( $form_data )
    {
      if ( 2 != $form_data['form_id'] ) 
      {
        error_log("returning from post submission due to form id not matching");
        return; // we don;t casre about any form except form with id=2
      }

      $wp_user_ID = get_current_user_id();

      $do_soc_cal_now = false;    // initialize variable

      if (false !== get_transient( $wp_user_ID . 'user_meta_defaults_arr'))
      {
        // Valid transient aretrieved so proceed to use it
        $defaults_arr = get_transient( $wp_user_ID . 'user_meta_defaults_arr');
      }
      else
      {
        // transient does not exist so exit so abort
        error_log("Could not retrieve transient data for defaults array for settings, aborting without user meta updates");
        return;
      }

      $defaults_arr_keys    = array_keys($defaults_arr);       // get all the keys in numerically indexed array
      
      $defaults_arr_values  = array_values($defaults_arr);    // get all the rows in a numerically indexed array
      

      foreach( $form_data[ 'fields' ] as $field ): 

        switch ( true ):
        

          case ( stripos( $field[ 'key' ], 'keep_shelly_switch_closed_always' ) !== false ):
            if ( $field[ 'value' ] )
            {
              $submitted_field_value = true;
            }
            else 
            {
              $submitted_field_value = false;
            }

            // get the existing user meta value
            $existing_user_meta_value = get_user_meta($wp_user_ID, "keep_shelly_switch_closed_always",  true);

            if ( $existing_user_meta_value != $submitted_field_value )
            {
              // update the user meta with value from form since it is different from existing setting
              update_user_meta( $wp_user_ID, 'keep_shelly_switch_closed_always', $submitted_field_value);

              error_log( "Updated User Meta - keep_shelly_switch_closed_always - from Settings Form: " . $field[ 'value' ] );
            }
          break;



          case ( stripos( $field[ 'key' ], 'do_minutely_updates' ) !== false ):
            if ( $field[ 'value' ] )
            {
              $submitted_field_value = true;
            }
            else 
            {
              $submitted_field_value = false;
            }

            // get the existing user meta value
            $existing_user_meta_value = get_user_meta($wp_user_ID, "do_minutely_updates",  true);

            if ( $existing_user_meta_value != $submitted_field_value )
            {
              // update the user meta with value from form since it is different from existing setting
              update_user_meta( $wp_user_ID, 'do_minutely_updates', $submitted_field_value);

              error_log( "Updated User Meta - do_minutely_updates - from Settings Form: " . $field[ 'value' ] );
            }
          break;



          case ( stripos( $field[ 'key' ], 'do_shelly' ) !== false ):
            if ( $field[ 'value' ] )
            {
              $submitted_field_value = true;
            }
            else 
            {
              $submitted_field_value = false;
            }

            // get the existing user meta value
            $existing_user_meta_value = get_user_meta($wp_user_ID, "do_shelly",  true);

            if ( $existing_user_meta_value != $submitted_field_value )
            {
              // update the user meta with value from form since it is different from existing setting
              update_user_meta( $wp_user_ID, 'do_shelly', $submitted_field_value);

              error_log( "Updated User Meta - do_shelly - from Settings Form: " . $field[ 'value' ] );
            }
          break;



          case ( stripos( $field[ 'key' ], 'do_soc_cal_now' ) !== false ):
            if ( $field[ 'value' ] )
            {
              $do_soc_cal_now = true;
            }
            else 
            {
              $do_soc_cal_now = false;
            }
            // Set the $this object for this flag
            $this->do_soc_cal_now_arr[$wp_user_ID] = $do_soc_cal_now;
          break;



          case ( stripos( $field[ 'key' ], 'soc_percentage_now' ) !== false ):
            $defaults_key = array_search('soc_percentage_now', $defaults_arr_keys); // get the index of desired row in array
            $defaults_row = $defaults_arr_values[$defaults_key];

            if ( $field[ 'value' ] >= $defaults_row['lower_limit'] && $field[ 'value' ] <= $defaults_row['upper_limit'] )
            {
              $soc_percentage_now_for_cal = $field[ 'value' ];
            }
          break;



          case ( stripos( $field[ 'key' ], 'battery_voltage_avg_lvds_setting' ) !== false ):

            // define the meta key of interest
            $user_meta_key = 'battery_voltage_avg_lvds_setting';

            // look for the defaults using the user meta key
            $defaults_key = array_search($user_meta_key, $defaults_arr_keys); // get the index of desired row in defaults array
            $defaults_row = $defaults_arr_values[$defaults_key];
            // validate user input
            if ( $field[ 'value' ] >= $defaults_row['lower_limit'] && $field[ 'value' ] <= $defaults_row['upper_limit'] )
            {
              // get the existing user meta value
              $existing_user_meta_value = get_user_meta($wp_user_ID, $user_meta_key,  true);

              // update the user meta with this value if different from existing value only
              if ($existing_user_meta_value != $field[ 'value' ])
              {
                update_user_meta( $wp_user_ID, $user_meta_key, $field[ 'value' ] );
                error_log( "Updated User Meta - " . $user_meta_key . " - from Settings Form: " . $field[ 'value' ] );
              }
            }
            else
            {
              error_log( "Updated User Meta - " . $user_meta_key . " - NOT Updated - invalid input: " . $field[ 'value' ] );
            }
          break;


          
          case ( stripos( $field[ 'key' ], 'soc_percentage_lvds_setting' ) !== false ):

            // define the meta key of interest
            $user_meta_key = 'soc_percentage_lvds_setting';

            // look for the defaults using the user meta key
            $defaults_key = array_search($user_meta_key, $defaults_arr_keys); // get the index of desired row in defaults array
            $defaults_row = $defaults_arr_values[$defaults_key];
            // validate user input
            if ( $field[ 'value' ] >= $defaults_row['lower_limit'] && $field[ 'value' ] <= $defaults_row['upper_limit'] )
            {
              // get the existing user meta value
              $existing_user_meta_value = get_user_meta($wp_user_ID, $user_meta_key,  true);

              // update the user meta with this value if different from existing value only
              if ($existing_user_meta_value != $field[ 'value' ])
              {
                update_user_meta( $wp_user_ID, $user_meta_key, $field[ 'value' ] );
                error_log( "Updated User Meta - " . $user_meta_key . " - from Settings Form: " . $field[ 'value' ] );
              }
            }
            else
            {
              error_log( "Updated User Meta - " . $user_meta_key . " - NOT Updated - invalid input: " . $field[ 'value' ] );
            }
          break;



          case ( stripos( $field[ 'key' ], 'soh_percentage_setting' ) !== false ):

            // define the meta key of interest
            $user_meta_key = 'soh_percentage_setting';

            // look for the defaults using the user meta key
            $defaults_key = array_search($user_meta_key, $defaults_arr_keys); // get the index of desired row in defaults array
            $defaults_row = $defaults_arr_values[$defaults_key];
            // validate user input
            if ( $field[ 'value' ] >= $defaults_row['lower_limit'] && $field[ 'value' ] <= $defaults_row['upper_limit'] )
            {
              // get the existing user meta value
              $existing_user_meta_value = get_user_meta($wp_user_ID, $user_meta_key,  true);

              // update the user meta with this value if different from existing value only
              if ($existing_user_meta_value != $field[ 'value' ])
              {
                update_user_meta( $wp_user_ID, $user_meta_key, $field[ 'value' ] );
                error_log( "Updated User Meta - " . $user_meta_key . " - from Settings Form: " . $field[ 'value' ] );
              }
            }
            else
            {
              error_log( "Updated User Meta - " . $user_meta_key . " - NOT Updated - invalid input: " . $field[ 'value' ] );
            }
          break;




          case ( stripos( $field[ 'key' ], 'soc_percentage_rdbc_setting' ) !== false ):

            // define the meta key of interest
            $user_meta_key = 'soc_percentage_rdbc_setting';

            // look for the defaults using the user meta key
            $defaults_key = array_search($user_meta_key, $defaults_arr_keys); // get the index of desired row in defaults array
            $defaults_row = $defaults_arr_values[$defaults_key];
            // validate user input
            if ( $field[ 'value' ] >= $defaults_row['lower_limit'] && $field[ 'value' ] <= $defaults_row['upper_limit'] )
            {
              // get the existing user meta value
              $existing_user_meta_value = get_user_meta($wp_user_ID, $user_meta_key,  true);

              // update the user meta with this value if different from existing value only
              if ($existing_user_meta_value != $field[ 'value' ])
              {
                update_user_meta( $wp_user_ID, $user_meta_key, $field[ 'value' ] );
                error_log( "Updated User Meta - " . $user_meta_key . " - from Settings Form: " . $field[ 'value' ] );
              }
            }
            else
            {
              error_log( "Updated User Meta - " . $user_meta_key . " - NOT Updated - invalid input: " . $field[ 'value' ] );
            }
          break;



          case ( stripos( $field[ 'key' ], 'soc_percentage_switch_release_setting' ) !== false ):

            // define the meta key of interest
            $user_meta_key = 'soc_percentage_switch_release_setting';

            // look for the defaults using the user meta key
            $defaults_key = array_search($user_meta_key, $defaults_arr_keys); // get the index of desired row in defaults array
            $defaults_row = $defaults_arr_values[$defaults_key];
            // validate user input
            if ( $field[ 'value' ] >= $defaults_row['lower_limit'] && $field[ 'value' ] <= $defaults_row['upper_limit'] )
            {
              // get the existing user meta value
              $existing_user_meta_value = get_user_meta($wp_user_ID, $user_meta_key,  true);

              // update the user meta with this value if different from existing value only
              if ($existing_user_meta_value != $field[ 'value' ])
              {
                update_user_meta( $wp_user_ID, $user_meta_key, $field[ 'value' ] );
                error_log( "Updated User Meta - " . $user_meta_key . " - from Settings Form: " . $field[ 'value' ] );
              }
            }
            else
            {
              error_log( "Updated User Meta - " . $user_meta_key . " - NOT Updated - invalid input: " . $field[ 'value' ] );
            }
          break;



          case ( stripos( $field[ 'key' ], 'min_soc_percentage_for_switch_release_after_rdbc' ) !== false ):

            // define the meta key of interest
            $user_meta_key = 'min_soc_percentage_for_switch_release_after_rdbc';

            // look for the defaults using the user meta key
            $defaults_key = array_search($user_meta_key, $defaults_arr_keys); // get the index of desired row in defaults array
            $defaults_row = $defaults_arr_values[$defaults_key];
            // validate user input
            if ( $field[ 'value' ] >= $defaults_row['lower_limit'] && $field[ 'value' ] <= $defaults_row['upper_limit'] )
            {
              // get the existing user meta value
              $existing_user_meta_value = get_user_meta($wp_user_ID, $user_meta_key,  true);

              // update the user meta with this value if different from existing value only
              if ($existing_user_meta_value != $field[ 'value' ])
              {
                update_user_meta( $wp_user_ID, $user_meta_key, $field[ 'value' ] );
                error_log( "Updated User Meta - " . $user_meta_key . " - from Settings Form: " . $field[ 'value' ] );
              }
            }
            else
            {
              error_log( "Updated User Meta - " . $user_meta_key . " - NOT Updated - invalid input: " . $field[ 'value' ] );
            }
          break;



          case ( stripos( $field[ 'key' ], 'min_solar_surplus_for_switch_release_after_rdbc' ) !== false ):

            // define the meta key of interest
            $user_meta_key = 'min_solar_surplus_for_switch_release_after_rdbc';

            // look for the defaults using the user meta key
            $defaults_key = array_search($user_meta_key, $defaults_arr_keys); // get the index of desired row in defaults array
            $defaults_row = $defaults_arr_values[$defaults_key];
            // validate user input
            if ( $field[ 'value' ] >= $defaults_row['lower_limit'] && $field[ 'value' ] <= $defaults_row['upper_limit'] )
            {
              // get the existing user meta value
              $existing_user_meta_value = get_user_meta($wp_user_ID, $user_meta_key,  true);

              // update the user meta with this value if different from existing value only
              if ($existing_user_meta_value != $field[ 'value' ])
              {
                update_user_meta( $wp_user_ID, $user_meta_key, $field[ 'value' ] );
                error_log( "Updated User Meta - " . $user_meta_key . " - from Settings Form: " . $field[ 'value' ] );
              }
            }
            else
            {
              error_log( "Updated User Meta - " . $user_meta_key . " - NOT Updated - invalid input: " . $field[ 'value' ] );
            }
          break;



          case ( stripos( $field[ 'key' ], 'battery_voltage_avg_float_setting' ) !== false ):

            // define the meta key of interest
            $user_meta_key = 'battery_voltage_avg_float_setting';

            // look for the defaults using the user meta key
            $defaults_key = array_search($user_meta_key, $defaults_arr_keys); // get the index of desired row in defaults array
            $defaults_row = $defaults_arr_values[$defaults_key];
            // validate user input
            if ( $field[ 'value' ] >= $defaults_row['lower_limit'] && $field[ 'value' ] <= $defaults_row['upper_limit'] )
            {
              // get the existing user meta value
              $existing_user_meta_value = get_user_meta($wp_user_ID, $user_meta_key,  true);

              // update the user meta with this value if different from existing value only
              if ($existing_user_meta_value != $field[ 'value' ])
              {
                update_user_meta( $wp_user_ID, $user_meta_key, $field[ 'value' ] );
                error_log( "Updated User Meta - " . $user_meta_key . " - from Settings Form: " . $field[ 'value' ] );
              }
            }
            else
            {
              error_log( "Updated User Meta - " . $user_meta_key . " - NOT Updated - invalid input: " . $field[ 'value' ] );
            }
          break;


          case ( stripos( $field[ 'key' ], 'acin_min_voltage_for_rdbc' ) !== false ):

            // define the meta key of interest
            $user_meta_key = 'acin_min_voltage_for_rdbc';

            // look for the defaults using the user meta key
            $defaults_key = array_search($user_meta_key, $defaults_arr_keys); // get the index of desired row in defaults array
            $defaults_row = $defaults_arr_values[$defaults_key];
            // validate user input
            if ( $field[ 'value' ] >= $defaults_row['lower_limit'] && $field[ 'value' ] <= $defaults_row['upper_limit'] )
            {
              // get the existing user meta value
              $existing_user_meta_value = get_user_meta($wp_user_ID, $user_meta_key,  true);

              // update the user meta with this value if different from existing value only
              if ($existing_user_meta_value != $field[ 'value' ])
              {
                update_user_meta( $wp_user_ID, $user_meta_key, $field[ 'value' ] );
                error_log( "Updated User Meta - " . $user_meta_key . " - from Settings Form: " . $field[ 'value' ] );
              }
            }
            else
            {
              error_log( "Updated User Meta - " . $user_meta_key . " - NOT Updated - invalid input: " . $field[ 'value' ] );
            }
          break;


          case ( stripos( $field[ 'key' ], 'acin_max_voltage_for_rdbc' ) !== false ):

            // define the meta key of interest
            $user_meta_key = 'acin_max_voltage_for_rdbc';

            // look for the defaults using the user meta key
            $defaults_key = array_search($user_meta_key, $defaults_arr_keys); // get the index of desired row in defaults array
            $defaults_row = $defaults_arr_values[$defaults_key];
            // validate user input
            if ( $field[ 'value' ] >= $defaults_row['lower_limit'] && $field[ 'value' ] <= $defaults_row['upper_limit'] )
            {
              // get the existing user meta value
              $existing_user_meta_value = get_user_meta($wp_user_ID, $user_meta_key,  true);

              // update the user meta with this value if different from existing value only
              if ($existing_user_meta_value != $field[ 'value' ])
              {
                update_user_meta( $wp_user_ID, $user_meta_key, $field[ 'value' ] );
                error_log( "Updated User Meta - " . $user_meta_key . " - from Settings Form: " . $field[ 'value' ] );
              }
            }
            else
            {
              error_log( "Updated User Meta - " . $user_meta_key . " - NOT Updated - invalid input: " . $field[ 'value' ] );
            }
          break;



          case ( stripos( $field[ 'key' ], 'psolar_surplus_for_rdbc_setting' ) !== false ):

            // define the meta key of interest
            $user_meta_key = 'psolar_surplus_for_rdbc_setting';

            // look for the defaults using the user meta key
            $defaults_key = array_search($user_meta_key, $defaults_arr_keys); // get the index of desired row in defaults array
            $defaults_row = $defaults_arr_values[$defaults_key];
            // validate user input
            if ( $field[ 'value' ] >= $defaults_row['lower_limit'] && $field[ 'value' ] <= $defaults_row['upper_limit'] )
            {
              // get the existing user meta value
              $existing_user_meta_value = get_user_meta($wp_user_ID, $user_meta_key,  true);

              // update the user meta with this value if different from existing value only
              if ($existing_user_meta_value != $field[ 'value' ])
              {
                update_user_meta( $wp_user_ID, $user_meta_key, $field[ 'value' ] );
                error_log( "Updated User Meta - " . $user_meta_key . " - from Settings Form: " . $field[ 'value' ] );
              }
            }
            else
            {
              error_log( "Updated User Meta - " . $user_meta_key . " - NOT Updated - invalid input: " . $field[ 'value' ] );
            }
          break;



          case ( stripos( $field[ 'key' ], 'psolar_min_for_rdbc_setting' ) !== false ):

            // define the meta key of interest
            $user_meta_key = 'psolar_min_for_rdbc_setting';

            // look for the defaults using the user meta key
            $defaults_key = array_search($user_meta_key, $defaults_arr_keys); // get the index of desired row in defaults array
            $defaults_row = $defaults_arr_values[$defaults_key];
            // validate user input
            if ( $field[ 'value' ] >= $defaults_row['lower_limit'] && $field[ 'value' ] <= $defaults_row['upper_limit'] )
            {
              // get the existing user meta value
              $existing_user_meta_value = get_user_meta($wp_user_ID, $user_meta_key,  true);

              // update the user meta with this value if different from existing value only
              if ($existing_user_meta_value != $field[ 'value' ])
              {
                update_user_meta( $wp_user_ID, $user_meta_key, $field[ 'value' ] );
                error_log( "Updated User Meta - " . $user_meta_key . " - from Settings Form: " . $field[ 'value' ] );
              }
            }
            else
            {
              error_log( "Updated User Meta - " . $user_meta_key . " - NOT Updated - invalid input: " . $field[ 'value' ] );
            }
          break;

        endswitch;       // end of switch

      endforeach;        // end of foreach

    } // end function

    /**
     *
     */
    public function get_current_cloud_cover_percentage()
    {
        $config = $this->config;
        $lat    = $this->lat;
        $lon    = $this->lon;
        $appid  = $config['appid'];

        $current_wether_api   = new openweathermap_api($lat, $lon, $appid);
        $current_weather_obj  = $current_wether_api->get_current_weather();

        if ($current_weather_obj)
        {
          $cloud_cover_percentage = $current_weather_obj->clouds->all;
          return $current_cloud_cover_percentage;
        }
        else
        {
          return null;
        }
    }

    /**
     *
     */
    public function check_if_forecast_is_cloudy()
    {
        $config = $this->config;
        $lat    = $this->lat;
        $lon    = $this->lon;
        $appid  = $config['openweather_appid'];
        $cnt    = 3;

        $current_wether_api   = new openweathermap_api($lat, $lon, $appid, $cnt);
        $cloudiness_forecast   = $current_wether_api->forecast_is_cloudy();

        return $cloudiness_forecast;
    }



    /**
     *  Takes the average of the battery values stored in the array, independent of its size
     */
    public static function get_battery_voltage_avg( array $bv_avg_arr ) : float
    {
        $count  = 0.00001;    // prevent division by 0 error
        $sum    = 0;
        foreach ($bv_avg_arr as $key => $bv_reading)
        {
           if ($bv_reading > 46.0)
           {
              // average all values that are real
              $sum    +=  $bv_reading;
              $count  +=  1;
           }
        }
        unset($bv_reading);   // for safety since in foreach loop

        return ( round( $sum / $count, 2) );
    }

    /**
     *  @param string:$start
     *  @param string:$stop
     *  @return bool true if current time is within the time limits specified otherwise false
     */
    public static function nowIsWithinTimeLimits(string $start_time, string $stop_time): bool
    {
        date_default_timezone_set("Asia/Kolkata");

        $now =  new DateTime();
        $begin = new DateTime($start_time);
        $end   = new DateTime($stop_time);

        if ($now >= $begin && $now <= $end)
        {
          return true;
        }
        else
        {
          return false;
        }
    }

    /**
     *
     */
    public function studer_sttings_page_render()
    {
        $output = '';

        $output .= '
        <style>
            table {
                border-collapse: collapse;
                }
                th, td {
                border: 1px solid orange;
                padding: 10px;
                text-align: left;
                }
                .rediconcolor {color:red;}
                .greeniconcolor {color:green;}
                .img-pow-genset { max-width: 59px; }
        </style>';
        $output .= '
        <table>
        <tr>
            <th>
              Parameter
            </th>';


        foreach ($$config['accounts'] as $user_index => $account)
        {
          $home = $account['home'];
          $output .=
            '<th>' . $home .
            '</th>';
        }
        unset($account);
        $output .=
        '</tr>';
        // Now we need to get all of the parameters of interest for each of the users and display them
        foreach ($$config['accounts'] as $user_index => $account)
        {
          $wp_user_name = $account['wp_user_name'];

        }

    }

    /**
     *  This function defined the shortcode to a page called mysolar that renders a user's solar system readings
     *  The HTML is created in a string variable and returned as is typical of a shortcode function
     */
    public static function my_studer_readings_page_render()
    {
        // initialize page HTML to be returned to be rendered by WordPress
        $output = '';

        $output .= '
        <style>
            @media (min-width: 768px) {
              .synoptic-table {
                  margin: auto;
                  width: 95% !important;
                  height: 100%;
                  border-collapse: collapse;
                  overflow-x: auto;
                  border-spacing: 0;
                  font-size: 1.5em;
              }
              .rediconcolor {color:red;}
              .greeniconcolor {color:green;}
              .clickableIcon {
                cursor: pointer
              .arrowSliding_nw_se {
                position: relative;
                -webkit-animation: slide_nw_se 2s linear infinite;
                        animation: slide_nw_se 2s linear infinite;
              }
        
              .arrowSliding_ne_sw {
                position: relative;
                -webkit-animation: slide_ne_sw 2s linear infinite;
                        animation: slide_ne_sw 2s linear infinite;
              }
        
              .arrowSliding_sw_ne {
                position: relative;
                -webkit-animation: slide_ne_sw 2s linear infinite reverse;
                        animation: slide_ne_sw 2s linear infinite reverse;
              }
        
              @-webkit-keyframes slide_ne_sw {
                  0% { opacity:0; transform: translate(20%, -20%); }
                  20% { opacity:1; transform: translate(10%, -10%); }
                  80% { opacity:1; transform: translate(-10%, 10%); }
                100% { opacity:0; transform: translate(-20%, 20%); }
              }
              @keyframes slide_ne_sw {
                  0% { opacity:0; transform: translate(20%, -20%); }
                  20% { opacity:1; transform: translate(10%, -10%); }
                  80% { opacity:1; transform: translate(-10%, 10%); }
                100% { opacity:0; transform: translate(-20%, 20%); }
              }
        
              @-webkit-keyframes slide_nw_se {
                  0% { opacity:0; transform: translate(-20%, -20%); }
                  20% { opacity:1; transform: translate(-10%, -10%); }
                  80% { opacity:1; transform: translate(10%, 10%);   }
                100% { opacity:0; transform: translate(20%, 20%);   }
              }
              @keyframes slide_nw_se {
                  0% { opacity:0; transform: translate(-20%, -20%); }
                  20% { opacity:1; transform: translate(-10%, -10%); }
                  80% { opacity:1; transform: translate(10%, 10%);   }
                100% { opacity:0; transform: translate(20%, 20%);   }
              }
           }
        </style>';

        // get my user index knowing my login name
        $current_user = wp_get_current_user();
        $wp_user_name = $current_user->user_login;
        $wp_user_ID   = $current_user->ID;

        $config       = self::$config;

        // Now to find the index in the config array using the above
        $user_index = array_search( $wp_user_name, array_column($config['accounts'], 'wp_user_name')) ;

        if ($user_index === false) return "User Index invalid, You DO NOT have a Studer Install";

        // extract the control flag as set in user meta
        $do_shelly_user_meta  = get_user_meta($wp_user_ID, "do_shelly", true) ?? false;

        // get the Studer status using the minimal set of readings
        $studer_readings_obj  = self::get_readings_and_servo_grid_switch($user_index, $wp_user_ID, $wp_user_name, $do_shelly_user_meta);

        // check for valid studer values. Return if not valid
        if( empty(  $studer_readings_obj ) ) {
                $output .= "Could not get a valid Studer Reading using API";
                return $output;
        }

        // get the format of all the information for the table in the page
        $format_object = self::prepare_data_for_mysolar_update( $wp_user_ID, $wp_user_name, $studer_readings_obj );

        // define all the icon styles and colors based on STuder and Switch values
        $output .= '
        <table id="my-studer-readings-table">
            <tr>
                <td id="grid_status_icon">'   . $format_object->grid_status_icon   . '</td>
                <td></td>
                <td id="shelly_servo_icon">'  . $format_object->shelly_servo_icon  . '</td>
                <td></td>
                <td id="pv_panel_icon">'      . $format_object->pv_panel_icon      . '</td>
            </tr>
                <td id="grid_info">'          . $format_object->grid_info          . '</td>
                <td id="grid_arrow_icon">'    . $format_object->grid_arrow_icon    . '</td>
                <td></td>
                <td id="pv_arrow_icon">'      . $format_object->pv_arrow_icon      . '</td>
                <td id="psolar_info">'        . $format_object->psolar_info        . '</td>

            <tr>
                <td></td>
                <td></td>
                <td id="studer_icon">'        . $format_object->studer_icon        . '</td>
                <td></td>
                <td></td>
            </tr>
            <tr>
                <td id="battery_info">'       . $format_object->battery_info       . '</td>
                <td id="battery_arrow_icon">' . $format_object->battery_arrow_icon . '</td>
                <td></td>
                <td id="load_arrow_icon">'    . $format_object->load_arrow_icon    . '</td>
                <td id="load_info">'          . $format_object->load_info          . '</td>
            </tr>
            <tr>
                <td id="battery_status_icon">'. $format_object->battery_status_icon     . '</td>
                <td></td>
                <td id="soc_percentage_now">'. $format_object->soc_percentage_now_html  . '</td>
                <td></td>
                <td id="load_icon">'          . $format_object->load_icon               . '</td>
            </tr>
            
        </table>';

        $output .= '<div id="cron_exit_condition">'. $format_object->cron_exit_condition     . '</div>';

        return $output;
    }


    /**
     *
     */
    public static function studer_readings_page_render()
    {
        // $script = '"' . $config['fontawesome_cdn'] . '"';
        //$output = '<script src="' . $config['fontawesome_cdn'] . '"></script>';
        $output = '';

        $output .= '
        <style>
            table {
                border-collapse: collapse;
                }
                th, td {
                border: 1px solid orange;
                padding: 10px;
                text-align: left;
                }
                .rediconcolor {color:red;}
                .greeniconcolor {color:green;}
                .img-pow-genset { max-width: 59px; }
        </style>';

        $output .= '
        <table>
        <tr>
            <th>
              Install
            </th>
            <th>
                <i class="fa-regular fa-2xl fa-calendar-minus"></i>
                <i class="fa-solid fa-2xl fa-solar-panel greeniconcolor"></i>
            </th>
            <th>
                <i class="fa-regular fa-2xl fa-calendar-minus"></i>
                <i class="fa-solid fa-2xl fa-plug-circle-check rediconcolor"></i>
            </th>
            <th><i class="fa-solid fa-2xl fa-charging-station"></i></th>
            <th><i class="fa-solid fa-2xl fa-solar-panel greeniconcolor"></i></th>
            <th><i class="fa-solid fa-2xl fa-house"></i></th>
        </tr>';

        // loop through all of the users in the config
        foreach (self::$config['accounts'] as $user_index => $account)
        {
            $home = $account['home'];

            $studer_readings_obj = self::get_studer_readings($user_index);

            if ($studer_readings_obj->grid_pin_ac_kw < 0.1)
            {
                $grid_staus_icon = '<i class="fa-solid fa-2xl fa-plug-circle-xmark greeniconcolor"></i>';
            }
            else
            {
                $grid_staus_icon = '<i class="fa-solid fa-2xl fa-plug-circle-check rediconcolor"></i>';
            }
            $solar_capacity         =   $account['solar_pk_install'];
            $battery_capacity       =   $account['battery_capacity'];
            $solar_yesterday        =   $studer_readings_obj->psolar_kw_yesterday;
            $grid_yesterday         =   $studer_readings_obj->energy_grid_yesterday;
            $consumed_yesterday     =   $studer_readings_obj->energy_consumed_yesterday;
            $battery_icon_class     =   $studer_readings_obj->battery_icon_class;
            $solar                  =   $studer_readings_obj->psolar_kw;
            $pout_inverter_ac_kw    =   $studer_readings_obj->pout_inverter_ac_kw;
            $battery_span_fontawesome = $studer_readings_obj->battery_span_fontawesome;
            $battery_voltage_vdc    =   round( $studer_readings_obj->battery_voltage_vdc, 1);

            $output .= self::print_row_table(  $home, $solar_capacity, $battery_capacity, $battery_voltage_vdc,
                                                $solar_yesterday, $grid_yesterday, $consumed_yesterday,
                                                $battery_span_fontawesome, $solar, $grid_staus_icon, $pout_inverter_ac_kw   );
        }
        $output .= '</table>';

        return $output;
    }

    public static function print_row_table( $home, $solar_capacity, $battery_capacity, $battery_voltage_vdc,
                                            $solar_yesterday, $grid_yesterday, $consumed_yesterday,
                                            $battery_span_fontawesome, $solar, $grid_staus_icon, $pout_inverter_ac_kw   )
    {
        $returnstring =
        '<tr>' .
            '<td>' . $home .                                            '</td>' .
            '<td>' . '<font color="green">' . $solar_yesterday .        '</td>' .
            '<td>' . '<font color="red">' .   $grid_yesterday .         '</td>' .
            '<td>' . $battery_span_fontawesome . $battery_voltage_vdc . '</td>' .
            '<td>' . '<font color="green">' . $solar .                  '</td>' .
            '<td>' . $grid_staus_icon .       $pout_inverter_ac_kw  .   '</td>' .
        '</tr>';
        return $returnstring;
    }

/**
 *  Function to test code conveniently.
 */
    public static function my_api_tools_render()
    {
        // this is for rendering the API test onto the sritoni_tools page
        ?>
            <h1> Input index of config and Click on desired button to test</h1>
            <form action="" method="post" id="mytoolsform">
                <input type="text"   id ="config_index" name="config_index"/>
                <input type="submit" name="button" 	value="Get_Studer_Readings"/>
                <input type="submit" name="button" 	value="Get_Shelly_Device_Status"/>
                <input type="submit" name="button" 	value="turn_Shelly_Switch_ON"/>
                <input type="submit" name="button" 	value="turn_Shelly_Switch_OFF"/>
                <input type="submit" name="button" 	value="run_cron_exec_once"/>
                <input type="submit" name="button" 	value="estimated_solar_power"/>
                <input type="submit" name="button" 	value="check_if_cloudy_day"/>
            </form>


        <?php

        $config_index = sanitize_text_field( $_POST['config_index'] );
        $button_text  = sanitize_text_field( $_POST['button'] );

        echo "<pre>" . "config_index: " .    $config_index . "</pre>";
        echo "<pre>" . "button: " .    $button_text . "</pre>";


        switch ($button_text)
        {
            case 'Get_Studer_Readings':

                // echo "<pre>" . print_r($config, true) . "</pre>";
                $studer_readings_obj = self::get_studer_readings($config_index);

                echo "<pre>" . "Studer Inverter Output (KW): " .    $studer_readings_obj->pout_inverter_ac_kw . "</pre>";
                echo "<pre>" . "Studer Solar Output(KW): " .        $studer_readings_obj->psolar_kw .           "</pre>";
                echo "<pre>" . "Battery Voltage (V): " .            $studer_readings_obj->battery_voltage_vdc . "</pre>";
                echo "<pre>" . "Solar Generated Yesterday (KWH): ". $studer_readings_obj->psolar_kw_yesterday . "</pre>";
                echo "<pre>" . "Battery Discharged Yesterday (KWH): ". $studer_readings_obj->energyout_battery_yesterday . "</pre>";
                echo "<pre>" . "Grid Energy In Yesterday (KWH): ".  $studer_readings_obj->energy_grid_yesterday . "</pre>";
                echo "<pre>" . "Energy Consumed Yesterday (KWH): ".  $studer_readings_obj->energy_consumed_yesterday . "</pre>";
                echo nl2br("/n");
            break;

            case "Get_Shelly_Device_Status":
                // Get the Shelly device status whose id is listed in the config.
                $shelly_api_device_response = self::get_shelly_device_status($config_index);
                $shelly_api_device_status_ON = $shelly_api_device_response->data->device_status;
            break;

            case "turn_Shelly_Switch_ON":
                // command the Shelly ACIN switch to ON
                $shelly_api_device_response = self::turn_on_off_shelly_switch($config_index, "on");
                sleep(1);

                // get a fresh status
                $shelly_api_device_response = self::get_shelly_device_status($config_index);
                $shelly_api_device_status_ON   = $shelly_api_device_response->data->device_status;
            break;

            case "turn_Shelly_Switch_OFF":
                // command the Shelly ACIN switch to ON
                $shelly_api_device_response = self::turn_on_off_shelly_switch($config_index, "off");
                sleep(1);

                // get a fresh status
                $shelly_api_device_response = self::get_shelly_device_status($config_index);
                $shelly_api_device_status_ON   = $shelly_api_device_response->data->device_status;
            break;

            case "run_cron_exec_once":
                self::$verbose = true;
                self::shellystuder_cron_exec();
                self::$verbose = false;
            break;

            case "estimated_solar_power":
              $est_solar_kw = self::estimated_solar_power($config_index);
              foreach ($est_solar_kw as $key => $value)
              {
                echo "<pre>" . "Est Solar Power, Clear Day (KW): " .    $value . "</pre>";
              }
              echo "<pre>" . "Total Est Solar Power Clear Day (KW): " .    array_sum($est_solar_kw) . "</pre>";
            break;

            case "check_if_cloudy_day":
              $cloudiness_forecast= self::check_if_forecast_is_cloudy();

              $it_is_a_cloudy_day = $cloudiness_forecast->it_is_a_cloudy_day;

              $cloud_cover_percentage = $cloudiness_forecast->cloudiness_average_percentage;

              echo "<pre>" . "Is it a cloudy day?: " .    $it_is_a_cloudy_day . "</pre>";
              echo "<pre>" . "Average CLoudiness percentage?: " .    $cloud_cover_percentage . "%</pre>";
            break;

        }
        if($shelly_api_device_status_ON->{"switch:0"}->output)
        {
            $switch_state = "Closed";
        }
        else
        {
          $switch_state = "Open";
        }
        if($shelly_api_device_status_ON->{"switch:0"}->output !== true)
        {
            $switch_state = "Open  and False";
        }
        else
        {
          $switch_state = "Closed  and True";
        }
        echo "<pre>" . "ACIN Shelly Switch State: " .    $switch_state . "</pre>";
        echo "<pre>" . "ACIN Shelly Switch Voltage: " .  $shelly_api_device_status_ON->{"switch:0"}->voltage . "</pre>";
        echo "<pre>" . "ACIN Shelly Switch Power: " .    $shelly_api_device_status_ON->{"switch:0"}->apower . "</pre>";
        echo "<pre>" . "ACIN Shelly Switch Current: " .  $shelly_api_device_status_ON->{"switch:0"}->current . "</pre>";
    }

    /**
     *
     */
    public static function estimated_solar_power($user_index)
    {
        $config = self::$config;
        $panel_sets = $config['accounts'][$user_index]['panels'];

        foreach ($panel_sets as $key => $panel_set)
        {
          // 5.5 is the UTC offset of 5h 30 mins in decimal.
          $transindus_lat_long_array = [self::$lat, self::$lon];
          $solar_calc = new solar_calculation($panel_set, $transindus_lat_long_array, self::$utc_offset);
          $est_solar_kw[$key] =  round($solar_calc->est_power(), 1);
        }

        return $est_solar_kw;
    }


    /**
     *
     */
    public static function turn_on_off_shelly_switch($user_index, $desired_state)
    {
        $config = self::$config;
        $shelly_server_uri  = $config['accounts'][$user_index]['shelly_server_uri'];
        $shelly_auth_key    = $config['accounts'][$user_index]['shelly_auth_key'];
        $shelly_device_id   = $config['accounts'][$user_index]['shelly_device_id'];

        $shelly_api    =  new shelly_cloud_api($shelly_auth_key, $shelly_server_uri, $shelly_device_id);

        // this is $curl_response
        $shelly_device_data = $shelly_api->turn_on_off_shelly_switch($desired_state);

        return $shelly_device_data;
    }

    public static function get_shelly_device_status(int $user_index): ?object
    {
        // get API and device ID from config based on user index
        $config = self::$config;
        $shelly_server_uri  = $config['accounts'][$user_index]['shelly_server_uri'];
        $shelly_auth_key    = $config['accounts'][$user_index]['shelly_auth_key'];
        $shelly_device_id   = $config['accounts'][$user_index]['shelly_device_id'];

        $shelly_api    =  new shelly_cloud_api($shelly_auth_key, $shelly_server_uri, $shelly_device_id);

        // this is $curl_response.
        $shelly_device_data = $shelly_api->get_shelly_device_status();

        return $shelly_device_data;
    }


    public static function get_shelly_device_status_acin(int $user_index): ?object
    {
        // get API and device ID from config based on user index
        $config = self::$config;
        $shelly_server_uri  = $config['accounts'][$user_index]['shelly_server_uri'];
        $shelly_auth_key    = $config['accounts'][$user_index]['shelly_auth_key'];
        $shelly_device_id   = $config['accounts'][$user_index]['shelly_device_id_acin'];

        $shelly_api    =  new shelly_cloud_api($shelly_auth_key, $shelly_server_uri, $shelly_device_id);

        // this is $curl_response.
        $shelly_device_data = $shelly_api->get_shelly_device_status();

        return $shelly_device_data;
    }


    public static function get_shelly_device_status_homepwr(int $user_index): ?object
    {
        // get API and device ID from config based on user index
        $config = self::$config;
        $shelly_server_uri  = $config['accounts'][$user_index]['shelly_server_uri'];
        $shelly_auth_key    = $config['accounts'][$user_index]['shelly_auth_key'];
        $shelly_device_id   = $config['accounts'][$user_index]['shelly_device_id_homepwr'];

        $shelly_api    =  new shelly_cloud_api($shelly_auth_key, $shelly_server_uri, $shelly_device_id);

        // this is $curl_response.
        $shelly_device_data = $shelly_api->get_shelly_device_status();

        // Since this is the switch that also measures the power and energy to home, let;s extract those details
        $power_channel_0 = $shelly_api_device_response->data->device_status->{"switch:0"}->apower;
        $power_channel_1 = $shelly_api_device_response->data->device_status->{"switch:1"}->apower;
        $power_channel_2 = $shelly_api_device_response->data->device_status->{"switch:2"}->apower;
        $power_channel_3 = $shelly_api_device_response->data->device_status->{"switch:3"}->apower;
        $power_total_to_home = $power_channel_0 + $power_channel_1 + $power_channel_2 + $power_channel_3;

        $energy_channel_0_ts = $shelly_api_device_response->data->device_status->{"switch:0"}->aenergy->total;
        $energy_channel_1_ts = $shelly_api_device_response->data->device_status->{"switch:1"}->aenergy->total;
        $energy_channel_2_ts = $shelly_api_device_response->data->device_status->{"switch:2"}->aenergy->total;
        $energy_channel_3_ts = $shelly_api_device_response->data->device_status->{"switch:3"}->aenergy->total;
        $energy_total_to_home_ts = $energy_channel_0_ts + $energy_channel_1_ts + $energy_channel_2_ts + $energy_channel_3_ts;

        // Unix minute time stamp for the power and energy readings
        $minute_ts = $shelly_api_device_response->data->device_status->{"switch:0"}->aenergy->minute_ts;

        // add these to returned object for later use in calling program
        $shelly_device_data->power_total_to_home      = $power_total_to_home;
        $shelly_device_data->energy_total_to_home_ts  = $energy_total_to_home_ts;
        $shelly_device_data->minute_ts                = $minute_ts;

        return $shelly_device_data;
    }

    /**
    ** This function returns an object that comprises data read form user's installtion
    *  @param int:$user_index  is the numeric index to denote a particular installtion
    *  @return object:$studer_readings_obj
    */
    public static function get_studer_readings(int $user_index): ?object
    {
        $config = self::$config;

        $Ra = 0.0;       // value of resistance from DC junction to Inverter
        $Rb = 0.025;       // value of resistance from DC junction to Battery terminals

        $base_url  = $config['studer_api_baseurl'];
        $uhash     = $config['accounts'][$user_index]['uhash'];
        $phash     = $config['accounts'][$user_index]['phash'];

        $studer_api = new studer_api($uhash, $phash, $base_url);

        $studer_readings_obj = new stdClass;

        $body = [];

        // get the input AC active power value
        $body = array(array(
                              "userRef"       =>  3136,   // AC active power delivered by inverter
                              "infoAssembly"  => "Master"
                           ),
                       array(
                               "userRef"       =>  3076,   // Energy from Battery Yesterday
                               "infoAssembly"  => "Master"
                           ),
                       array(
                               "userRef"       =>  3078,   // Energy from Battery Today till now
                               "infoAssembly"  => "Master"
                           ),
                       array(
                               "userRef"       =>  3082,   // Energy consumed yesterday
                               "infoAssembly"  => "Master"
                           ),
                       array(
                               "userRef"       =>  3080,   // Energy from Grid yesterda
                               "infoAssembly"  => "Master"
                           ),
                       array(
                               "userRef"       =>  11011,   // Solar Production from Panel set1 yesterday
                               "infoAssembly"  => "1"
                           ),
                       array(
                               "userRef"       =>  11011,   // Solar Production from Panel set 2 yesterday
                               "infoAssembly"  => "2"
                           ),
                      array(
                               "userRef"       =>  3137,   // Grid AC input Active power
                               "infoAssembly"  => "Master"
                           ),
                      array(
                               "userRef"       =>  3020,   // State of Transfer Relay
                               "infoAssembly"  => "Master"
                            ),
                      array(
                               "userRef"       =>  3031,   // State of AUX1 relay
                               "infoAssembly"  => "Master"
                            ),
                      array(
                              "userRef"       =>  3000,   // Battery Voltage
                              "infoAssembly"  => "Master"
                            ),
                      array(
                              "userRef"       =>  3011,   // Grid AC in Voltage Vac
                              "infoAssembly"  => "Master"
                            ),
                      array(
                              "userRef"       =>  3012,   // Grid AC in Current Aac
                              "infoAssembly"  => "Master"
                            ),
                      array(
                              "userRef"       =>  3005,   // DC input current to Inverter
                              "infoAssembly"  => "Master"
                            ),

                      array(
                              "userRef"       =>  11001,   // DC current into Battery junstion from VT1
                              "infoAssembly"  => "1"
                            ),
                      array(
                              "userRef"       =>  11001,   // DC current into Battery junstion from VT2
                              "infoAssembly"  => "2"
                            ),
                      array(
                              "userRef"       =>  11002,   // solar pv Voltage to variotrac
                              "infoAssembly"  => "Master"
                            ),
                      array(
                              "userRef"       =>  11004,   // Psolkw from VT1
                              "infoAssembly"  => "1"
                            ),
                      array(
                              "userRef"       =>  11004,   // Psolkw from VT2
                              "infoAssembly"  => "2"
                            ),

                      array(
                              "userRef"       =>  3010,   // Phase of battery charge
                              "infoAssembly"  => "Master"
                            ),
                      );
        $studer_api->body   = $body;

        // POST curl request to Studer
        $user_values  = $studer_api->get_user_values();

        if (empty($user_values))
            {
              return null;
            }

        $solar_pv_adc = 0;
        $psolar_kw    = 0;
        $psolar_kw_yesterday = 0;


        foreach ($user_values as $user_value)
        {
          switch (true)
          {
            case ( $user_value->reference == 3031 ) :
              $aux1_relay_state = $user_value->value;
            break;

            case ( $user_value->reference == 3020 ) :
              $transfer_relay_state = $user_value->value;
            break;

            case ( $user_value->reference == 3011 ) :
              $grid_input_vac = round($user_value->value, 0);
            break;

            case ( $user_value->reference == 3012 ) :
              $grid_input_aac = round($user_value->value, 1);
            break;

            case ( $user_value->reference == 3000 ) :
              $battery_voltage_vdc = round($user_value->value, 2);
            break;

            case ( $user_value->reference == 3005 ) :
              $inverter_current_adc = round($user_value->value, 1);
            break;

            case ( $user_value->reference == 3137 ) :
              $grid_pin_ac_kw = round($user_value->value, 2);

            break;

            case ( $user_value->reference == 3136 ) :
              $pout_inverter_ac_kw = round($user_value->value, 2);

            break;

            case ( $user_value->reference == 3076 ) :
               $energyout_battery_yesterday = round($user_value->value, 2);

             break;

            case ( $user_value->reference == 3078 ) :
              $KWH_battery_today = round($user_value->value, 2);

            break;

             case ( $user_value->reference == 3080 ) :
               $energy_grid_yesterday = round($user_value->value, 2);

             break;

             case ( $user_value->reference == 3082 ) :
               $energy_consumed_yesterday = round($user_value->value, 2);

             break;

            case ( $user_value->reference == 11001 ) :
              // we have to accumulate values form 2 cases:VT1 and VT2 so we have used accumulation below
              $solar_pv_adc += $user_value->value;

            break;

            case ( $user_value->reference == 11002 ) :
              $solar_pv_vdc = round($user_value->value, 1);

            break;

            case ( $user_value->reference == 11004 ) :
              // we have to accumulate values form 2 cases so we have used accumulation below
              $psolar_kw += round($user_value->value, 2);

            break;

            case ( $user_value->reference == 3010 ) :
              $phase_battery_charge = $user_value->value;

            break;

            case ( $user_value->reference == 11011 ) :
               // we have to accumulate values form 2 cases so we have used accumulation below
               $psolar_kw_yesterday += round($user_value->value, 2);

             break;
          }
        }

        $solar_pv_adc = round($solar_pv_adc, 1);

        // calculate the current into/out of battery and battery instantaneous power
        $battery_charge_adc  = round($solar_pv_adc + $inverter_current_adc, 1); // + is charge, - is discharge
        $pbattery_kw         = round($battery_voltage_vdc * $battery_charge_adc * 0.001, 2); //$psolar_kw - $pout_inverter_ac_kw;


        // inverter's output always goes to load never the other way around :-)
        $inverter_pout_arrow_class = "fa fa-long-arrow-right fa-rotate-45 rediconcolor";

        // conditional class names for battery charge down or up arrow
        if ($battery_charge_adc > 0.0)
        {
          // current is positive so battery is charging so arrow is down and to left. Also arrow shall be red to indicate charging
          $battery_charge_arrow_class = "fa fa-long-arrow-down fa-rotate-45 rediconcolor";
          // battery animation class is from ne-sw
          $battery_charge_animation_class = "arrowSliding_ne_sw";

          $battery_color_style = 'greeniconcolor';

          // also good time to compensate for IR drop.
          // Actual voltage is smaller than indicated, when charging
          $battery_voltage_vdc = round($battery_voltage_vdc + abs($inverter_current_adc) * $Ra - abs($battery_charge_adc) * $Rb, 2);
        }
        else
        {
          // current is -ve so battery is discharging so arrow is up and icon color shall be red
          $battery_charge_arrow_class = "fa fa-long-arrow-up fa-rotate-45 greeniconcolor";
          $battery_charge_animation_class = "arrowSliding_sw_ne";
          $battery_color_style = 'rediconcolor';

          // Actual battery voltage is larger than indicated when discharging
          $battery_voltage_vdc = round($battery_voltage_vdc + abs($inverter_current_adc) * $Ra + abs($battery_charge_adc) * $Rb, 2);
        }

        switch(true)
        {
          case (abs($battery_charge_adc) < 27 ) :
            $battery_charge_arrow_class .= " fa-1x";
          break;

          case (abs($battery_charge_adc) < 54 ) :
            $battery_charge_arrow_class .= " fa-2x";
          break;

          case (abs($battery_charge_adc) >=54 ) :
            $battery_charge_arrow_class .= " fa-3x";
          break;
        }

        // conditional for solar pv arrow
        if ($psolar_kw > 0.1)
        {
          // power is greater than 0.2kW so indicate down arrow
          $solar_arrow_class = "fa fa-long-arrow-down fa-rotate-45 greeniconcolor";
          $solar_arrow_animation_class = "arrowSliding_ne_sw";
        }
        else
        {
          // power is too small indicate a blank line vertically down from Solar panel to Inverter in diagram
          $solar_arrow_class = "fa fa-minus fa-rotate-90";
          $solar_arrow_animation_class = "";
        }

        switch(true)
        {
          case (abs($psolar_kw) < 0.5 ) :
            $solar_arrow_class .= " fa-1x";
          break;

          case (abs($psolar_kw) < 2.0 ) :
            $solar_arrow_class .= " fa-2x";
          break;

          case (abs($psolar_kw) >= 2.0 ) :
            $solar_arrow_class .= " fa-3x";
          break;
        }

        switch(true)
        {
          case (abs($pout_inverter_ac_kw) < 1.0 ) :
            $inverter_pout_arrow_class .= " fa-1x";
          break;

          case (abs($pout_inverter_ac_kw) < 2.0 ) :
            $inverter_pout_arrow_class .= " fa-2x";
          break;

          case (abs($pout_inverter_ac_kw) >=2.0 ) :
            $inverter_pout_arrow_class .= " fa-3x";
          break;
        }

        // conditional for Grid input arrow
        if ($transfer_relay_state)
        {
          // Transfer Relay is closed so grid input is possible
          $grid_input_arrow_class = "fa fa-long-arrow-right fa-rotate-45";
        }
        else
        {
          // Transfer relay is open and grid input is not possible
          $grid_input_arrow_class = "fa fa-times-circle fa-2x";
        }

        switch(true)
        {
          case (abs($grid_pin_ac_kw) < 1.0 ) :
            $grid_input_arrow_class .= " fa-1x";
          break;

          case (abs($grid_pin_ac_kw) < 2.0 ) :
            $grid_input_arrow_class .= " fa-2x";
          break;

          case (abs($grid_pin_ac_kw) < 3.5 ) :
            $grid_input_arrow_class .= " fa-3x";
          break;

          case (abs($grid_pin_ac_kw) < 4 ) :
            $grid_input_arrow_class .= " fa-4x";
          break;
        }

       $current_user           = wp_get_current_user();
       $current_user_ID        = $current_user->ID;
       
      switch(true)
      {
        case ($battery_voltage_vdc < $config['battery_vdc_state']["25p"] ):
          $battery_icon_class = "fa fa-3x fa-solid fa-battery-empty";
        break;

        case ($battery_voltage_vdc >= $config['battery_vdc_state']["25p"] &&
              $battery_voltage_vdc <  $config['battery_vdc_state']["50p"] ):
          $battery_icon_class = "fa fa-3x fa-solid fa-battery-quarter";
        break;

        case ($battery_voltage_vdc >= $config['battery_vdc_state']["50p"] &&
              $battery_voltage_vdc <  $config['battery_vdc_state']["75p"] ):
          $battery_icon_class = "fa fa-3x fa-solid fa-battery-half";
        break;

        case ($battery_voltage_vdc >= $config['battery_vdc_state']["75p"] &&
              $battery_voltage_vdc <  $config['battery_vdc_state']["100p"] ):
          $battery_icon_class = "fa fa-3x fa-solid fa-battery-three-quarters";
        break;

        case ($battery_voltage_vdc >= $config['battery_vdc_state']["100p"] ):
          $battery_icon_class = "fa fa-3x fa-solid fa-battery-full";
        break;
      }

      $battery_span_fontawesome = '
                                    <i class="' . $battery_icon_class . ' ' . $battery_color_style . '"></i>';

      // select battery icon color: Green if charging, Red if discharging


      // update the object with battery data read
      $studer_readings_obj->battery_charge_adc          = $battery_charge_adc;
      $studer_readings_obj->pbattery_kw                 = abs($pbattery_kw);
      $studer_readings_obj->battery_voltage_vdc         = $battery_voltage_vdc;
      $studer_readings_obj->battery_charge_arrow_class  = $battery_charge_arrow_class;
      $studer_readings_obj->battery_icon_class          = $battery_icon_class;
      $studer_readings_obj->battery_charge_animation_class = $battery_charge_animation_class;
      $studer_readings_obj->energyout_battery_yesterday    = $energyout_battery_yesterday;

      // update the object with SOlar data read
      $studer_readings_obj->psolar_kw                   = $psolar_kw;
      $studer_readings_obj->solar_pv_adc                = $solar_pv_adc;
      // $studer_readings_obj->solar_pv_vdc                = $solar_pv_vdc;
      $studer_readings_obj->solar_arrow_class           = $solar_arrow_class;
      $studer_readings_obj->solar_arrow_animation_class = $solar_arrow_animation_class;
      $studer_readings_obj->psolar_kw_yesterday         = $psolar_kw_yesterday;

      //update the object with Inverter Load details
      $studer_readings_obj->pout_inverter_ac_kw         = $pout_inverter_ac_kw;
      $studer_readings_obj->inverter_pout_arrow_class   = $inverter_pout_arrow_class;

      // update the Grid input values
      $studer_readings_obj->transfer_relay_state        = $transfer_relay_state;
      $studer_readings_obj->grid_pin_ac_kw              = $grid_pin_ac_kw;
      $studer_readings_obj->grid_input_vac              = $grid_input_vac;
      $studer_readings_obj->grid_input_arrow_class      = $grid_input_arrow_class;
      $studer_readings_obj->aux1_relay_state            = $aux1_relay_state;
      $studer_readings_obj->energy_grid_yesterday       = $energy_grid_yesterday;
      $studer_readings_obj->energy_consumed_yesterday   = $energy_consumed_yesterday;
      $studer_readings_obj->battery_span_fontawesome    = $battery_span_fontawesome;


      // update the object with the fontawesome cdn from Studer API object
      // $studer_readings_obj->fontawesome_cdn             = $studer_api->fontawesome_cdn;

      return $studer_readings_obj;
    }

    /**
    ** This function returns an object that comprises data read form user's installtion
    *  @param int:$user_index  is the numeric index to denote a particular installtion
    *  @return object:$studer_readings_obj
    */
    public static function get_studer_min_readings(int $user_index): ?object
    {
        $config = self::$config;

        $Ra = 0.0;       // value of resistance from DC junction to Inverter
        $Rb = 0.025;       // value of resistance from DC junction to Battery terminals

        $base_url  = $config['studer_api_baseurl'];
        $uhash     = $config['accounts'][$user_index]['uhash'];
        $phash     = $config['accounts'][$user_index]['phash'];

        $studer_api = new studer_api($uhash, $phash, $base_url);

        $studer_readings_obj = new stdClass;

        $body = [];

        $body = array(array(
                              "userRef"       =>  3136,   // AC active power delivered by inverter
                              "infoAssembly"  => "Master"
                           ),
                      array(
                               "userRef"       =>  3137,   // Grid AC input Active power
                               "infoAssembly"  => "Master"
                           ),
                      array(
                               "userRef"       =>  3020,   // State of Transfer Relay
                               "infoAssembly"  => "Master"
                            ),
                      array(
                               "userRef"       =>  3031,   // State of AUX1 relay
                               "infoAssembly"  => "Master"
                            ),
                      array(
                              "userRef"       =>  3000,   // Battery Voltage
                              "infoAssembly"  => "Master"
                            ),
                      array(
                              "userRef"       =>  3011,   // Grid AC in Voltage Vac
                              "infoAssembly"  => "Master"
                            ),
                      array(
                              "userRef"       =>  3012,   // Grid AC in Current Aac
                              "infoAssembly"  => "Master"
                            ),
                      array(
                              "userRef"       =>  3005,   // DC input current to Inverter
                              "infoAssembly"  => "Master"
                            ),
                      array(
                              "userRef"       =>  3078,   // KWH today Energy discharged from Battery
                              "infoAssembly"  => "Master"
                            ),      
                      array(
                              "userRef"       =>  3081,   // KWH today Energy In from GRID
                              "infoAssembly"  => "Master"
                            ),
                      array(
                              "userRef"       =>  3083,   // KWH today Energy consumed by Load
                              "infoAssembly"  => "Master"
                            ),

                      array(
                              "userRef"       =>  11001,   // DC current into Battery junstion from VT1
                              "infoAssembly"  => "1"
                            ),
                      array(
                              "userRef"       =>  11001,   // DC current into Battery junstion from VT2
                              "infoAssembly"  => "2"
                            ),
                      array(
                              "userRef"       =>  11002,   // solar pv Voltage to variotrac
                              "infoAssembly"  => "Master"
                            ),
                      array(
                              "userRef"       =>  11004,   // Psolkw from VT1
                              "infoAssembly"  => "1"
                            ),
                      array(
                              "userRef"       =>  11004,   // Psolkw from VT2
                              "infoAssembly"  => "2"
                            ),
                      array(
                              "userRef"       =>  11007,   // KWHsol generated today till now, from VT1
                              "infoAssembly"  => "1"
                            ),
                      array(
                              "userRef"       =>  11007,   // KWHsol generated today till now, from VT2
                              "infoAssembly"  => "2"
                            ),
                      array(
                              "userRef"       =>  3010,   // Phase of battery charge
                              "infoAssembly"  => "Master"
                            ),
                      );
        $studer_api->body   = $body;

        // POST curl request to Studer
        $user_values  = $studer_api->get_user_values();

        if (empty($user_values))
            {
              return null;
            }

        $solar_pv_adc = 0;
        $psolar_kw    = 0;
        $psolar_kw_yesterday = 0;
        $KWH_solar_today = 0;


        foreach ($user_values as $user_value)
        {
          switch (true)
          {
            case ( $user_value->reference == 3031 ) :
              $aux1_relay_state = $user_value->value;
            break;

            case ( $user_value->reference == 3020 ) :
              $transfer_relay_state = $user_value->value;
            break;

            case ( $user_value->reference == 3011 ) :
              $grid_input_vac = round($user_value->value, 0);
            break;

            case ( $user_value->reference == 3012 ) :
              $grid_input_aac = round($user_value->value, 1);
            break;

            case ( $user_value->reference == 3000 ) :
              $battery_voltage_vdc = round($user_value->value, 2);
            break;

            case ( $user_value->reference == 3005 ) :
              $inverter_current_adc = round($user_value->value, 1);
            break;

            case ( $user_value->reference == 3137 ) :
              $grid_pin_ac_kw = round($user_value->value, 2);

            break;

            case ( $user_value->reference == 3136 ) :
              $pout_inverter_ac_kw = round($user_value->value, 2);

            break;

            case ( $user_value->reference == 3076 ) :
               $energyout_battery_yesterday = round($user_value->value, 2);

             break;

             case ( $user_value->reference == 3078 ) :
                $KWH_batt_discharged_today = round($user_value->value, 2);

            break;

             case ( $user_value->reference == 3080 ) :
               $energy_grid_yesterday = round($user_value->value, 2);

             break;

             case ( $user_value->reference == 3081 ) :
                $KWH_grid_today = round($user_value->value, 2);

            break;

             case ( $user_value->reference == 3082 ) :
               $energy_consumed_yesterday = round($user_value->value, 2);

             break;

             case ( $user_value->reference == 3083 ) :
              $KWH_load_today = round($user_value->value, 2);

            break;

            case ( $user_value->reference == 11001 ) :
              // we have to accumulate values form 2 cases:VT1 and VT2 so we have used accumulation below
              $solar_pv_adc += $user_value->value;

            break;

            case ( $user_value->reference == 11002 ) :
              $solar_pv_vdc = round($user_value->value, 1);

            break;

            case ( $user_value->reference == 11004 ) :
              // we have to accumulate values form 2 cases so we have used accumulation below
              $psolar_kw += round($user_value->value, 2);

            break;

            case ( $user_value->reference == 3010 ) :
              $phase_battery_charge = $user_value->value;

            break;

            case ( $user_value->reference == 11011 ) :
               // we have to accumulate values form 2 cases so we have used accumulation below
               $psolar_kw_yesterday += round($user_value->value, 2);

             break;

            case ( $user_value->reference == 11007 ) :
              // we have to accumulate values form 2 cases so we have used accumulation below
              $KWH_solar_today += round($user_value->value, 2);

            break;
          }
        }

        $solar_pv_adc = round($solar_pv_adc, 1);

        // calculate the current into/out of battery and battery instantaneous power
        $battery_charge_adc  = round($solar_pv_adc + $inverter_current_adc, 1); // + is charge, - is discharge
        $pbattery_kw         = round($battery_voltage_vdc * $battery_charge_adc * 0.001, 2); //$psolar_kw - $pout_inverter_ac_kw;


        // conditional class names for battery charge down or up arrow
        if ($battery_charge_adc > 0.0)
        {
          // current is positive so battery is charging so arrow is down and to left. Also arrow shall be red to indicate charging
          $battery_charge_arrow_class = "fa fa-long-arrow-down fa-rotate-45 rediconcolor";
          // battery animation class is from ne-sw
          $battery_charge_animation_class = "arrowSliding_ne_sw";

          $battery_color_style = 'greeniconcolor';

          // also good time to compensate for IR drop.
          // Actual voltage is smaller than indicated, when charging
          $battery_voltage_vdc = round($battery_voltage_vdc + abs($inverter_current_adc) * $Ra - abs($battery_charge_adc) * $Rb, 2);
        }
        else
        {
          // current is -ve so battery is discharging so arrow is up and icon color shall be red
          $battery_charge_arrow_class = "fa fa-long-arrow-up fa-rotate-45 greeniconcolor";
          $battery_charge_animation_class = "arrowSliding_sw_ne";
          $battery_color_style = 'rediconcolor';

          // Actual battery voltage is larger than indicated when discharging
          $battery_voltage_vdc = round($battery_voltage_vdc + abs($inverter_current_adc) * $Ra + abs($battery_charge_adc) * $Rb, 2);
        }

        switch(true)
        {
          case (abs($battery_charge_adc) < 27 ) :
            $battery_charge_arrow_class .= " fa-1x";
          break;

          case (abs($battery_charge_adc) < 54 ) :
            $battery_charge_arrow_class .= " fa-2x";
          break;

          case (abs($battery_charge_adc) >=54 ) :
            $battery_charge_arrow_class .= " fa-3x";
          break;
        }

        // conditional for solar pv arrow
        if ($psolar_kw > 0.1)
        {
          // power is greater than 0.2kW so indicate down arrow
          $solar_arrow_class = "fa fa-long-arrow-down fa-rotate-45 greeniconcolor";
          $solar_arrow_animation_class = "arrowSliding_ne_sw";
        }
        else
        {
          // power is too small indicate a blank line vertically down from Solar panel to Inverter in diagram
          $solar_arrow_class = "fa fa-minus fa-rotate-90";
          $solar_arrow_animation_class = "";
        }

        switch(true)
        {
          case (abs($psolar_kw) < 0.5 ) :
            $solar_arrow_class .= " fa-1x";
          break;

          case (abs($psolar_kw) < 2.0 ) :
            $solar_arrow_class .= " fa-2x";
          break;

          case (abs($psolar_kw) >= 2.0 ) :
            $solar_arrow_class .= " fa-3x";
          break;
        }

        // conditional for Grid input arrow
        if ($transfer_relay_state)
        {
          // Transfer Relay is closed so grid input is possible
          $grid_input_arrow_class = "fa fa-long-arrow-right fa-rotate-45";
        }
        else
        {
          // Transfer relay is open and grid input is not possible
          $grid_input_arrow_class = "fa fa-times-circle fa-2x";
        }

        switch(true)
        {
          case (abs($grid_pin_ac_kw) < 1.0 ) :
            $grid_input_arrow_class .= " fa-1x";
          break;

          case (abs($grid_pin_ac_kw) < 2.0 ) :
            $grid_input_arrow_class .= " fa-2x";
          break;

          case (abs($grid_pin_ac_kw) < 3.5 ) :
            $grid_input_arrow_class .= " fa-3x";
          break;

          case (abs($grid_pin_ac_kw) < 4 ) :
            $grid_input_arrow_class .= " fa-4x";
          break;
        }

       $current_user           = wp_get_current_user();
       $current_user_ID        = $current_user->ID;
       
      switch(true)
      {
        case ($battery_voltage_vdc < $config['battery_vdc_state']["25p"] ):
          $battery_icon_class = "fa fa-3x fa-solid fa-battery-empty";
        break;

        case ($battery_voltage_vdc >= $config['battery_vdc_state']["25p"] &&
              $battery_voltage_vdc <  $config['battery_vdc_state']["50p"] ):
          $battery_icon_class = "fa fa-3x fa-solid fa-battery-quarter";
        break;

        case ($battery_voltage_vdc >= $config['battery_vdc_state']["50p"] &&
              $battery_voltage_vdc <  $config['battery_vdc_state']["75p"] ):
          $battery_icon_class = "fa fa-3x fa-solid fa-battery-half";
        break;

        case ($battery_voltage_vdc >= $config['battery_vdc_state']["75p"] &&
              $battery_voltage_vdc <  $config['battery_vdc_state']["100p"] ):
          $battery_icon_class = "fa fa-3x fa-solid fa-battery-three-quarters";
        break;

        case ($battery_voltage_vdc >= $config['battery_vdc_state']["100p"] ):
          $battery_icon_class = "fa fa-3x fa-solid fa-battery-full";
        break;
      }

      $battery_span_fontawesome = '
                                    <i class="' . $battery_icon_class . ' ' . $battery_color_style . '"></i>';

      // select battery icon color: Green if charging, Red if discharging


      // update the object with battery data read
      $studer_readings_obj->battery_charge_adc          = $battery_charge_adc;
      $studer_readings_obj->pbattery_kw                 = abs($pbattery_kw);
      $studer_readings_obj->battery_voltage_vdc         = $battery_voltage_vdc;
      $studer_readings_obj->battery_charge_arrow_class  = $battery_charge_arrow_class;
      $studer_readings_obj->battery_icon_class          = $battery_icon_class;
      $studer_readings_obj->battery_charge_animation_class = $battery_charge_animation_class;
      // $studer_readings_obj->energyout_battery_yesterday    = $energyout_battery_yesterday;

      // update the object with Solar data read
      $studer_readings_obj->psolar_kw                   = $psolar_kw;
      $studer_readings_obj->solar_pv_adc                = $solar_pv_adc;
      // $studer_readings_obj->solar_pv_vdc                = $solar_pv_vdc;
      $studer_readings_obj->solar_arrow_class           = $solar_arrow_class;
      $studer_readings_obj->solar_arrow_animation_class = $solar_arrow_animation_class;
      $studer_readings_obj->psolar_kw_yesterday         = $psolar_kw_yesterday;

      //update the object with Inverter Load details
      $studer_readings_obj->pout_inverter_ac_kw         = $pout_inverter_ac_kw;
      // $studer_readings_obj->inverter_pout_arrow_class   = $inverter_pout_arrow_class;
      $studer_readings_obj->inverter_current_adc        = $inverter_current_adc;

      // update the Grid input values
      $studer_readings_obj->transfer_relay_state        = $transfer_relay_state;
      $studer_readings_obj->grid_pin_ac_kw              = $grid_pin_ac_kw;
      $studer_readings_obj->grid_input_vac              = $grid_input_vac;
      $studer_readings_obj->grid_input_arrow_class      = $grid_input_arrow_class;
      $studer_readings_obj->aux1_relay_state            = $aux1_relay_state;
      // $studer_readings_obj->energy_grid_yesterday       = $energy_grid_yesterday;
      // $studer_readings_obj->energy_consumed_yesterday   = $energy_consumed_yesterday;
      $studer_readings_obj->battery_span_fontawesome    = $battery_span_fontawesome;

      // Energy in KWH generated since midnight to now by Solar Panels
      $studer_readings_obj->KWH_solar_today    = $KWH_solar_today;

      $studer_readings_obj->KWH_grid_today    = $KWH_grid_today;

      $studer_readings_obj->KWH_load_today    = $KWH_load_today;

      $studer_readings_obj->KWH_batt_discharged_today    = $KWH_batt_discharged_today;

      return $studer_readings_obj;
    }

    /**
     *  service AJax Call for minutely cron updates to my solar page of website
     */
    public static function ajax_my_solar_cron_update_handler()     
    {   // service AJax Call for minutely cron updates to my solar screen
        // The error log time stamp was showing as UTC so I added the below statement
      date_default_timezone_set("Asia/Kolkata");

          // Ensures nonce is correct for security
          check_ajax_referer('my_solar_app_script');

          if ($_POST['data']) {   // extract data from POST sent by the Ajax Call and Sanitize
              
              $data = $_POST['data'];

              // get my user index knowing my login name
              $wp_user_ID   = $data['wp_user_ID'];

              // sanitize the POST data
              $wp_user_ID   = sanitize_text_field($wp_user_ID);
          }

          {    // get user_index based on user_name
            $current_user = get_user_by('id', $wp_user_ID);
            $wp_user_name = $current_user->user_login;
            $user_index   = array_search( $wp_user_name, array_column(self::$config['accounts'], 'wp_user_name')) ;

            // error_log('from CRON Ajax Call: wp_user_ID:' . $wp_user_ID . ' user_index:'   . $user_index);
          }

          // get the transient related to this user ID that stores the latest Readings
          if ( true === get_transient( $wp_user_name . '_studer_readings_object' ) )
          {
            $studer_readings_obj = get_transient( $wp_user_name . '_studer_readings_object' );
          }
          else
          {
            $studer_readings_obj = null;
          }
          
          // error_log(print_r($studer_readings_obj, true));

          if ($studer_readings_obj) {   // transient exists so we can send it
              
              $format_object = self::prepare_data_for_mysolar_update( $wp_user_ID, $wp_user_name, $studer_readings_obj );

              // send JSON encoded data to client browser AJAX call and then die
              wp_send_json($format_object);
          }
          else {    // transient does not exist so send null
            wp_send_json(null);
          }
      }



    /**
     * 
     */
    public static function ajax_my_solar_update_handler()     
    {   // service AJax Call
        // The error log time stamp was showing as UTC so I added the below statement
      date_default_timezone_set("Asia/Kolkata");

        // Ensures nonce is correct for security
        check_ajax_referer('my_solar_app_script');

        if ($_POST['data']) {   // extract data from POST sent by the Ajax Call and Sanitize
            
            $data = $_POST['data'];

            $toggleGridSwitch = $data['toggleGridSwitch'];

            // sanitize the POST data
            $toggleGridSwitch = sanitize_text_field($toggleGridSwitch);

            // get my user index knowing my login name
            $wp_user_ID   = $data['wp_user_ID'];

            // sanitize the POST data
            $wp_user_ID   = sanitize_text_field($wp_user_ID);

            $doShellyToggle = $data['doShellyToggle'];

            // sanitize the POST data
            $doShellyToggle = sanitize_text_field($doShellyToggle);
        }

        if ( $doShellyToggle ) {  // User request to toggle do_shelly user meta

            // get the current setting from User Meta
            $current_status_doShelly = get_user_meta($wp_user_ID, "do_shelly", true);

            switch(true)
            {
                case( is_null( $current_status_doShelly ) ):  // do nothing, since the user has not formally set this flag.  
                    break;

                case($current_status_doShelly):               // If TRUE, update user meta to FALSE
                    
                    update_user_meta( $wp_user_ID, "do_shelly", false);
                    break;

                case( ! $current_status_doShelly):            // If FALSE, update user meta to TRUE
                    update_user_meta( $wp_user_ID, "do_shelly", true);
                    break;
            }
        }
        
        {    // get user_index based on user_name
            $current_user = get_user_by('id', $wp_user_ID);
            $wp_user_name = $current_user->user_login;
            $user_index   = array_search( $wp_user_name, array_column( self::$config['accounts'], 'wp_user_name' ) ) ;

            error_log("from Ajax Call: toggleGridSwitch Value: " . $toggleGridSwitch . 
                                                  ' wp_user_ID:' . $wp_user_ID       . 
                                            ' doShellyToggle:'   . $doShellyToggle   . 
                                                ' user_index:'   . $user_index);
        }

        // extract the do_shelly control flag as set in user meta
        $do_shelly_user_meta  = get_user_meta($wp_user_ID, "do_shelly", true);

        if ($toggleGridSwitch)  {   // User has requested to toggle the GRID ON/OFF Shelly Switch

            // Get current status of switch
            $shelly_api_device_response   = self::get_shelly_device_status_acin($user_index);

            if ( empty($shelly_api_device_response) ) {   // what do we do we do if device is OFFLINE?
                // do nothing
            }
            else {  // valid switch response so we can determine status
                    
                    $shelly_api_device_status_ON  = $shelly_api_device_response->data->device_status->{"switch:0"}->output;

                    if ($shelly_api_device_status_ON) {   // Switch is ON, toggle switch to OFF
                        $shelly_switch_status = "ON";

                        // we need to turn it off because user has toggled switch
                        $response = self::turn_on_off_shelly_switch($user_index, "off");

                        error_log('Changed Switch from ON->OFF due to Ajax Request');

                    }
                    else {    // Switch is OFF, toggle switch to ON
                        $shelly_switch_status = "OFF";

                        // we need to turn switch ON since user has toggled switch
                        $response = self::turn_on_off_shelly_switch($user_index, "on");

                        error_log('Changed Switch from OFF->ON due to Ajax Request');
                    }
            }
        }

        // get a new set of readings
        $studer_readings_obj = self::get_readings_and_servo_grid_switch  ($user_index, 
                                                                          $wp_user_ID, 
                                                                          $wp_user_name, 
                                                                          $do_shelly_user_meta);

        $format_object = self::prepare_data_for_mysolar_update( $wp_user_ID, $wp_user_name, $studer_readings_obj );

        wp_send_json($format_object);
    }    
    
    /**
     * @param stdClass:studer_readings_obj contains details of all the readings
     * @return stdClass:format_object contains html for all the icons to be updatd by JS on Ajax call return
     * determine the icons based on updated data
     */
    public static function prepare_data_for_mysolar_update( $wp_user_ID, $wp_user_name, $studer_readings_obj )
    {
        $config         = self::$config;

        // Initialize object to be returned
        $format_object  = new stdClass();

        $psolar_kw              =   $studer_readings_obj->psolar_kw;
        $solar_pv_adc           =   $studer_readings_obj->solar_pv_adc;

        $pout_inverter_ac_kw    =   $studer_readings_obj->pout_inverter_ac_kw;

    
        $battery_voltage_vdc    =   round( $studer_readings_obj->battery_voltage_vdc, 1);

        // Positive is charging and negative is discharging
        $battery_charge_adc     =   $studer_readings_obj->battery_charge_adc;

        $pbattery_kw            = $studer_readings_obj->pbattery_kw;

        $grid_pin_ac_kw         =   $studer_readings_obj->grid_pin_ac_kw;
        $grid_input_vac         =   $studer_readings_obj->grid_input_vac;

        $shelly_api_device_status_ON      = $studer_readings_obj->shelly_api_device_status_ON;
        $shelly_api_device_status_voltage = $studer_readings_obj->shelly_api_device_status_voltage;

        $SOC_percentage_now = $studer_readings_obj->SOC_percentage_now;

        // If power is flowing OR switch has ON status then show CHeck and Green
        $grid_arrow_size = self::get_arrow_size_based_on_power($grid_pin_ac_kw);

        switch (true)
        {   // choose grid icon info based on switch status
            case ( is_null($shelly_api_device_status_ON) ): // No Grid OR switch is OFFLINE
                $grid_status_icon = '<i class="fa-solid fa-3x fa-power-off" style="color: Yellow;"></i>';

                $grid_arrow_icon = ''; //'<i class="fa-solid fa-3x fa-circle-xmark"></i>';

                $grid_info = 'No<br>Grid';

                break;


            case ( $shelly_api_device_status_ON): // Switch is ON
                $grid_status_icon = '<i class="clickableIcon fa-solid fa-3x fa-power-off" style="color: Blue;"></i>';

                $grid_arrow_icon  = '<i class="fa-solid' . $grid_arrow_size .  'fa-arrow-right-long fa-rotate-by"
                                                                                  style="--fa-rotate-angle: 45deg;">
                                    </i>';
                $grid_info = '<span style="font-size: 18px;color: Red;"><strong>' . $grid_pin_ac_kw . 
                              ' KW</strong><br>' . $shelly_api_device_status_voltage . ' V</span>';
                break;


            case ( ! $shelly_api_device_status_ON):   // Switch is online and OFF
                $grid_status_icon = '<i class="clickableIcon fa-solid fa-3x fa-power-off" style="color: Red;"></i>';

                $grid_arrow_icon = ''; //'<i class="fa-solid fa-1x fa-circle-xmark"></i>';
    
                $grid_info = '<span style="font-size: 18px;color: Red;">' . $grid_pin_ac_kw . 
                        ' KW<br>' . $shelly_api_device_status_voltage . ' V</span>';
                break;

            default:  
              $grid_status_icon = '<i class="fa-solid fa-3x fa-power-off" style="color: Brown;"></i>';

              $grid_arrow_icon = 'XX'; //'<i class="fa-solid fa-3x fa-circle-xmark"></i>';

              $grid_info = '???';
        }

        $format_object->grid_status_icon  = $grid_status_icon;
        $format_object->grid_arrow_icon   = $grid_arrow_icon;

        // grid power and voltage info
        $format_object->grid_info       = $grid_info;

        // PV arrow icon psolar_info
        $pv_arrow_size = self::get_arrow_size_based_on_power($psolar_kw);

        if ($psolar_kw > 0.1) {
            $pv_arrow_icon = '<i class="fa-solid' . $pv_arrow_size . 'fa-arrow-down-long fa-rotate-by"
                                                                           style="--fa-rotate-angle: 45deg;
                                                                                              color: Green;"></i>';
            $psolar_info =  '<span style="font-size: 18px;color: Green;"><strong>' . $psolar_kw . 
                            ' KW</strong><br>' . $solar_pv_adc . ' A</span>';
        }
        else {
            $pv_arrow_icon = ''; //'<i class="fa-solid fa-1x fa-circle-xmark"></i>';
            $psolar_info =  '<span style="font-size: 18px;">' . $psolar_kw . 
                            ' KW<br>' . $solar_pv_adc . ' A</span>';
        }

        $pv_panel_icon =  '<span style="color: Green;">
                              <i class="fa-solid fa-3x fa-solar-panel"></i>
                          </span>';

        $format_object->pv_panel_icon = $pv_panel_icon;
        $format_object->pv_arrow_icon = $pv_arrow_icon;
        $format_object->psolar_info   = $psolar_info;

        // Studer Inverter icon
        $studer_icon = '<i style="display:block; text-align: center;" class="clickableIcon fa-solid fa-3x fa-cog"></i>';
        $format_object->studer_icon = $studer_icon;

        if ($studer_readings_obj->control_shelly)
        {
            $shelly_servo_icon = '<span style="color: Green; display:block; text-align: center;">
                                      <i class="clickableIcon fa-solid fa-2x fa-cloud"></i>
                                  </span>';
        }
        else
        {
            $shelly_servo_icon = '<span style="color: Red; display:block; text-align: center;">
                                      <i class="clickableIcon fa-solid fa-2x fa-cloud"></i>
                                  </span>';
        }
        $format_object->shelly_servo_icon = $shelly_servo_icon;

        // battery status icon: select battery icon based on charge level
        switch(true)
        {
            case ($SOC_percentage_now < 25):
              $battery_icon_class = "fa fa-3x fa-solid fa-battery-empty";
            break;

            case ($SOC_percentage_now >= 25 &&
                  $SOC_percentage_now <  37.5 ):
              $battery_icon_class = "fa fa-3x fa-solid fa-battery-quarter";
            break;

            case ($SOC_percentage_now >= 37.5 &&
                  $SOC_percentage_now <  50 ):
              $battery_icon_class = "fa fa-3x fa-solid fa-battery-half";
            break;

            case ($SOC_percentage_now >= 50 &&
                  $SOC_percentage_now <  77.5):
              $battery_icon_class = "fa fa-3x fa-solid fa-battery-three-quarters";
            break;

            case ($SOC_percentage_now >= 77.5):
              $battery_icon_class = "fa fa-3x fa-solid fa-battery-full";
            break;
        }

        // now determione battery arrow direction and battery color based on charge or discharge
        // conditional class names for battery charge down or up arrow
        $battery_arrow_size = self::get_arrow_size_based_on_power($pbattery_kw);

        if ($battery_charge_adc > 0.0)
        {
            // current is positive so battery is charging so arrow is down and to left. Also arrow shall be red to indicate charging
            $battery_arrow_icon = '<i class="fa-solid' .  $battery_arrow_size . 'fa-arrow-down-long fa-rotate-by"
                                                                                style="--fa-rotate-angle: 45deg;
                                                                                                   color:green;">
                                   </i>';

            // battery animation class is from ne-sw
            $battery_charge_animation_class = "arrowSliding_ne_sw";

            // battery icon shall be green in color
            $battery_color_style = 'greeniconcolor';

            // battery info shall be green in color
            $battery_info =  '<span style="font-size: 18px;color: Green;"><strong>' . $pbattery_kw  . ' KW</strong><br>' 
                                                                            . abs($battery_charge_adc)  . 'A<br>'
                                                                            . $battery_voltage_vdc      . ' V<br></span>';
        }
        else
        {
          // current is -ve so battery is discharging so arrow is up and icon color shall be red
          $battery_arrow_icon = '<i class="fa-solid' . $battery_arrow_size . 'fa-arrow-up fa-rotate-by"
                                                                              style="--fa-rotate-angle: 45deg;
                                                                                                 color:red;">
                                  </i>';

          $battery_charge_animation_class = "arrowSliding_sw_ne";

          // battery status in discharge is red in color
          $battery_color_style = 'rediconcolor';

          // battery info shall be red in color
          $battery_info =  '<span style="font-size: 18px;color: Red;"><strong>' . $pbattery_kw . ' KW</strong><br>' 
                                                                        . abs($battery_charge_adc)  . 'A<br>'
                                                                        . $battery_voltage_vdc      . ' V<br></span>';
        }

        if  ($pbattery_kw < 0.01 ) $battery_arrow_icon = ''; // '<i class="fa-solid fa-1x fa-circle-xmark"></i>';

        $format_object->battery_arrow_icon  = $battery_arrow_icon;

        $battery_status_icon = '<i class="' . $battery_icon_class . ' ' . $battery_color_style . '"></i>';

        $format_object->battery_status_icon = $battery_status_icon;
        $format_object->battery_arrow_icon  = $battery_arrow_icon;
        $format_object->battery_info        = $battery_info;

        $load_arrow_size = self::get_arrow_size_based_on_power($pout_inverter_ac_kw);

        $load_info = '<span style="font-size: 18px;color: Black;"><strong>' . $pout_inverter_ac_kw . ' KW</strong></span>';
        $load_arrow_icon = '<i class="fa-solid' . $load_arrow_size . 'fa-arrow-right-long fa-rotate-by"
                                                                          style="--fa-rotate-angle: 45deg;">
                            </i>';

        $load_icon = '<span style="color: Black;">
                          <i class="fa-solid fa-3x fa-house"></i>
                      </span>';

        $format_object->load_info        = $load_info;
        $format_object->load_arrow_icon  = $load_arrow_icon;
        $format_object->load_icon        = $load_icon;

        // Get Cron Exit COndition from User Meta and its time stamo
        $json_cron_exit_condition_user_meta = get_user_meta( $wp_user_ID, 'studer_readings_object', true );
        // decode the JSON encoded string into an Object
        $cron_exit_condition_user_meta_arr = json_decode($json_cron_exit_condition_user_meta, true);

        // extract the last condition saved that was NOT a No Action. Add cloudiness and Estimated Solar to message
        $saved_cron_exit_condition = $cron_exit_condition_user_meta_arr['cron_exit_condition'];
        $saved_cron_exit_condition .= " Cloud: " . $studer_readings_obj->cloudiness_average_percentage_weighted . " %";
        $saved_cron_exit_condition .= " Pest: " . $studer_readings_obj->est_solar_kw . " KW";

        // present time
        $now = new DateTime();
        // timestamp at last measurement exit
        $past_unixdatetime = $cron_exit_condition_user_meta_arr['unixdatetime'];
        // get datetime object from timestamp
        $past = (new DateTime('@' . $past_unixdatetime))->setTimezone(new DateTimeZone("Asia/Kolkata"));
        // get the interval object
        $interval_since_last_change = $now->diff($past);
        // format the interval for display
        $formatted_interval = self::format_interval($interval_since_last_change);

        
        $format_object->soc_percentage_now_html = '<span style="font-size: 18px;color: Blue; display:block; text-align: center;">' . 
                                                      '<strong>' . $SOC_percentage_now . ' %' . '</strong><br>' .
                                                  '</span>';
        $format_object->cron_exit_condition = '<span style="color: Blue; display:block; text-align: center;">' .
                                                    $formatted_interval   . ' ' . $saved_cron_exit_condition  .
                                              '</span>';
        return $format_object;
    }

    /**
     * 
     */
    public function get_arrow_size_based_on_power($power)
    {
        switch (true)
        {
            case ($power > 0.0 && $power < 1.0):
                return " fa-1x ";

            case ($power >= 1.0 && $power < 2.0):
                return " fa-2x ";

            case ($power >= 2.0 && $power < 3.0):
                return " fa-3x ";

            case ($power >= 3.0 ):
              return " fa-4x ";
        }
    }

    /**
     * Format an interval to show all existing components.
     * If the interval doesn't have a time component (years, months, etc)
     * That component won't be displayed.
     *
     * @param DateInterval $interval The interval
     *
     * @return string Formatted interval string.
     */
    public function format_interval(DateInterval $interval) {
      $result = "";
      if ($interval->y) { $result .= $interval->format("%y years "); }
      if ($interval->m) { $result .= $interval->format("%m months "); }
      if ($interval->d) { $result .= $interval->format("%d d "); }
      if ($interval->h) { $result .= $interval->format("%h h "); }
      if ($interval->i) { $result .= $interval->format("%i m "); }
      if ($interval->s) { $result .= $interval->format("%s s "); }

      return $result;
    }

}

avas_solar::init();