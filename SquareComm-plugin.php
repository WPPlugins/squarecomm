<?php

/**
 * @package SquareComm 
 * @author Square State Software 
 * @version 2.1.2
 */

/*
Plugin Name: SquareComm 
Plugin URI: https://www.squarestatesoftware.com/SquareComm-plugin
Description: eCommerce REST API. Written for the SquareComm mobile app but may support any client app conforming to the published API.
Author: Square State Software
Version: 2.1.2
Author URI: https://www.squarestatesoftware.com
License: GPLv2 or later
Text Domain: squarecomm 
*/

/*
Copyright 2015  Tom Mills (https://www.squarestatesoftware.com)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License, version 2, as 
published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

if( !defined('$debug') )
       	define( '$debug', 0);
$debug = 0;

//
// SquareComm API
//
if (!class_exists('squarecommapi'))
{
	require_once (dirname(__FILE__) . '/SquareCommAPI/SquareCommAPI.php');
}

//----------------------------------------------------------------------------
// query vars wordpress hook
//----------------------------------------------------------------------------
function sqss_squarecomm_plugin_query_vars($vars)
{
	$vars[] = 'myplugin';
	$vars[] = 'dbname';
	$vars[] = 'debug';
	$vars[] = 'uuid';
	$vars[] = 'request';
	$vars[] = 'prodID';
	$vars[] = 'upc';

	return $vars;
}

//----------------------------------------------------------------------------
// parse request wordpress hook
//----------------------------------------------------------------------------
function sqss_squarecomm_plugin_parse_request($wp)
{
	global $wpdb;
        global $db;
        global $debug;
        global $uuid;
        global $plugin;
        global $req;

        global $errCode;

        $debug = $wp->query_vars['debug'];

	if ($debug > 0)
	{
		error_log(__METHOD__."; _SERVER: ".print_r($_SERVER,true));
	}

	if (!(array_key_exists('myplugin', $wp->query_vars) && $wp->query_vars['myplugin'] == 'SquareComm-plugin'))
	{
		goto noop;
	}
	if (class_exists('squarecommapi'))
	{
                if ($debug > 0)
                {
			error_log(__METHOD__."; query_vars: ".print_r($wp->query_vars,true));
                }
	}
	else
	{
		// return valid http error code
		return 1;
	}

        $wp->query_vars['uploddir'] = wp_upload_dir();

	if (array_key_exists('request', $wp->query_vars))
        	$request = $wp->query_vars['request'];

	if (array_key_exists('uuid', $wp->query_vars))
        	$uuid = $wp->query_vars['uuid'];

        $vars = array();

	if ($request == sqss_request_type::sqss_req_handshake)
	{
		goto out;
	}

        $vars['body'] = 'YES';

out:

        $vars['myplugin'] = $wp->query_vars['myplugin'];
        $vars['debug'] = $wp->query_vars['debug'];
        $vars['dbname'] = $wp->query_vars['dbname'];
        $vars['uuid'] = $wp->query_vars['uuid'];

        $squarecomm = new squarecommapi($request, $_SERVER['HTTP_ORIGIN'], $vars);
	echo $squarecomm->processAPI("0");

	exit();

err:

	$request = "error/".$errCode."/".$request;

	if ($debug > 1)
	{
		error_log(__METHOD__."; error request: ".$request);
	}

	goto out;

noop:

	if ($debug > 1)
	{
		error_log(__METHOD__."; no-op request: ".$request);
	}

	$request = "sqss_req_ignore";

	return;
}

// Add support for custom vars
add_filter('query_vars', 'sqss_squarecomm_plugin_query_vars');
 
// Process the custom URL
add_action('parse_request', 'sqss_squarecomm_plugin_parse_request');

?>
