<?php
/**
 * PHP OPcache Plugin for Newrelic Copyright (C) 2014 Steven King <kingrst@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>
 */

/**
 * PHP OPcache Plugin for Newrelic
 *
 * PHP version 5
 *
 * @category	System
 * @package	System_Daemon
 * @author	Steven King &lt;kingrst@gmail.com&gt;
 * @copyright	2014 Steven King
 * @license	http://opensource.org/licenses/GPL-3.0
 * @link	http://kingrst.com/newrelic-phpopcache
 *
 */

  class NRPHPOPcache {
    protected $metrics = array();
    protected $instance_name = 'PHP OPcache';
    protected $plugin_guid = 'com.kingrst.phpopcache';
    protected $poll_cycle;
    protected $version = '1.0.1';

    protected $config_location = array( '.', '/etc/newrelic-phpopcache', '/etc/newrelic' );
    protected $config_name = 'newrelic-phpopcache.ini';
    protected $platform_api_uri = 'https://platform-api.newrelic.com/platform/v1/metrics';

    protected $api_error_codes = array (
      '400' => array(
        'name' => 'Bad Request',
	'desc' => 'The request or headers are in the wrong format, or the URL is incorrect, or teh GUID does not meet the validation requirements.'),
      '403' => array(
        'name' => 'Unauthorized',
	'desc' => 'Authenticatoin error (no license key header, or invalid license key).'),
      '404' => array(
        'name' => 'Not Found',
	'desc' => 'Invalid URL.'),
      '405' => array(
        'name' => 'Method Not Allowed',
	'desc' => 'Returned if the method is an invalid or unexpected type (GET/POST/PUT/etc.).'),
      '413' => array(
        'name' => 'Request Entity Too Large',
	'desc' => 'Too many metrics were sent in one request, or too many components (instances) were specified in onerequest, or other single-request limits were reached.'),
      '500' => array(
        'name' => 'Internal Server Error',
	'desc' => 'Unexpected server error.'),
      '502' => array(
        'name' => 'Bad Gateway',
	'desc' => 'All 50X errors mean there is a transient problem in the server completing requests, and no data has been retained. Clients are expected to resent the data after waiting one minute. The data should be aggregated appropriately, combining multiple timeslice data values for the same metric into a single aggregated timeslice data value.'),
      '503' => array(
        'name' => 'Service Unavailable',
	'desc' => 'See 502 description.'),
      '504' => array(
        'name' => 'Gateway Timeout',
	'desc' => 'See 502 description.'));

    protected $hostname;
    protected $pid;
    protected $license_key;

    public function __construct() {
      // Set the pid of the PHP process
      $this->pid = getmypid();
      $this->load_conf();

      return 0;
    }

    private function is_opcache_enabled() {
      return ini_get('opcache.enable');
    }

    private function load_conf() {
      foreach ( $this->config_location as $config ) {
        if (file_exists($config.'/'.$this->config_name)) {
	  $full_config_path = $config.'/'.$this->config_name;

	  $config_values = parse_ini_file($full_config_path, false);

	  // If poll_cycle is not specified in the configuration, set to default
	  if ( !isset($config_values['poll_cycle']) || !is_numeric($config_values['poll_cycle'] ) ) {
            $this->poll_cycle = 60;
	  } else {
            $this->poll_cycle = $config_values['poll_cycle'];
          }

	  // If hostname is not specfied in the configuration, auto detect it
	  if ( !isset($config_values['hostname']) || $config_values['hostname'] == NULL ) {
	    $this->hostname = php_uname('n');
          } else {
            $this->hostname = $config_values['hostname'];
	  }

	  // If instance_name is not specified in the configuration, leave it at the default
	  if ( !isset($config_values['instance_name']) || $config_values['instance_name'] == NULL ) {
            $this->instance_name = 'Default PHP OPcache Instance';
	  } else {
            $this->instance_name = $config_values['instance_name'];
          }

	  // If the liense_key is set in the config then set it, else throw an error code
	  if ( isset($config_values['license_key']) ) {
            $this->license_key = $config_values['license_key'];
	  } else {
	    echo 'No license_key found in configuration';
            exit(31);
	  }

	  // A config file was found and successfully parsed, return true
	  return true;
        }
      }

      // No config files were found, return false
      echo 'No configuration file was found';
      exit(20);
    }

    // This function is to be called after initializing the object. This actually starts the process
    public function run() {
      // Refresh the OPcache statistics
      $this->gather_metrics();

      // Send the metrics to Newrelic
      $post_result = $this->post_metrics();

      if ( $post_result['http_error_code'] == 200 ) {
        echo $post_result['http_data'];
      } else {
        echo $post_result['http_data']."\n\n HTTP Error Code: ".$post_result['http_error_code'];
      }

      return 0;
    }

    private function gather_metrics() {
      $opcache_stats = opcache_get_status();
      $opcache_conf = opcache_get_configuration();

      $this->metrics = array(
          'agent' => array(
                  'host' => $this->hostname,
                  'pid' => $this->pid,
                  'version' => $this->version),
          'components' => array( array(
                  'name' => $this->instance_name,
                  'guid' => $this->plugin_guid,
                  'duration' => $this->poll_cycle,
                  'metrics' => array (
                          'Component/PHPOPcache/Memory/Used[bytes]' => $opcache_stats['memory_usage']['used_memory'],
                          'Component/PHPOPcache/Memory/Free[bytes]' => $opcache_stats['memory_usage']['free_memory'],
                          'Component/PHPOPcache/Memory/Wasted[bytes]' => $opcache_stats['memory_usage']['wasted_memory'],
			  'Component/PHPOPcache/Cache/TotalScripts[scripts]' => $opcache_stats['opcache_statistics']['num_cached_scripts'],
			  'Component/PHPOPcache/Cache/HitRate[percent]' => $opcache_stats['opcache_statistics']['opcache_hit_rate'],
                          'Component/PHPOPcache/Cache/Keys/Current[keys]' => $opcache_stats['opcache_statistics']['num_cached_keys'],
                          'Component/PHPOPcache/Cache/Keys/Max[keys]' => $opcache_stats['opcache_statistics']['max_cached_keys'],
                          'Component/PHPOPcache/Cache/Scripts/Hits[scripts]' => $opcache_stats['opcache_statistics']['hits'],
			  'Component/PHPOPcache/Cache/Scripts/Misses[scripts]' => $opcache_stats['opcache_statistics']['misses'],
                          'Component/PHPOPcache/Cache/Blacklist/Misses[bscripts]' => $opcache_stats['opcache_statistics']['blacklist_misses'],
                          'Component/PHPOPcache/Cache/Restarts/OOM[restarts]' => $opcache_stats['opcache_statistics']['oom_restarts'],
			  'Component/PHPOPcache/Cache/Restarts/Hash[restarts]' => $opcache_stats['opcache_statistics']['hash_restarts'],
			  'Component/PHPOPcache/Cache/Restarts/Manual[restarts]' => $opcache_stats['opcache_statistics']['manual_restarts'],
                  )
          ))
      );

      return true;
    }

    private function post_metrics() {
      // Convert our metrics array to json
      $json_metrics = json_encode($this->metrics);

      $post_options = array(
        'http' => array(
	  'header' => "X-License-Key: ".$this->license_key."\r\nContent-type: application/json\r\nAccept: application/json",
	  'method' => 'POST',
	  'content' => $json_metrics,
	),
      );

      $context = stream_context_create($post_options);
      $post_result = file_get_contents($this->platform_api_uri, false, $context);

      preg_match("/(\d+){3}/", $http_response_header[0], $preg_matches);

      $return_status = array( 'http_error_code' => $preg_matches[0], 'http_data' => $post_result );

      return $return_status;
    }
  }

  if ( php_sapi_name() == 'cli' ) {
    echo "Please run this code via a web browser. The OPcache statistics will not be accurate if ran from the command line!\n";

    die();
  }

  if ( isset($_REQUEST['test']) ) {
    echo "Success! Newrelic-phpopcache appears to be installed successfully.\n";
    die();
  }

  $nr = new NRPHPOPcache();
  $nr->run();
?>
