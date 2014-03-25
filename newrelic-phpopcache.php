#!/usr/bin/php -q
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

  require_once "System/Daemon.php";

  class newrelic-phpopcache {
    protected $runmode = array();
    protected $metrics = array();
    protected $instance_name = 'PHP OPcache';
    protected $plugin_guid = 'com.kingrst.phpopcache';
    protected $version = '1.0.0';
    protected $poll_cycle = 60;
    protected $config_location = array( '.', '/etc/newrelic-phpopcache' );
    protected $config_name = 'newrelic-phpopcache';
    protected $platform_api_uri = 'https://platform-api.newrelic.com/platform/v1/metrics';
    protected $run_once = false;

    protected $hostname;
    protected $pid;
    protected $license_key;

    public function __construct( $args ) {
      $this->pid = getmypid();

      // Allowed arguments & their defaults
      $this->runmode = array(
        'no-daemon' => false,
	'help' => false,
	'daemonize' => true
      );

      $config_code = $this->load_config();

      if ($config_code == 21) {
        echo 'No license_key found in configuration';
        exit(21);
      } elseif ($config_code == false ) {
        echo 'No configuration file was found';
        exit(20);
      }

      return 0;
    }

    private load_config() {
      foreach ( $this->config_location as $config ) {
        if (stat($config.'/'.$this->config_name)) {
	  $full_config_path = $config.'/'.$this->config_name;

	  $config_values = parse_ini_file($full_config_path, false);

	  // If hostname is not specfied in the configuration, auto detect it
	  if ( !isset($config_values['hostname']) || $config_values['hostname'] == NULL ) {
	    $this->hostname = php_uname('n');
          } else {
            $this->hostname = $config_values['hostname'];
	  }

	  // If instance_name is not specified in the configuration, leave it at the default
	  if ( isset($config_values['instance_name'] || !$config_values['instance_name'] == NULL ) {
	    $this->instance_name = $config_values['instance_name'];
	  }

	  // If the liense_key is set in the config then set it, else throw an error code
	  if ( isset($config_values['license_key']) ) {
            $this->license_key = $config_values['license_key'];
	  } else {
	    return 21;
	  }

          // If the platform_api_uri is set in the config then set it, else leave it as the default
          if ( isset($config_values['platform_api_uri']) || !$config_values['platform_api_uri'] == NULL ) {
            $this->platform_api_uri = $config_values['platform_api_uri'];
          }

	  // A config file was found and successfully parsed, return true
	  return true;
        }
      }

      // No config files were found, return false
      return false;
    }

    private start_daemon() {
    }

    private stop_daemon() {
    }

    private show_help() {
    }

    private run_once() {
      // Reload the configuration file values in case they were updated
      $this->load_config();

      // Refresh the OPcache statistics
      $this->gather_metrics();

      // Send the metrics to Newrelic
      $this->post_metrics();

      return 0;
    }

    private gather_metrics() {
      $opcache_stats = opcache_get_status();

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
                          'Component/PHPOPcache/Memory/Used[count]' => $opcache_stats['memory_usage']['used_memory'],
                          'Component/PHPOPcache/Memory/Free[count]' => $opcache_stats['memory_usage']['free_memory'],
                          'Component/PHPOPcache/Memory/Wasted[count]' => $opcache_stats['memory_usage']['wasted_memory'],
                          'Component/PHPOPcache/Cache/TotalScripts[count]' => $opcache_stats['opcache_statistics']['num_cached_scripts'],
                          'Component/PHPOPcache/Cache/Keys/Current[count]' => $opcache_stats['opcache_statistics']['num_cached_keys'],
                          'Component/PHPOPcache/Cache/Keys/Max[count]' => $opcache_stats['opcache_statistics']['max_cached_keys'],
                          'Component/PHPOPcache/Cache/Scripts/Hits[count]' => $opcache_stats['opcache_statistics']['hits'],
			  'Component/PHPOPcache/Cache/Scripts/Misses[count]' => $opcache_stats['opcache_statistics']['misses'],
                          'Component/PHPOPcache/Cache/Blacklist/Misses[count]' => $opcache_stats['opcache_statistics']['blacklist_misses'],
                          'Component/PHPOPcache/Cache/Restarts/OOM[count]' => $opcache_stats['opcache_statistics']['oom_restarts'],
			  'Component/PHPOPcache/Cache/Restarts/Hash[count]' => $opcache_stats['opcache_statistics']['hash_restarts'],
			  'Component/PHPOPcache/Cache/Restarts/Manual[count]' => $opcache_stats['opcache_statistics']['manual_restarts'],
                  )
          ))
      );

      return true;
    }

    private post_metrics() {
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

      return $post_result;
    }
  }

  System_Daemon::setOption('PHPOPcacheNewrelic', 'phpocnr');
  System_Daemon::start();
?>
