<?php

/*
Copyright 2015  Tom Mills (http://www.squarestatesoftware.com)

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

//
// SQSS Server
//
require_once ('sqss_serverapi.php');

//----------------------------------------------------------------------------
// SQSS.PHP - SQSS global services 
//----------------------------------------------------------------------------

// Requests from the same server don't have a HTTP_ORIGIN header
if (!array_key_exists('HTTP_ORIGIN', $_SERVER))
{
	$_SERVER['HTTP_ORIGIN'] = $_SERVER['SERVER_NAME'];
}
try
{
	$uuid = "";
	$api = new sqss_serverapi($_REQUEST['request'], $_SERVER['HTTP_ORIGIN'], $uuid);

	$method = "PUT";

	if (array_key_exists('REQUEST_METHOD', $_SERVER))
	{
		$method = $_SERVER['REQUEST_METHOD'];
	}

	if ($method == "GET")
	{
		$result = $api->processAPI("1");
		echo print_r($result,true);
	}
	else
	{
		$result = $api->processAPI("0");
		echo print_r($result,true);
	}

}
catch (Exception $e)
{
	echo json_encode(Array('error' => $e->getMessage()));
}

?>
