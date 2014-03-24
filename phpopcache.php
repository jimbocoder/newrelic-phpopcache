#!/usr/bin/php
<?php
  $plugin_name = 'PHP Opcache';
  $guid = 'com.kingrst.phpopcache';
  $version = '1.0.0.2';
  $hostname = php_uname('n');
  $pid = getmypid();
  $license_key = '88153707775afdf5a2b7f3eb590951151a555a72';

  $platform_api_uri = 'https://platform-api.newrelic.com/platform/v1/metrics';
  $poll_cycle = 60;

  $metrics = array(
	  'agent' => array(
		  'host' => $hostname,
		  'pid' => $pid,
		  'version' => $version),
	  'components' => array( array(
		  'name' => $plugin_name,
		  'guid' => $guid,
		  'duration' => $poll_cycle,
		  'metrics' => array (
			  'Component/PHPOpcache/Memory/Used[count]' => 520041,
			  'Component/PHPOpcache/Memory/Free[count]' => 750049,
			  'Component/PHPOpcache/Memory/Wasted[count]' => 0,
			  'Component/PHPOpcache/Cache/TotalScripts[count]' => 834,
			  'Component/PHPOpcache/Cache/Keys/Current[count]' => 861,
			  'Component/PHPOpcache/Cache/Keys/Max[count]' => 7963,
			  'Component/PHPOpcache/Cache/Scripts/Hits[count]' => 67429,
			  'Component/PHPOpcache/Cache/Scripts/Misses[count]' => 839,
			  'Component/PHPOpcache/Cache/Blacklist/Misses[count]' => 0,
			  'Component/PHPOpcache/Cache/Restarts/OOM[count]' => 0,
			  'Component/PHPOpcache/Cache/Restarts/Hash[count]' => 0,
		  )
	  ))
  );

  $encoded_metrics = json_encode($metrics );

  $post_options = array(
          'http' => array(
                'header' => "X-License-Key: ".$license_key."\r\nContent-type: application/json\r\nAccept: application/json",
                'method' => 'POST',
                'content' => $encoded_metrics
        ),
);

  $context = stream_context_create($post_options);
  $result = file_get_contents($platform_api_uri, false, $context);

  var_dump($post_options);
  var_dump($result);
  $opcache_stats = opcache_get_status();

?>
