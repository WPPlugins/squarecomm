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

//----------------------------------------------------------------------------
// SQSSAPI.PHP - script used to query/update a wordpress database
//----------------------------------------------------------------------------

if( !defined('SQSSAPI_DEBUG') )
	define( 'SQSSAPI_DEBUG', 0 );

//----------------------------------------------------------------------------

abstract class sqssapi
{
	/**
	* Property: method
	* The HTTP method this request was made in, either GET, POST, PUT or DELETE
	*/
	protected $method = '';

	/**
	* Property: endpoint
	* The Model requested in the URI. eg: /files
	*/
	protected $endpoint = '';

	/**
	* Property: verb
	* An optional additional descriptor about the endpoint, used for things that can
	* not be handled by the basic methods. eg: /files/process
	*/
	protected $verb = '';

	/**
	* Property: args
	* Any additional URI components after the endpoint and verb have been removed, in our
	* case, an integer ID for the resource. eg: /<endpoint>/<verb>/<arg0>/<arg1>
	* or /<endpoint>/<arg0>
	*/
	protected $args = Array();

	/**
	* Property: file
	* Stores the input of the PUT request
	*/
	protected $file = Null;

	/**
	* Constructor: __construct
	* Allow for CORS, assemble and pre-process the data
	*/
	public function __construct($request)
	{
		if (SQSSAPI_DEBUG > 1)
		{
			error_log(__METHOD__."; _SERVER: ".print_r($_SERVER,true));
		}

		header("Access-Control-Allow-Headers: Origin, X-Atmosphere-tracking-id, X-Atmosphere-Framework, X-Cache-Date, Content-Type, X-Atmosphere-Transport, *");
		header("Access-Control-Allow-Methods: POST, GET, OPTIONS , PUT");
		header("Access-Control-Allow-Origin: *");
		header("Access-Control-Request-Headers: Origin, X-Atmosphere-tracking-id, X-Atmosphere-Framework, X-Cache-Date, Content-Type, X-Atmosphere-Transport,  *");
		header("Content-Type: application/json");

		//$this->args = explode('/', rtrim($request, '/'));
		$request = rtrim($request, '/');
		$this->args = explode('/', ltrim($request, '/'));
		$this->endpoint = array_shift($this->args);

		if (array_key_exists(0, $this->args) && !is_numeric($this->args[0]))
		{
			$this->verb = array_shift($this->args);
		}

		if ($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])
		{
			$this->method = $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'];
		}
		else
		{
			$this->method = $_SERVER['REQUEST_METHOD'];
		}

		if (SQSSAPI_DEBUG > 0)
		{
			error_log(__METHOD__."; request: ".$request);
			error_log(__METHOD__."; args: ".print_r($this->args,true));
			error_log(__METHOD__."; endpoint: ".$this->endpoint);
			error_log(__METHOD__."; method: ".$this->method);
			error_log(__METHOD__."; verb: ".$this->verb);
		}

		if ($this->method == 'POST' && array_key_exists('HTTP_X_HTTP_METHOD', $_SERVER))
		{
			if ($_SERVER['HTTP_X_HTTP_METHOD'] == 'DELETE')
			{
				$this->method = 'DELETE';
			}
			else if ($_SERVER['HTTP_X_HTTP_METHOD'] == 'PUT')
			{
				$this->method = 'PUT';
			}
			else
			{
				throw new Exception("Unexpected Header");
			}
		}

		switch($this->method)
		{
			case 'DELETE':
			case 'POST':
				$this->request = $this->_scrub($_POST);
				$this->file = file_get_contents("php://input");
				break;
			case 'GET':
				$this->request = $this->_scrub($_GET);
				$this->file = file_get_contents("php://input");
				break;
			case 'PUT':
				$this->request = $this->_scrub($_POST);
				$this->file = file_get_contents("php://input");
				break;
			default:
				$this->_response(__METHOD__.'; Unsupported Method', 405);
				break;
		}
	}

	public function processAPI($format)
	{
		if ((int)method_exists($this, $this->endpoint) > 0)
		{
			return $this->_response($this->{$this->endpoint}($this->args),$format);
		}
		return $this->_response(__METHOD__ ."; No Endpoint: $this->endpoint", $format, 404);
	}

	private function _response($data, $format, $status = 200)
	{
		header("HTTP/1.1 " . $status . " " . $this->_requestStatus($status));

		if ($format)
		{
			return json_readable_encode($data, $indent = 0, $from_array = true);
		}
		else
		{
			return json_encode($data);
		}
	}

	private function _scrub($data)
	{
		$clean_input = Array();
		if (is_array($data))
		{
			foreach ($data as $k => $v)
			{
				$clean_input[$k] = $this->_scrub($v);
			}
		}
		else
		{
			$clean_input = trim(strip_tags($data));
		}
		return $clean_input;
	}

	private function _requestStatus($code)
	{
		$status = array(  
			200 => 'OK',
			404 => 'Not Found',   
			405 => 'Method Not Allowed',
			500 => 'Internal Server Error',
		); 
		return ($status[$code])?$status[$code]:$status[500]; 
	}

}

function json_readable_encode($in, $indent = 0, $from_array = false)
{
    $_myself = __FUNCTION__;
    $_escape = function ($str)
    {
        return preg_replace("!([\b\t\n\r\f\"\\'])!", "\\\\\\1", $str);
    };

    $out = '';

    foreach ($in as $key=>$value)
    {
        $out .= str_repeat("\t", $indent + 1);
        $out .= "\"".$_escape((string)$key)."\": ";

        if (is_object($value) || is_array($value))
        {
            $out .= "\n";
            $out .= $_myself($value, $indent + 1);
        }
        elseif (is_bool($value))
        {
            $out .= $value ? 'true' : 'false';
        }
        elseif (is_null($value))
        {
            $out .= 'null';
        }
        elseif (is_string($value))
        {
            $out .= "\"" . $_escape($value) ."\"";
        }
        else
        {
            $out .= $value;
        }

        $out .= ",\n";
    }

    if (!empty($out))
    {
        $out = substr($out, 0, -2);
    }

    $out = str_repeat("\t", $indent) . "{\n" . $out;
    $out .= "\n" . str_repeat("\t", $indent) . "}";

    return $out;
}

?>
