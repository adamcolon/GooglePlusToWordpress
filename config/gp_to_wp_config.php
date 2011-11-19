<?php
	require('gp_to_wp_settings.php');

	// Loading Wordpress Config ---------------- Do not Edit anything below
	$wordpress_config_path = dirname(dirname(dirname(__FILE__))).'/wp-config.php';
	if (file_exists($wordpress_config_path)){
		require($wordpress_config_path);
	}else{
		$wordpress_config_path = dirname(dirname(dirname(dirname(__FILE__)))).'/wp-config.php';
		if (file_exists($wordpress_config_path)){
			require($wordpress_config_path);
		}else{
			die('Wordpress Config Not Found In Current Directory or Previous Directory');
		}
	}
	$wordpress_settings['db_host'] = DB_HOST;
	$wordpress_settings['db_username'] = DB_USER;
	$wordpress_settings['db_password'] = DB_PASSWORD;
	$wordpress_settings['db_name'] = DB_NAME;
	$wordpress_settings['db_table_prefix'] = $table_prefix;
