<?php
/**
 * PHP OPcache Plugin for Newrelic Copyright (C) 2014 Steven King
 * <kingrst@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation, either version 3 of the License, or (at your option)
 * any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 *
 * You should have received a copy of the GNU General Public License along with
 * this program. If not, see <http://www.gnu.org/licenses/>
 */

/**
 * PHP OPcache Plugin for Newrelic
 *
 * PHP version 5
 *
 * @category    System
 * @package    System_Daemon
 * @author    Steven King &lt;kingrst@gmail.com&gt;
 * @copyright    2014 Steven King
 * @license    http://opensource.org/licenses/GPL-3.0
 * @link    http://kingrst.com/newrelic-phpopcache
 *
 */
namespace jimbocoder\Newrelic\Plugin;

class PHPOPcache
{
    protected $options = array ();

    protected function getDefaultOptions()
    {
        return array(
            'guid' => 'github.jimbcoder.newrelic_phpopcache',
            'pollCycle' => 60,
            'hostname' => function() { return php_uname('n'); },
            'instanceName' => function() { return php_uname('n'); },
            'licenseKey' => function() { throw new \Exception("Must set licenseKey option."); },
            'platformApiUri' => 'https://platform-api.newrelic.com/platform/v1/metrics',
        );
    }

    protected $version = '1.0.3';

    protected $apiErrorCodes = array (
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


    public function __construct(array $options = array())
    {
        if (!$this->isOpcacheEnabled()) {
            echo "OPcache is not enabled! Please add opcache.enable=1 to your php.ini file.";
            exit(1);
        }

        $this->options = array_merge($this->getDefaultOptions(), $options);
    }

    protected function getOption($option)
    {
        if ( !isset($this->options[$option]) ) {
            throw new \Exception("Undefined option: $option");
        }
        $value = $this->options[$option];
        return is_callable($value) ? $value() : $value;
    }

    private function isOpcacheEnabled()
    {
        return ini_get('opcache.enable');
    }

    // This function is to be called after initializing the object. This actually starts the process
    public function run()
    {
        // Send the metrics to Newrelic
        $post_result = $this->postMetrics($this->getStructuredUpdate());

        if ($post_result['http_error_code'] == 200) {
            echo $post_result['http_data'];
        } else {
            echo $post_result['http_data']."\n\n HTTP Error Code: ".$post_result['http_error_code'];
        }
    }

    private function getStructuredUpdate()
    {
        $opcache_stats = opcache_get_status();
        $opcache_conf = opcache_get_configuration();

        $opcache_stats['memory_usage']['percent_used'] = $opcache_stats['memory_usage']['used_memory'] / ($opcache_stats['memory_usage']['used_memory'] + $opcache_stats['memory_usage']['free_memory']) * 100;

        $metrics = array (
            'Component/PHPOPcache/Memory/Used[bytes]' => $opcache_stats['memory_usage']['used_memory'],
            'Component/PHPOPcache/Memory/Free[bytes]' => $opcache_stats['memory_usage']['free_memory'],
            'Component/PHPOPcache/Memory/Wasted[bytes]' => $opcache_stats['memory_usage']['wasted_memory'],
            'Component/PHPOPcache/Memory/PercentUsed[percent]' => $opcache_stats['memory_usage']['percent_used'],
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
        );

        $component = array(
            'name' => $this->getOption('instanceName'),
            'guid' => $this->getOption('guid'),
            'duration' => $this->getOption('pollCycle'),
            'metrics' => $metrics,
        );

        $agent = array(
            'host' => $this->getOption('hostname'),
            'pid' => getmypid(),
            'version' => $this->version
        );

        return array(
            'agent' => $agent,
            'components' => array($component),
        );
    }

    private function postMetrics($structuredUpdate)
    {
        $postOptions = array(
            'http' => array(
                'header' => "X-License-Key: ".$this->getOption('licenseKey')."\r\nContent-type: application/json\r\nAccept: application/json",
                'method' => 'POST',
                'content' => json_encode($structuredUpdate),
            ),
        );

        // TODO: guzzle
        $context = stream_context_create($postOptions);
        $post_result = file_get_contents($this->getOption('platformApiUri'), false, $context);

        preg_match("/(\d+){3}/", $http_response_header[0], $preg_matches);

        $return_status = array( 'http_error_code' => $preg_matches[0], 'http_data' => $post_result );

        return $return_status;
    }
}
