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
// SquareCommAPI.PHP - SquareComm api
//----------------------------------------------------------------------------

//
// required scripts 
//
require_once ('file.php');
require_once (dirname(__FILE__) . '/../SquareStateAPI/sqss_serverapi.php');

//----------------------------------------------------------------------------

class squarecommapi extends sqss_serverapi
{
	private $parms;
	private $db			= "";
	private $uuid			= "";
	private $plugin			= "";

	public $endpoint		= "";
	public $req			= "";
	public $payload			= "";
	public $reqstring		= "";
	public $dbname			= "";
	public $dbuser			= "";
	public $dbpasswd		= "";
	public $dbhost			= "";
	public $dbprefix		= "";
	public $msg			= "Server Operation Completed";
	public $stat			= "Success";
	public $valid			= "true";
	//public $cart 			= "Woocommerce";
	public $prefix			= "";
	public $version			= "1.1.0";
	public $sku			= "";
	public $debug			= "";
	public $uploaddir		= "";

	public $errCode			= 0;
	public $errbuff			= array();
	public $phpbuff			= array();

	protected $User;

	protected $fileapi;

	public function __construct($request, $origin, $query_vars)
	{
		global $wpdb;

		parent::__construct($request, $origin, $query_vars);

		error_log(__METHOD__);

		$this->plugin = $query_vars['myplugin'];
		$this->dbname = $query_vars['dbname'];
		$this->dbuser = $query_vars['dbuser'];
		$this->dbpasswd = $query_vars['dbpasswd'];
		$this->dbhost = $query_vars['dbhost'];
		$this->dbprefix = $query_vars['dbprefix'];
		$this->debug = $query_vars['debug'];
		$this->uuid = $query_vars['uuid'];
		$this->action = $query_vars['action'];

		$this->fileapi = new squarecommfile();

/*
		// Abstracted out for example
		$APIKey = new Models\APIKey();
		$User = new Models\User();

		if (!array_key_exists('apiKey', $this->request))
		{
			throw new Exception('No API Key provided');
		}
		else if (!$APIKey->verifyKey($this->request['apiKey'], $origin))
		{
			throw new Exception('Invalid API Key');
		}
		else if (array_key_exists('token', $this->request) && !$User->get('token', $this->request['token']))
		{
			throw new Exception('Invalid User Token');
		}
		$this->User = $User;

*/

		$old_error_handler = set_error_handler(array($this, 'php_error_handler'));

		if ($debug > 0)
		{
			if ($debug > 1)
			{
				$this->error_log(__METHOD__."; _SERVER: ".print_r($_SERVER,true));
			}
			$this->error_log(__METHOD__."; request: ".$request);
			$this->error_log(__METHOD__."; origin: ".$origin);
			$this->error_log(__METHOD__."; query_vars: ".print_r($query_vars,true));
		}

		$this->verb = $_SERVER['REQUEST_METHOD'];
		$this->url_elements = explode('/', $_SERVER['REQUEST_URI']);

		if ($this->debug > 1)
		{
			$this->error_log(__METHOD__."; url elements: ".print_r($this->url_elements,true));
		}

		$this->parse_args();

		if ($this->debug > 0)
		{
			$this->error_log(__METHOD__."; parms: ".print_r($this->parms,true));
		}

		if ($query_vars['body'] == 'YES')
		{
			//
			// override query_vars with request body parms
			//
			$this->dbname = $this->parms['dbname'];
			$this->dbuser = $this->parms['dbuser'];
			$this->dbpasswd = $this->parms['dbpasswd'];
			$this->dbhost = $this->parms['dbhost'];
			$this->dbprefix = $this->parms['dbprefix'];
			$this->action = $this->parms['action'];
			$this->prodID = $this->parms['prodID'];
			$this->sku = $this->parms['sku'];
		}

		if ($request == sqss_request_type::sqss_req_handshake)
		{
                        $this->action = 'GET';

                        //
                        // client has no way to know the database state until this call completes
                        //

			$this->dbname = $wpdb->dbname;
			$this->dbuser = $wpdb->dbuser;
			$this->dbpasswd = $wpdb->dbpassword;
			$this->dbhost = $wpdb->dbhost;
			$this->dbprefix = $wpdb->prefix;
		}

		if (empty($this->dbname))
		{
			$this->error_log(__METHOD__."; A database name must be provided: ");
		}
		else	
		{
			$wpdb = new wpdb($this->dbuser, $this->dbpasswd, $this->dbname, $this->dbhost);
			$wpdb->set_prefix($this->dbprefix);
			$wpdb->select($this->dbname);
		}

		if ($this->debug > 0)
		{
			error_log("wpdb->dbname: ".$wpdb->dbname);
			error_log("wpdb->dbuser: ".$wpdb->dbuser);
			error_log("wpdb->dbpasswd: ".$wpdb->dbpassword);
			error_log("wpdb->dbhost: ".$wpdb->dbhost);
			error_log("wpdb->prefix: ".$wpdb->prefix);
		}

		$this->uploaddir = wp_upload_dir();
	}

	// error handler function
	public function php_error_handler($errno, $errstr, $errfile, $errline)
	{
		if ($errfile == __FILE__)
		{
			$error = $errstr."; Line Number: ".$errline."; File: ".$errfile;
			$this->phpbuff[] = $error;
		}

		/* Don't execute PHP internal error handler */
		return true;
	}

	private function error_log($error)
	{
		$this->errbuff[] = $error."\n";
		error_log($error);
	}

	public function parse_args()
	{
		$parms = array();

		if (isset($_SERVER['QUERY_STRING']))
		{
			parse_str($_SERVER['QUERY_STRING'], $parms);
		}
 
		// now how about PUT/POST bodies? These override what we got from GET

		$content_type = false;
		if (isset($_SERVER['CONTENT_TYPE']))
		{
			$content_type = $_SERVER['CONTENT_TYPE'];
		}

		if ($this->debug > 0)
		{
			$this->error_log("content_type: ".$content_type);
		}

		$body = $this->file;	// parent class reads from input

		switch($content_type)
		{
			case "application/json":
				$body_params = json_decode($body);
				if ($body_params)
				{
					foreach($body_params as $param_name => $param_value)
					{
						$parms[$param_name] = $param_value;
					}
				}
				$this->format = "json";
				break;

			case "application/x-www-form-urlencoded":
				parse_str($body, $postvars);
				foreach($postvars as $field => $value)
				{
					$parms[$field] = $value;
				}
				$this->format = "html";
				break;

			default:
				// we could parse other supported formats here
				break;

		}
		$this->parms = $parms;
	}

	//----------------------------------------------------------------------------
	// REST endpoints 
	//----------------------------------------------------------------------------


	//----------------------------------------------------------------------------
	// error 
	//----------------------------------------------------------------------------
	protected function error($args)
	{
		$this->endpoint = $args[1];
		$this->reqstring = "sqss_req_".$this->endpoint;
		$this->errCode = $this->sqss_do_request(sqss_request_type::sqss_req_error, $args);

		if ($this->debug > 0)
		{
			error_log(__METHOD__."; args: ".print_r($args,true));
			error_log(__METHOD__."; payload: ".print_r($this->payload,true));
		}

		return $this->payload;
	}

	//----------------------------------------------------------------------------
	// handshake 
	//----------------------------------------------------------------------------
	protected function handshake($args)
	{
		if ($this->debug > 0)
		{
			$this->error_log(__METHOD__."; args: ".print_r($args,true));
		}

		$this->reqstring = "sqss_req_handshake";
		$this->endpoint = sqss_request_type::sqss_req_handshake;
		$this->errCode = $this->sqss_do_request($this->endpoint, $args);

		if ($this->debug > 1)
		{
			$this->error_log(__METHOD__."; payload: ".print_r($this->payload,true));
		}
		return $this->payload;
	}

	//----------------------------------------------------------------------------
	// user 
	//----------------------------------------------------------------------------
	protected function user($args)
	{
		if ($this->debug > 0)
		{
			$this->error_log(__METHOD__."; args: ".print_r($args,true));
		}

		$this->reqstring = "sqss_req_add_user";
		$this->endpoint = sqss_request_type::sqss_req_add_user;
		$this->errCode = $this->sqss_do_request($this->endpoint, $args);

		if ($this->debug > 1)
		{
			$this->error_log(__METHOD__."; payload: ".print_r($this->payload,true));
		}
		return $this->payload;
	}

	//----------------------------------------------------------------------------
	// customtypes 
	//----------------------------------------------------------------------------
	protected function customtypes($args)
	{
		$this->reqstring = "sqss_req_customtypes";
		$this->endpoint= sqss_request_type::sqss_req_customtypes;
		$this->errCode = $this->sqss_do_request($this->endpoint, $args);

		if ($this->debug > 0)
		{
			error_log(__METHOD__."; args: ".print_r($args,true));
			error_log(__METHOD__."; payload: ".print_r($this->payload,true));
		}
		return $this->payload;
	}

	//-----------------------------------------------------------------------------
	// products
	//----------------------------------------------------------------------------
	protected function products($args)
	{
		$this->reqstring = "sqss_req_product";
		$this->endpoint = sqss_request_type::sqss_req_product;
		$this->errCode = $this->sqss_do_request($this->endpoint, $args);

		if ($this->debug > 1)
		{
			$this->error_log(__METHOD__."; payload: ".print_r($this->payload,true));
		}
		return $this->payload;
	}

	//----------------------------------------------------------------------------
	// variations 
	//----------------------------------------------------------------------------
	protected function variations($args)
	{
		$this->reqstring = "sqss_req_variation";
		$this->endpoint = sqss_request_type::sqss_req_variation;
		$this->errCode = $this->sqss_do_request($this->endpoint, $args);

		if ($this->debug > 1)
		{
			$this->error_log(__METHOD__."; payload: ".print_r($this->payload,true));
		}
		return $this->payload;
	}

	//----------------------------------------------------------------------------
	// attributes 
	//----------------------------------------------------------------------------
	protected function attributes($args)
	{
		$this->reqstring = "sqss_req_attributes";
		$this->endpoint= sqss_request_type::sqss_req_attributes;
		$this->errCode = $this->sqss_do_request($this->endpoint, $args);

		if ($this->debug > 1)
		{
			$this->error_log(__METHOD__."; payload: ".print_r($this->payload,true));
		}
		return $this->payload;
	}

	//----------------------------------------------------------------------------
	// metakeys 
	//----------------------------------------------------------------------------
	protected function metakeys($args)
	{
		$this->reqstring = "sqss_req_metakeys";
		$this->endpoint = sqss_request_type::sqss_req_metakeys;
		$this->errCode = $this->sqss_do_request($this->endpoint, $args);

		if ($this->debug > 1)
		{
			$this->error_log(__METHOD__."; payload: ".print_r($this->payload,true));
		}
		return $this->payload;
	}

	//----------------------------------------------------------------------------
	// skus 
	//----------------------------------------------------------------------------
	protected function skus($args)
	{
		$this->reqstring = "sqss_req_skus";
		$this->endpoint= sqss_request_type::sqss_req_sku;
		$this->errCode = $this->sqss_do_request($this->endpoint, $args);

		if ($this->debug > 1)
		{
			$this->error_log(__METHOD__."; payload: ".print_r($this->payload,true));
		}
		return $this->payload;
	}

	//----------------------------------------------------------------------------
	// media 
	//----------------------------------------------------------------------------
	protected function media($args)
	{
		$this->reqstring = "sqss_req_media";
		$this->endpoint= sqss_request_type::sqss_req_media;
		$this->errCode = $this->sqss_do_request($this->endpoint, $args);

		if ($this->debug > 1)
		{
			$this->error_log(__METHOD__."; payload: ".print_r($this->payload,true));
		}
		return $this->payload;
	}

	//----------------------------------------------------------------------------
	// reset_sample 
	//----------------------------------------------------------------------------
	protected function reset_sample($args)
	{
		$this->reqstring = "sqss_req_reset_sample";
		$this->endpoint = sqss_request_type::sqss_req_reset_sample;
		$this->errCode = $this->sqss_do_request($this->endpoint, $args);

		if ($this->debug > 1)
		{
			$this->error_log(__METHOD__."; payload: ".print_r($this->payload,true));
		}
		return $this->payload;
	}

	//----------------------------------------------------------------------------
	// feedback 
	//----------------------------------------------------------------------------
	protected function feedback($args)
	{
		$this->reqstring = "sqss_req_feedback";
		$this->endpoint = sqss_request_type::sqss_req_feedback;
		$this->errCode = $this->sqss_do_request($this->endpoint, $args);

		if ($this->debug > 1)
		{
			$this->error_log(__METHOD__."; payload: ".print_r($this->payload,true));
		}
		return $this->payload;
	}

	//----------------------------------------------------------------------------
	// do request 
	//----------------------------------------------------------------------------
	public function sqss_do_request($request, $args)
	{
		$this->payload	= array();

		if ($this->debug > 1)
		{
			$this->error_log(__METHOD__."; request: ".$request."; args: ".print_r($args,true));
		}

		switch ($request) {
		    case sqss_request_type::sqss_req_error:

			$this->errCode = array_shift($args);

			goto err;

		    case sqss_request_type::sqss_req_handshake:

			$this->errCode = $this->handshake_request();
			if ($this->errCode != sqss_error::errSuccess)
				goto err;

			break;

		    case sqss_request_type::sqss_req_customtypes:

			$this->errCode = $this->customtype_query();
			if ($this->errCode != sqss_error::errSuccess)
				goto err;

			break;

		    case sqss_request_type::sqss_req_product:

			if ($this->action == 'GET')
			{
				$this->errCode = $this->product_query($args);
				if ($this->errCode != sqss_error::errSuccess)
					goto err;
			}
			else
			{
				if (array_key_exists('product', $args))
				{
					$product = stripslashes($args['product']);
	
					if ($this->debug > 1)
					{
						$this->error_log(__METHOD__."; product: ".print_r($product,true));
					}
					$this->req = json_decode($product, true);

					if (!$this->req || $this->req == "" || !isset($this->req))
					{
						$this->error_log(__METHOD__."; json error: ".sqss_json_error(json_last_error()));
						$this->error_log(__METHOD__."; errant json: ".$product);
						goto err;
					}
				}

				$this->errCode = $this->product_update($args);
				if ($this->errCode != sqss_error::errSuccess)
					goto err;
			}
			
			break;

		    case sqss_request_type::sqss_req_variation:

			if ($this->action == 'GET')
			{
				$this->errCode = $this->product_variation_query($args);
				if ($this->errCode != sqss_error::errSuccess)
					goto err;
			}
			else
			{
				if (array_key_exists('product', $args))
				{
					$product = stripslashes($args['product']);
	
					if ($this->debug > 1)
					{
						$this->error_log(__METHOD__."; product: ".print_r($product,true));
					}
					$this->req = json_decode($product, true);

					if (!$this->req || $this->req == "" || !isset($this->req))
					{
						$this->error_log(__METHOD__."; json error: ".sqss_json_error(json_last_error()));
						$this->error_log(__METHOD__."; errant json: ".$product);
						goto err;
					}
				}

				$this->errCode = $this->variation_update($args);
				if ($this->errCode != sqss_error::errSuccess)
					goto err;
			}

			break;

		    case sqss_request_type::sqss_req_attributes:

			if ($this->action == 'GET')
			{
				$this->errCode = $this->attribute_query();
				if ($this->errCode != sqss_error::errSuccess)
					goto err;
			}

			break;

		    case sqss_request_type::sqss_req_metakeys:

			if ($this->action == 'GET')
			{
				$this->errCode = $this->metakey_query();
				if ($this->errCode != sqss_error::errSuccess)
					goto err;
			}

			break;

		    case sqss_request_type::sqss_req_sku:

			if ($this->action == 'GET')
			{
				$this->errCode = $this->sku_query();
				if ($this->errCode != sqss_error::errSuccess)
					goto err;
			}

			break;

		    case sqss_request_type::sqss_req_media:

			if ($this->action == 'GET')
			{
				$this->errCode = $this->media_query();
				if ($this->errCode != sqss_error::errSuccess)
					goto err;
			}

			break;

		    case sqss_request_type::sqss_req_image:

			if ($this->action == 'GET')
			{
			}
			else
			{
				$this->errCode = $this->image_upload();
				if ($this->errCode != sqss_error::errSuccess)
				{
					$this->errCode = sqss_error::errorImage;
					goto err;
				}
			}

			break;

		    case sqss_request_type::sqss_req_feedback:

			$this->reqstring = "sqss_req_feedback";
			//$logdata = stripslashes($_POST['feedback']);

			if (array_key_exists('feedback', $args))
			{
				$feedback = stripslashes($args['feedback']);
	
				if ($this->debug > 0)
				{
					$this->error_log(__METHOD__."; feedback: ".print_r($feedback,true));
				}
				$this->req = json_decode($feedback, true);

				if (!$this->req || $this->req == "" || !isset($this->req))
				{
					$this->error_log(__METHOD__."; json error: ".sqss_json_error(json_last_error()));
					$this->error_log(__METHOD__."; errant json: ".$feedback);
					goto err;
				}
			}

			$this->errCode = $this->send_feedback($args);
			if ($this->errCode != sqss_error::errSuccess)
				goto err;

			break;

		    case sqss_request_type::sqss_req_add_user:

			$this->reqstring = "sqss_req_add_user";
			$this->errCode = $this->add_user($args);
			if ($this->errCode != sqss_error::errSuccess)
				goto err;

			break;

		    case sqss_request_type::sqss_req_reset_sample:

			$this->reqstring = "sqss_req_reset_sample";
			$this->errCode = $this->do_reset_sample();
			if ($this->errCode != sqss_error::errSuccess)
				goto err;

			break;

		    default:

			$this->reqstring = "sqss_req_unknown_request_type";
			$this->msg = __METHOD__."; error: unknown query type: $this->endpoint.";

			$this->errCode = array_shift($args);

			goto err;
		}

out:
		$rc = $this->errCode;
		$this->errText = $this->translate_errCode($rc);

		// status 
		$status = array('valid'		=> "$this->valid",
				'action'	=> "$this->action",
				'stat'		=> "$this->stat",
				'message'	=> "$this->msg",
				'version'	=> "$this->version",
				'plugin'	=> "$this->plugin",
				'endpoint'	=> "$this->endpoint",
				'dbname'	=> "$this->dbname",
				'uuid'		=> "$this->uuid",
				'errorcode'	=> "$this->errCode",
				'errortext'	=> "$this->errText"
		);

		$this->payload['sqss_status'] = $status;
		$this->payload['sqss_log'] = $this->errbuff;

		return $rc;

err:
		// error
		$this->error_log($this->msg);

		//
		$this->valid = "false";
		$this->stat = "fail";

		$rc = $this->errCode;
		$this->errText = $this->translate_errCode($this->errCode);

		goto out;
	}

	//----------------------------------------------------------------------------
	// handshake - return the default dbname, user, passwd 
	//----------------------------------------------------------------------------
	private function handshake_request()
	{
		global $wpdb;

		$handshake = array(
			'dbhost'	=> $this->dbhost,
			'dbprefix'	=> $wpdb->prefix,
			'dbname'	=> $this->dbname,
			'dbuser'	=> $this->dbuser,
			'dbpasswd'	=> $this->dbpasswd,
			'fieldtypes'	=> $wpdb->field_types
		);

		$this->payload['sqss_handshake'] = $handshake;

		return sqss_error::errSuccess;
	}

	//----------------------------------------------------------------------------
	// customtypes - custom field data types 
	//----------------------------------------------------------------------------
	private function customtype_query()
	{
		global $wpdb;

		// get data types

		$sql = "DESCRIBE $wpdb->postmeta";
		$res = $wpdb->get_results($sql);
		if ($wpdb->last_error)
		{
			$this->error_log(__METHOD__."; query error: ".$wpdb->last_error);
			return sqss_error::errProductQueryFail;
 		}
		$datatypes = json_decode(json_encode($res), true);
 
		$types = array();

		foreach ($datatypes as $type)
		{
			$typearray = array(
				'field'		=> $type['Field'],
				'type'		=> $type['Type']
			);

			$types[] = $typearray;
		}
		$this->payload['sqss_custom_datatypes'] = $types;

		return sqss_error::errSuccess;
	}

	//----------------------------------------------------------------------------
	// product query
	//----------------------------------------------------------------------------
	private function product_query($args)
	{
		global $wpdb;

		if ($this->debug > 0)
		{
			$this->error_log(__METHOD__."; args: ".print_r($args,true));
		}

		if (count($args) == 0)
		{
			$offset = 0;
			$count = -1;
		}
		else if (count($args) == 1)
		{
			$offset = 0;
			$count = 0;
			$prodID = $args[0];
		}
		else if (count($args) == 2)
		{
			if ($args[1] == "variations")
			{
				$this->reqstring = "sqss_req_variation";
				$this->endpoint = sqss_request_type::sqss_req_variation;

				return $this->product_variation_query($args);
			}
			else
			{
				$offset = $args[0];
				$count = $args[1];
			}
		}
		else if (count($args) > 2)
		{
			if ($args[1] == "variations")
			{
				return $this->product_variation_query($args);
			}
		}

		if ($this->debug > 0)
		{
			$this->error_log(__METHOD__."; offset: ".$offset."; count: ".$count);
			$this->error_log(__METHOD__."; prodID: ".$prodID);
		}

		//
		// products 
		//
		$sql = "SELECT ID, post_title, post_type, post_name, post_excerpt, post_modified "
			. "FROM $wpdb->posts "
			. "WHERE post_type = 'product' "
			.	"AND post_status = 'publish' "
			. "ORDER BY post_title";
		$res = $wpdb->get_results($sql);
		if ($wpdb->last_error)
		{
			$this->error_log(__METHOD__.":".__LINE__."; query error: ".$wpdb->last_error);
			return sqss_error::errProductQueryFail;
 		}
		$prods = json_decode(json_encode($res), true);
		if ($this->debug > 0)
		{
			$this->error_log(__METHOD__."; sql(0): ".$sql);
			$this->error_log(__METHOD__."; result count: ".count($prods));
		}

		//
		// product thumbnail image id's 
		//
		$sql = "SELECT post_id, meta_value "
			. "FROM $wpdb->postmeta "
			. "WHERE meta_key = '_thumbnail_id'";
		$res = $wpdb->get_results($sql);
		if ($wpdb->last_error)
		{
			$this->error_log(__METHOD__.":".__LINE__."; query error: ".$wpdb->last_error);
			return sqss_error::errGetMetaFail;
 		}
		$thumbids = json_decode(json_encode($res), true);
		if ($this->debug > 0)
		{
			$this->error_log(__METHOD__."; sql(1): ".$sql);
			$this->error_log(__METHOD__."; result count: ".count($thumbids));
		}

		//
		// product attached files
		//
		$sql = "SELECT post_id, meta_value "
			. "FROM $wpdb->postmeta "
			. "WHERE meta_key = '_wp_attached_file'";
		$res = $wpdb->get_results($sql);
		if ($wpdb->last_error)
		{
			$this->error_log(__METHOD__.":".__LINE__."; query error: ".$wpdb->last_error);
			return sqss_error::errGetMetaFail;
 		}
		$paths = json_decode(json_encode($res), true);
		if ($this->debug > 0)
		{
			$this->error_log(__METHOD__."; sql(2): ".$sql);
			$this->error_log(__METHOD__."; result count: ".count($paths));
		}

		//
		// product gallery
		//
		$sql = "SELECT post_id, meta_value "
			. "FROM $wpdb->postmeta "
			. "WHERE meta_key = '_product_image_gallery'";
		$res = $wpdb->get_results($sql);
		if ($wpdb->last_error)
		{
			$this->error_log(__METHOD__.":".__LINE__."; query error: ".$wpdb->last_error);
			return sqss_error::errGetMetaFail;
 		}
		$galleries = json_decode(json_encode($res), true);
		if ($this->debug > 0)
		{
			$this->error_log(__METHOD__."; sql(3): ".$sql);
			$this->error_log(__METHOD__."; result count: ".count($galleries));
		}

		//$upload_dir = wp_upload_dir();
		$urlarrays = array();

		foreach ($thumbids as $thumbid)
		{
			foreach ($paths as $path)
			{
				if ($path['post_id'] == $thumbid['meta_value'])
				{
					// found it
					$urlarray = array (
						'id'		=> $thumbid['post_id'],
						'thumbid'	=> $thumbid['meta_value'],
						'url'		=> $this->uploaddir['baseurl']."/".$path['meta_value']
					);
					$urlarrays[] = $urlarray;
					break;
				}
			}
		}

		for ($i = 0; $i < count($prods); $i++)
		{
			$prod = $prods[$i];
			$pID = $prod['ID'];

			if (!empty($prodID) && $prodID != $pID)
			{
				continue;
			}

			$prodImages = array();


			foreach ($urlarrays as $urlarray)
			{
				if ($pID == $urlarray['id'])
				{
					$urlid = $urlarray['thumbid'];
					$url = $urlarray['url'];
					$urlorig = $this->fileapi->get_original_fileurl($url);
					break;
				}
			}

			foreach ($galleries as $g)
			{
				if ($pID == $g['post_id'])
				{
					$gallery = $g['meta_value'];
					break;
				}
			}

			if ($this->debug > 0)
			{
				$this->error_log(__METHOD__."; product featured URL: ".$url->meta_value);
			}

			$urlarray = array(
				'id'		=> $urlid,
				'url'		=> $url,
				'urlorig'	=> $urlorig,
				'type'		=> "featured",
				'action'	=> 'no-op'		// default action
			);

			if ($this->debug > 0)
			{
				$this->error_log(__METHOD__."; urlarray: ".print_r($urlarray,true));
			}

			$prodImages[] = $urlarray;

			//
			// get gallery images
			//
			$gallery_ids = array();

			if ($gallery)
			{
				$gallery_ids = explode(",", $gallery);

				if ($this->debug > 0)
				{
					$this->error_log(__METHOD__."; gallery_ids: ".print_r($gallery_ids,true));
				}

				foreach ($gallery_ids as $gid)
				{
					//
					// find gid in paths
					//
					$gurl = "";

					foreach ($paths as $path)
					{
						if ($path['post_id'] == $gid)
						{
							// found it
							$gurl = $path['meta_value'];
							break;
						}
					}

					if ($gurl)
					{
						$url = $this->uploaddir['baseurl']."/$gurl";
						$urlorig = $this->get_original_fileurl($url);
	
						if ($this->debug > 0)
						{
							$this->error_log(__METHOD__."; product attachment URL: ".$url."; ID: ".$prod['post_id']."; key: ".$key);
						}

						$urlarray = array(
							'id'		=> $gid,
							'url'		=> $url,
							'urlorig'	=> $urlorig,
							'type'		=> "gallery",
							'action'	=> 'no-op'		// default action
						);
						$prodImages[] = $urlarray;
					}
				}
			}

			// get variation count
			$sql = "SELECT ID "
				. "FROM $wpdb->posts "
				. "WHERE post_parent = '$pID' "
				. 	"AND post_type = 'product_variation'";
			$res = $wpdb->get_results($sql);

			// product
			$product = array(
				'valid'			=> "true",
       		         	'uuid'			=> $this->uuid,
				'id'			=> $pID,
				'type'			=> $prod['post_type'],
				'name'			=> $prod['post_name'],
				'itemName'		=> $prod['post_title'],
				'description'		=> $prod['post_excerpt'],
				'modified'		=> $prod['post_modified'],
				'featuredUrl'		=> $url,
				'productImages'		=> $prodImages,
				'variationCount'	=> count($res)
				//'prod_meta_visible'	=> $prod_meta_visible,
			);
			$products[] = $product;
		}

		if (count($products))
		{
			$this->payload['sqss_products'] = $products;
			return sqss_error::errSuccess;
		}

		return sqss_error::errNoProducts;
	}

	//----------------------------------------------------------------------------
	// product variation query
	//----------------------------------------------------------------------------
	private function product_variation_query($args)
	{
		global $wpdb;

		if ($this->debug > 0)
		{
			$this->error_log(__METHOD__."; args: ".print_r($args,true));
		}

		if (count($args) == 0)
		{
			$offset = 0;
			$count = -1;
		}
		else if (count($args) == 1)
		{
			$offset = 0;
			$count = $args[0];
		}
		else if (count($args) == 2)
		{
			$offset = $args[0];
			$count = $args[1];
		}
		else if (count($args) == 3)
		{
			$varID = $args[2];
		}

		//$prodID = $args[0];
		$prodID = $this->prodID;
		$varID = $this->varID;

		if ($this->debug > 1)
		{
			$this->error_log(__METHOD__."; prodID: ".$prodID);
			$this->error_log(__METHOD__."; varID: ".$varID);
			$this->error_log(__METHOD__."; sku: ".$sku);
		}

		if (!empty($prodID))
		{
			$sql = "SELECT a.ID, a.post_parent, a.post_title, a.post_type, a.post_name, a.post_excerpt, a.post_modified, "
				. "b.meta_id, b.meta_key, b.meta_value "
				. "FROM $wpdb->posts a, $wpdb->postmeta b "
				. "WHERE a.post_parent = '$prodID' "
				. 	"AND a.ID = b.post_id "
				. 	"AND a.post_type = 'product_variation' "
				.	"AND a.post_status = 'publish' "
				. "ORDER BY post_parent, ID";
		}
		else
		{
			$sql = "SELECT a.ID, a.post_parent, a.post_title, a.post_type, a.post_name, a.post_excerpt, a.post_modified, "
				. "b.meta_id, b.meta_key, b.meta_value "
				. "FROM $wpdb->posts a, $wpdb->postmeta b "
				. "WHERE a.ID = b.post_id "
				. 	"AND a.post_type = 'product_variation' "
				.	"AND a.post_status = 'publish' "
				. "ORDER BY post_parent, ID";
		}

		if ($this->debug > 1)
		{
			$this->error_log(__METHOD__."; sql: ".$sql);
		}

		$res = $wpdb->get_results($sql);
		if ($wpdb->last_error)
		{
			$this->error_log(__METHOD__."; query error: ".$wpdb->last_error);
			return sqss_error::errVariationQueryFail;
 		}
		$vars = json_decode(json_encode($res), true);

		$var_meta_visible = array();
		$var_meta_hidden = array();

		$variations = array();

		$lastID = $vars[0]['ID'];

		foreach ($vars as $var)
		{
			$vID = $var['ID'];

			if (!empty($varID) && $varID != $vID)
			{
				continue;
			}
			
			if ($var['ID'] != $lastID)
			{
				$errCode = $this->create_variation($lastvar, $var_meta_visible, $var_meta_hidden, $variation);
				if ($errCode != sqss_error::errSuccess)
				{
					$this->error_log(__METHOD__."; script error: ".$errCode);
					return sqss_error::errVariationQueryFail;
				}
				$variations[] = $variation;
				$lastID = $var['ID'];
			}

			//
			// custom fields that are not editable and must be 'added' to the product
			// via the web interface (ie. wp-admin) in order to make them editable
			//
			if ($var['meta_key'][0] == '_')
			{
				$nkey = ltrim($var['meta_key'],"_");
				$var_meta_hidden["$nkey"] = ltrim($var['meta_value'], "_");
			}
			else
			{
				$nkey = $var['meta_key'];
				$var_meta_visible["$nkey"] = $var['meta_value'];
			}

			$lastvar = $var;
		}

		// create last variation
		$errCode = $this->create_variation($lastvar, $var_meta_visible, $var_meta_hidden, $variation);
		if ($errCode != sqss_error::errSuccess)
		{
			$this->error_log(__METHOD__."; script error: ".$errCode);
			return sqss_error::errVariationQueryFail;
		}
		$variations[] = $variation;

		$this->payload['sqss_variations'] = $variations;

		return sqss_error::errSuccess;
	}

	//----------------------------------------------------------------------------
	// create variation from compiled meta data 
	//----------------------------------------------------------------------------
	private function create_variation($var, &$var_meta_visible, &$var_meta_hidden, &$variation)
	{
		global $wpdb;

		$rc = sqss_error::errSuccess;

		// variation 
		$variation = array(
			'valid'			=> "true",
       		       	'uuid'			=> "$this->uuid",
			'id'			=> $var['ID'],
			'title'			=> $var['post_title'],
			'modified'		=> $var['post_modified'],
			'sku'			=> $var_meta_hidden['sku'],
			'var_meta_visible'	=> $var_meta_visible,
			'var_meta_hidden'	=> $var_meta_hidden
		);

		$parent = $var['post_parent'];
		$sql = "SELECT a.term_id, a.name, a.slug, b.taxonomy "
			. "FROM $wpdb->terms a, $wpdb->term_taxonomy b, $wpdb->term_relationships c "
			. "WHERE a.term_id = b.term_id "
			. 	"AND c.object_id = '$parent' "
			. 	"AND b.term_taxonomy_id = c.term_taxonomy_id "
			. 	"AND b.taxonomy LIKE 'pa_%' "
			. "ORDER BY term_id";

		$res = $wpdb->get_results($sql);
		if ($wpdb->last_error)
		{
			$this->error_log(__METHOD__."; query error: ".$wpdb->last_error);
			$rc = sqss_error::errTaxonomyQueryFail;
			goto out;
 		}
		$taxonomies = json_decode(json_encode($res), true);

		$attributes = array();

		foreach ($taxonomies as $taxonomy)
		{
			$attrname = "attribute_".$taxonomy['taxonomy'];
			$tax = substr($attrname, strlen("attribute_pa_"));
			$slug = $taxonomy['slug'];

			// does this variation contain this attribute ?
			foreach ($var_meta_visible as $key => $value)
			{
				if ($key == $attrname)
				{
					if (strtolower($value) == strtolower($taxonomy['slug']))
					{
						$id = $taxonomy['term_id'];
						$attribute = array(
							'id'		=> "$id",
							'name'		=> ucwords($taxonomy['name'])
						);
						$attributes["$tax"] = $attribute;
					}	
				}	
			}
		}

		if (!empty($attributes))
		{
			$variation['attributes'] = $attributes;
		}

out:

		unset($var_meta_visible);
		$var_meta_visible = array();
		unset($var_meta_hidden);
		$var_meta_hidden = array();

		return $rc;
	}

	//----------------------------------------------------------------------------
	// sku query
	//----------------------------------------------------------------------------
	private function sku_query()
	{
		global $wpdb;

		$sql = "SELECT a.ID, a.post_parent, a.post_title, a.post_type, a.post_name, a.post_excerpt, a.post_modified, "
			. "b.meta_id, b.meta_key, b.meta_value "
			. "FROM $wpdb->posts a, $wpdb->postmeta b "
			. "WHERE a.ID = b.post_id "
			. 	"AND a.post_type = 'product_variation' "
			.	"AND a.post_status = 'publish' "
			.	"AND b.meta_key = '_sku' "
			.	"AND b.meta_value != '' "
			. "ORDER BY post_parent, ID";
		$res = $wpdb->get_results($sql);
		if ($wpdb->last_error)
		{
			$this->error_log(__METHOD__."; query error: ".$wpdb->last_error);
			return sqss_error::errVariationQueryFail;
 		}
		$skurecs = json_decode(json_encode($res), true);

		$sql = "SELECT post_id, meta_key, meta_value "
			. "FROM $wpdb->postmeta "
			. "WHERE meta_key LIKE 'attribute_%' "
			. "ORDER BY post_id";
		$res = $wpdb->get_results($sql);
		if ($wpdb->last_error)
		{
			$this->error_log(__METHOD__."; query error: ".$wpdb->last_error);
			return sqss_error::errGetMetaFail;
 		}
		$attrrecs = json_decode(json_encode($res), true);

		$sql = "SELECT ID, post_title "
			. "FROM $wpdb->posts "
			. "WHERE post_type = 'product' "
			. 	"AND post_status = 'publish'";
		$res = $wpdb->get_results($sql);
		if ($wpdb->last_error)
		{
			$this->error_log(__METHOD__."; query error: ".$wpdb->last_error);
			return sqss_error::errGetMetaFail;
 		}
		$prods = json_decode(json_encode($res), true);

		unset($this->payload);

		$skus = array();

		foreach ($skurecs as $skurec)
		{
			$skuid = $skurec['meta_value'];

			$attributes = array();

			foreach ($attrrecs as $attrrec)
			{
				if ($skurec['ID'] == $attrrec['post_id'])
				{
					$keyStr = substr($attrrec['meta_key'], strlen("attribute_pa_"));

					$attribute = array(
						'key'		=> $keyStr,
						'value'		=> $attrrec['meta_value']
					);
					$attributes[] = $attribute;
				}
			}

			foreach ($prods as $prod)
			{
				if ($skurec['post_parent'] == $prod['ID'])
				{
					$name = $prod['post_title'];
					break;
				}
			}

			// sku 
			$sku = array(
				'name'		=> $name,
				'id'		=> $skurec['ID'],
				'sku'		=> "$skuid",
				'attributes'	=> $attributes
			);
			$skus[] = $sku;
		}
		$this->payload['sqss_skus'] = $skus;

		return sqss_error::errSuccess;
	}

	//----------------------------------------------------------------------------
	// attribute query - depends on ecommerce package (woocommerce, jigoshop, ???)
	//----------------------------------------------------------------------------
	private function attribute_query()
	{
		global $wpdb;

		$sql = "SELECT DISTINCT meta_key "
			. "FROM $wpdb->postmeta "
			. "WHERE meta_key LIKE 'attribute_%'";
		$keys = $wpdb->get_results($sql);
		if ($wpdb->last_error)
		{
			$this->error_log(__METHOD__."; query error: ".$wpdb->last_error);
			return sqss_error::errAttributesQueryFail;
 		}
 
		$metakeys = array();

		foreach ($keys as $key)
		{
			$metakeys[] = $key->meta_key;
		}
		$this->payload['sqss_attributes'] = $metakeys;

		return sqss_error::errSuccess;
	}

	//----------------------------------------------------------------------------
	// metakeys query - 
	//----------------------------------------------------------------------------
	private function metakey_query()
	{
		global $wpdb;

		$sql = "SELECT DISTINCT b.meta_key "
			. "FROM $wpdb->posts a, $wpdb->postmeta b "
			. "WHERE a.ID = b.post_id "
			. 	"AND a.post_type = 'product_variation' "
			.	"AND a.post_status = 'publish' "
			. 	"AND b.meta_key NOT LIKE '\_%' "
			.	"AND b.meta_key NOT LIKE 'attribute_%' "
			. "ORDER BY b.meta_key";

		$keys = $wpdb->get_results($sql);
		if ($wpdb->last_error)
		{
			$this->error_log(__METHOD__."; query error: ".$wpdb->last_error);
			return sqss_error::errVisibleQueryFail;
 		}
 
		$metakeys = array();

		foreach ($keys as $key)
		{
			$nkey = ltrim($key->meta_key,"_");
			$metakeys[] = $nkey;
		}
		$this->payload['sqss_metakeys'] = $metakeys;

		return sqss_error::errSuccess;
	}

	//----------------------------------------------------------------------------
	// media query
	//----------------------------------------------------------------------------
	private function media_query()
	{
		global $wpdb;

		$sql = "SELECT ID "
			. "FROM $wpdb->posts "
			. "WHERE post_type = 'attachment' "
			.	"AND post_mime_type = 'image' "
			.	"AND post_status = 'inherit'";
		$imgs = $wpdb->get_results($sql);
		if ($wpdb->last_error)
		{
			$this->error_log(__METHOD__."; query error: ".$wpdb->last_error);
			return sqss_error::errVisibleQueryFail;
 		}

		$images = array();

		foreach ($imgs as $img)
		{
			$sql = "SELECT ID "
				. "FROM $wpdb->postmeta "
				. "WHERE post_id = '$img->ID' "
				. 	"AND meta_key = '_wp_attachment_file'";
			$res = $wpdb->get_results($sql);
			if ($wpdb->last_error)
			{
				$this->error_log(__METHOD__."; query error: ".$wpdb->last_error);
				return sqss_error::errVisibleQueryFail;
 			}
			$url = json_decode(json_encode($res[0]), true);

			$images[] = $url;

			if ($this->debug > 0)
			{
				$this->error_log(__METHOD__."; url: ".$url);
			}
		}
		$this->payload['sqss_urls'] = $images;

		return sqss_error::errSuccess;
	}

	//----------------------------------------------------------------------------
	// add user 
	//----------------------------------------------------------------------------
	private function add_user($args)
	{
		global $wpdb;

		if ($this->debug > 0)
		{
			$this->error_log(__METHOD__."; args: ".print_r($args,true));
			$this->error_log(__METHOD__."; uuid: ".$uuid);
			$this->error_log(__METHOD__."; this->uuid: ".$this->uuid);
		}

		//$this->uuid = $uuid;
/*
		if (!$uuid)
		{
			$password = "sqss_app_user";
			$email = "support@squarestatesoftware.com";
			$this->uuid = $uuid;
		}
		else
		{
			$this->msg = "User: ".$this->userID." already exists";
			//$this->uuid = $this->userID;
			$rc = 0;
			goto out;
		}
*/
		return sqss_error::errSuccess;

out:
		return $rc;
	}

	//----------------------------------------------------------------------------
	// product update 
	//----------------------------------------------------------------------------
	private function product_update($args)
	{
		global $wpdb;

		if ($this->debug > 0)
		{
			$this->error_log(__METHOD__."; args: ".print_r($args,true));
		}

		if (count($args) == 0)
		{
			$offset = 0;
			$count = -1;
		}
		else if (count($args) == 1)
		{
			$offset = 0;
			$count = 0;
			$prodID = $args[0];
		}
		else if (count($args) == 2)
		{
			if ($args[1] == "variations")
			{
				$this->reqstring = "sqss_req_variation";
				$this->endpoint = sqss_request_type::sqss_req_variation;

				return $this->product_variation_update($args);
			}
			else
			{
				$offset = $args[0];
				$count = $args[1];
			}
		}
		else if (count($args) > 2)
		{
			if ($args[1] == "variations")
			{
				return $this->product_variation_update($args);
			}
		}

		$prodID	= $args[0];

		$product = $this->parms;

		if ($this->debug > 1)
		{
			$this->error_log(__METHOD__."; parms: ".print_r($this->parms,true));
			$this->error_log(__METHOD__."; offset: ".$offset."; count: ".$count);
			$this->error_log(__METHOD__."; product: ".$product);
		}

		$itemName       = $product['itemName'];
		$description	= $product['description'];

		if ($this->debug > 1)
		{
			$this->error_log(__METHOD__."; prodID: ".$prodID);
			$this->error_log(__METHOD__."; description: ".$description);
		}

		$sql = "SELECT ID, post_title, post_type, post_name, post_excerpt, post_modified "
			. "FROM $wpdb->posts "
			. "WHERE ID = '$prodID' "
			.	"AND post_type = 'product' "
			.	"AND post_status = 'publish'";

		$res = $wpdb->get_results($sql);
		if ($wpdb->last_error)
		{
			$this->error_log(__METHOD__."; query error: ".$wpdb->last_error);
			return sqss_error::errProductQueryFail;
 		}
		$prod = json_decode(json_encode($res[0]), true);

		$rc = sqss_error::errSuccess;

		//----------------------------------------------------------------------------
		// product update
		//----------------------------------------------------------------------------

		if ($this->debug > 1)
		{
			$this->error_log(__METHOD__."; args = ".print_r($args, true));
		}
		$desc = $this->req['description'];
 
		$sql = "UPDATE $wpdb->posts "
			. "SET post_title='$itemName' "
			. "SET post_excerpt='$desc' "
			. "WHERE ID = '$prodID'";

		$result = $wpdb->get_results($sql);
		if ($wpdb->last_error)
		{
			$this->error_log(__METHOD__."; query error: ".$wpdb->last_error);
			return sqss_error::errProductUpdateFail;
 		}

		if ($result == 0)
		{
			$this->msg = __METHOD__."; update product failed; product ID = '".$prodID."'";
			$rc = sqss_error::errProductUpdateFail;
			goto out;
		}

		//----------------------------------------------------------------------------
		// product images
		//----------------------------------------------------------------------------
		$images = $product['productImages'];

		if (isset($images))
		{
			if ($this->debug > 0)
			{
				$this->error_log(__METHOD__."; app images: ".print_r($images, true));
			}

			$new_gallery_ids = array();

			for ($i = 0, $cnt = count($images); $i < $cnt; ++$i)
			{
				//$dict = $images[$i];
				$action = $images[$i]['action'];

				if (array_key_exists('type', $images[$i]))
					$type = $images[$i]['type'];
				if (array_key_exists('id', $images[$i]))
					$id = $images[$i]['id'];
				if (array_key_exists('url', $images[$i]))
					$url = $images[$i]['url'];

				$attach_id = "";

				if ($this->debug > 0)
				{
					$this->error_log(__METHOD__."; url: ".$url."; type: ".$type."; id: ".$id."; action: ".$action);
				}

				if ($action == "no-op")
				{
					// no op

					if ($type == "featured")
					{
						// check that this is the featured image and if not, make it
	
						$sql = "SELECT meta_id, meta_key, meta_value "
							. "FROM $wpdb->postmeta "
							. "WHERE post_id = '$prodID' "
							. 	"AND meta_key = '_thumbnail_id'";
						$res = $wpdb->get_results($sql);
						if ($wpdb->last_error)
						{
							$this->error_log(__METHOD__."; query error: ".$wpdb->last_error);
							return sqss_error::errGetMetaFail;
			 			}

						$thumbkey = json_decode(json_encode($res), true);
						$thumbnail_id = $thumbkey['meta_value'];

						if ($thumbnail_id != $id)
						{
							if ($this->debug > 0)
							{
								$this->error_log(__METHOD__."; new featured image file; id: ".$id."; file: ".basename($url));
							}
	
							$sql = "UPDATE $wpdb->postmeta "
								. "SET _thumbnail_id='$id' "
								. "WHERE ID = '$prodID'";

							$result = $wpdb->get_results($sql);
							if ($wpdb->last_error)
							{
								$this->error_log(__METHOD__."; query error: ".$wpdb->last_error);
								return sqss_error::errProductUpdateFail;
					 		}

							if ($result == 0)
							{
								$this->error_log(__METHOD__."; product_update: post_meta update failed; thumbnail_id: ".$id);
								$rc = sqss_error::errProductUpdateFail;
								goto out;
							}
						}
					}
/*
					// get the attachments for the product image 
					$args = array(
						'post_mime_type'=> 'image',
						'post_parent'	=> $prodID 
					);

					$attachments = get_children($args);
	
					if (!empty ($attachments))
					{   
						foreach ($attachments as $attachment)
						{
							$sql = "SELECT meta_value "
								. "FROM $wpdb->postmeta "
								. "WHERE post_id = '$attachment->ID' "
								. 	"AND meta_key = '_wp_attached_file'";
							$val = $wpdb->get_results($sql);
							if ($wpdb->last_error)
							{
								$this->error_log(__METHOD__."; query error: ".$wpdb->last_error);
								return sqss_error::errGetMetaFail;
 							}
							$attachUrl = json_decode(json_encode($val[0]), true);
							if (!strcmp (basename($attachUrl), basename($url)))
							{
								$attach_id = $attachment->ID;
								break;
							}
						}
					}
*/
					$attach_id = $id;
				}
				else if ($action == "attach")
				{
					// link existing database image to the product

					$this->errCode = $this->fileapi->get_image_attachment($url, $attach_id);
					if ($this->errCode != sqss_error::errSuccess)
					{
						$rc = $this->errCode;
						goto out;
					}

					// update image info for client
					//$images[$i]['urlorig'] = $newurl;
					//$images[$i]['url'] = $newurl;
					//$images[$i]['action'] = "none";
				}
				else if ($action == "add")
				{
					// add image to the database and the product

					$name = $images[$i]['localName'];

					$urlorig = "";
					$newurl = "";

					$this->errCode = $this->add_file($name, $newurl, $attach_id);
					if ($this->errCode != sqss_error::errSuccess)
					{
						$rc = $this->errCode;
						goto out;
					}

					// update image info for client
					$images[$i]['urlorig'] = $newurl;
					$images[$i]['url'] = $newurl;
					$images[$i]['action'] = "none";
				}
				else if ($action == "replace")
				{
					// replace image in the database

					$name = $images[$i]['localName'];

					$newurl = "";
					$urlorig = "";

					if (array_key_exists('urlorig', $images[$i]))
					{
						$urlorig = $images[$i]['urlorig'];
					}
					
					$this->errCode = $this->replace_file($url, $name, $newurl, $attach_id);
					if ($this->errCode != sqss_error::errSuccess)
					{
						$rc = $this->errCode;
						goto out;
					}

					// update image info for client
					$images[$i]['url'] = $newurl;
					$images[$i]['action'] = "none";
				}
				else if ($action == "revert")
				{
					$this->errCode = $this->revert_file($url);
					if ($this->errCode != sqss_error::errSuccess)
					{
						$rc = $this->errCode;
						goto out;
					}
					$attach_id = $id;
				}
				else if ($action == "delete")
				{
					$this->errCode = $this->delete_file($url);
					if ($this->errCode != sqss_error::errSuccess)
					{
						$rc = $this->errCode;
						goto out;
					}
					$attach_id = $id;
				}
				else
				{
					$this->error_log(__METHOD__."; invalid action received from client;\naction: '".$action."';\nurl: ".$url);
					$rc = sqss_error::errProductUpdateFail;
					goto out;
				}


				// add to gallery ?
				if ($attach_id != "")
				{
					if ($type == "gallery")
					{
						// gallery image. add it to gallery (post_content)
						$key = array_search($attach_id, $new_gallery_ids);
						if ($key == "")
						{
							// id not in gallery, add it to the gallery
							$new_gallery_ids[] = $attach_id;
						}
					}
				}
			}      

			//----------------------------------------------------------------------------
			// update post gallery ids
			//----------------------------------------------------------------------------
			$id_string = implode(",",$new_gallery_ids);

			if ($id_string != "")
			{
				// XXX
				// XXX jigoshop post_content ????
				// XXX $format = '[gallery ids="%s"]';
				// XXX
				$sql = "UPDATE $wpdb->postmeta "
					. "SET _product_image_gallery='$id_string' "
					. "WHERE ID = '$prodID'";

				$result = $wpdb->get_results($sql);
				if ($wpdb->last_error)
				{
					$this->error_log(__METHOD__."; query error: ".$wpdb->last_error);
					$rc = sqss_error::errProductUpdateFail;
					goto out;
		 		}
				if ($result == 0)
				{
					$this->error_log(__METHOD__."; error: meta update failed; key = _product_image_gallery; value = ".$id_string);
					$rc = sqss_error::errProductUpdateFail;
					goto out;
				}
			}
		}

out:
		$this->payload['sqss_images'] = $images;

		return $rc;
	}

	//----------------------------------------------------------------------------
	// variation update 
	//----------------------------------------------------------------------------
	private function product_variation_update($args)
	{
		global $wpdb;

		if ($this->debug > 0)
		{
			$this->error_log(__METHOD__."; args: ".print_r($args,true));
		}

		if (count($args) == 0)
		{
			$offset = 0;
			$count = -1;
		}
		else if (count($args) == 1)
		{
			$offset = 0;
			$count = $args[0];
		}
		else if (count($args) == 2)
		{
			$offset = $args[0];
			$count = $args[1];
		}

		$prodID	= $args[0];

		$variation = json_decode(json_encode($this->parms), true);

		if ($this->debug > 0)
		{
			$this->error_log(__METHOD__."; prodID: ".$prodID);
			$this->error_log(__METHOD__."; product variation arg: ".print_r($variation,true));
		}

		$id		= $variation['id'];
		$parentID	= $variation['parent_id'];
		$number		= $variation['sku'];
		$metaHidden	= $variation['var_meta_hidden'];
		$metaVisible	= $variation['var_meta_visible'];

		$rc = sqss_error::errSuccess;

		//----------------------------------------------------------------------------
		// variation meta values
		//----------------------------------------------------------------------------

		if (isset($number))
		{
			//
			// 'SKU' only appears in a request when it is being added to the database
			//

			if ($this->debug > 0)
			{
				$this->error_log(__METHOD__."; variation['sku']: ".$number);
			}

			if (!empty($number))
			{
				$sql = "SELECT ID, post_title "
					. "FROM $wpdb->posts a, $wpdb->postmeta b "
					. "WHERE a.ID = b.post_id "
					. 	"AND a.post_type = 'product_variation' "
					.		"AND a.post_status = 'publish' "
					.		"AND b.meta_key = '_sku' "
					.		"AND b.meta_value = '$number' "
					. "ORDER BY post_title";

				$prods = $wpdb->get_results($sql);
				if ($wpdb->last_error)
				{
					$this->error_log(__METHOD__."; query error: ".$wpdb->last_error);
					$rc = sqss_error::errProductQueryFail;
					goto out;
		 		}

			}
	
			if (count($prods))
			{
				foreach ($prods as $prod)
				{
					if ($id != $prod->ID)
					{
						//
						// number exists in a different product variation
						// and the request is trying to add it again. 
						//
						$this->msg = __METHOD__."; error: SKU: ".$number." already used; existing product variation with this SKU: ".$prod->post_title;
						$rc = sqss_error::errVariationUpdateFail;
						goto out;
					}
				}
			}
			else if ($number != "")
			{
				//
				// number doesn't exist in database or it's empty.
				//
				$sql = "UPDATE $wpdb->postmeta "
					. "SET _sku='$number' "
					. "WHERE ID = '$id'";

				$result = $wpdb->get_results($sql);
				if ($wpdb->last_error)
				{
					$this->error_log(__METHOD__."; query error: ".$wpdb->last_error);
					$rc = sqss_error::errVariationUpdateFail;
					goto out;
		 		}

				if ($result == 0)
				{
					$this->msg = sprintf(__METHOD__."; error: meta update failed; key = sku; value = ".$number);
					$rc = sqss_error::errVariationUpdateFail;
					goto out;
				}
			}
		}

		//
		// meta keys
		//

		foreach ($metaHidden as $key => $value)
		{
			if (isset($value))
			{
				$args = array(
					'id'	=> $id,
					'key'	=> "_".$key,
					'value'	=> $value
				);
				$this->update_meta($args);
			}
		}


		foreach ($metaVisible as $key => $value)
		{
			if (isset($value))
			{
				$args = array(
					'id'	=> $id,
					'key'	=> $key,
					'value'	=> $value
				);
				$this->update_meta($args);
			}
		}

out:
		return $rc;
	}

	//----------------------------------------------------------------------------
	// product meta: generic meta update
	//----------------------------------------------------------------------------
	private function update_meta($args)
	{
		global $wpdb;

		$id = $args['id'];
		$key = $args['key'];
		$value = $args['value'];

		$sql = "SELECT meta_key, meta_value "
			. "FROM $wpdb->postmeta "
			. "WHERE post_id = '$id' "
			. 	"AND meta_key = '$key'";

		$res = $wpdb->get_results($sql);
		if ($wpdb->last_error)
		{
			$this->error_log(__METHOD__."; query error: ".$wpdb->last_error);
			return sqss_error::errProductQueryFail;
 		}
		$val = json_decode(json_encode($res[0]), true);

		if ($this->debug > 0)
		{
			$this->error_log(__METHOD__."; key: ".$key."; db value: ".$val['meta_value']."; new value: ".$value);
		}

		if ($val['meta_value'] != $value)
		{
			$sql = "UPDATE $wpdb->postmeta "
				. "SET meta_value='$value' "
				. "WHERE post_id = '$id' "
				. "	AND meta_key = '$key'";

			$result = $wpdb->get_results($sql);
			if ($wpdb->last_error)
			{
				$this->error_log(__METHOD__."; query error: ".$wpdb->last_error);
				return sqss_error::errVariationUpdateFail;
	 		}

			if ($result == 0)
			{
				$this->msg = __METHOD__.": postmeta update failed; key = ".$key."; value = ".$value;
				return sqss_error::errVariationUpdateFail;
			}
		}
	}

	//----------------------------------------------------------------------------
	// send_feedback 
	//----------------------------------------------------------------------------
	private function send_feedback($args)
	{
		$json = json_decode($logdata, true);

		if (!array_key_exists('log', $json))
		{
			$log = array('log'	=> "No Log");
		}
		else
		{
			$log	= $json['log'];
		}
		if (!array_key_exists('status', $json))
		{
			$status = array('status'=> "No Status");
		}
		else
		{
			$status	= $json['status'];
		}

		$email	= "admin@squarestatesoftware.com";

		if ($this->debug > 0)
		{

			$this->error_log(__METHOD__."; log: ".print_r($log,true));
			$this->error_log(__METHOD__."; status: ".print_r($status,true));
			$this->error_log(__METHOD__."; ack: ".print_r($ack,true));
			$this->error_log(__METHOD__."; email: ".$email);
		}

		$message = sprintf("\n\nPlugin Log:\n\n%s\nPlugin Return Status:\n\n%s\nPlugin Ack:\n\n%s",
				print_r($log,true),print_r($status,true),print_r($ack,true));

		$subject = sprintf("squarecomm debug: %s",$uuid);

		$rc = mail($email, $subject, '$message');

		if ($rc == 0)
		{
			$this->error_log(__METHOD__."; mail failed; error: ".print_r(error_get_last(),true));
		}
out:
		return sqss_error::errSuccess;
	}

	//----------------------------------------------------------------------------
	// gallery file search 
	//----------------------------------------------------------------------------
	private function get_gallery_attachment($post, $appurl)
	{

		// get the post gallery
		$gallery = get_post_gallery($post, false);

		if ($this->debug > 0)
		{
			$this->error_log(__METHOD__."; app file name : ".basename($appurl));
			$this->error_log(__METHOD__."; gallery       : ".print_r($gallery,true));
		}

		if ($gallery)
		{
			$gallery_urls = $gallery['src'];
			$gallery_ids = explode(",",$gallery['ids']);
		}

		for ($i = 0; $i < count($gallery_urls); ++$i)
		{
			$galleryurl = $gallery_urls[$i];

			if ($this->debug > 0)
			{
				$this->error_log(__METHOD__."; gallery name   : ".basename($galleryurl));
			}

			if (!strcmp(basename($galleryurl), basename($appurl)))
			{
				$attach_id = $gallery_ids[$i];
				if ($attach_id != "")
				{
					$this->error_log(__METHOD__."; found attachment for: ".basename($appurl)."; id: ".$attach_id);
					return $attach_id;
				}
			}
		}

		return "";
	}

	//----------------------------------------------------------------------------
	// gallery - attach an image to the product
	//----------------------------------------------------------------------------
	private function attach_image($url, &$attach_id)
	{
		$prodID = $this->req['parent_id'];

		$rc = sqss_error::errSuccess;

		if ($this->debug > 0)
		{
			$this->error_log(__METHOD__."; url: ".$url);
			$this->error_log(__METHOD__."; dstfile: ".$dstfile);
		}
		
		// get the attachment id

		$rc = $this->fileapi->product_image_attach( $prodID, $attach_ID, $url );

out:
		return $rc;
	}

}

?>
