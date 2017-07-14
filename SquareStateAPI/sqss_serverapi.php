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
// SQSS_SERVERAPI.PHP - sqss global server api
//----------------------------------------------------------------------------

//
// Square State Software Server 
//
require_once ('sqssapi.php');
//require_once (dirname(__FILE__) . '/../PHPMailer-master/PHPMailerAutoload.php');

define( 'SQSS_NO_SEND', 1 );

if( !defined( 'SQSS_DEBUG' ) )
	define( 'SQSS_DEBUG', 0 );
if( !defined( 'SQSS_NO_SEND' ) )
	define( 'SQSS_NO_SEND', 0 );

//----------------------------------------------------------------------------

abstract class sqss_request_type
{
        // app request types -- commands

        const sqss_req_handshake        = "handshake";
        const sqss_req_error            = "error";
        const sqss_req_customtypes      = "customtypes";
        const sqss_req_product          = "products";
        const sqss_req_variation        = "variations";
        const sqss_req_sku              = "skus";
        const sqss_req_attributes       = "attrubutes";
        const sqss_req_metakeys         = "metakeys";
        const sqss_req_media            = "media";
        const sqss_req_image            = "image";
        const sqss_req_add_user         = "user";
        const sqss_req_reset_sample     = "reset_sample";
        const sqss_req_feedback         = "feedback";
}

abstract class sqss_error
{
        // error codes

        const errSuccess                = 0;
        const errArgs                   = 1;
	const errEmailFail		= 2;
        const errUserID                 = 10;
        const errProductQueryFail       = 11;
        const errProductUpdateFail      = 12;
        const errVariationQueryFail     = 14;
        const errVariationUpdateFail    = 15;
        const errNoProducts	        = 16;
        const errUserDBCreateFail       = 20;
        const errUserDBCreateSuccess    = 21;
        const errUserDBDropFail         = 22;
        const errUserDBDropSourceDB     = 23;
        const errUserDBSelectFail       = 24;
        const errImage                  = 30;
        const errImageAttachFail        = 31;
        const errImageMetaFail          = 32;
        const errGetAttachmentFail      = 33;
        const errGetMetaFail            = 34;
        const errAttributesQueryFail    = 40;
        const errVisibleKeysQueryFail   = 50;
        const errTaxonomyQueryFail      = 60;
}

class sqss_serverapi extends sqssapi
{
	private $parms;
	private $uuid		= "";

	public $reqtype		= "";
	public $req		= "";
	public $payload		= "";
	public $reqstring	= "";
	public $msg		= "Operation Completed";
	public $stat		= "Success";
	public $valid		= "true";
	public $version		= "2.0.0";

	public $errCode		= 0;
	public $errbuff		= array();
	public $phpbuff		= array();

	protected $User;

	public function __construct($request, $origin, $query_vars)
	{
		parent::__construct($request);

		$this->uuid = $query_vars['uuid'];

		$this->error_log(__METHOD__);
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

		if (SQSS_DEBUG > 1)
		{
			$this->error_log(__METHOD__."; _SERVER: ".print_r($_SERVER,true));
			$this->error_log(__METHOD__."; request: ".$request);
			$this->error_log(__METHOD__."; origin: ".$origin);
			$this->error_log(__METHOD__."; query_vars: ".print_r($query_vars,true));
		}

		$this->verb = $_SERVER['REQUEST_METHOD'];
		$this->url_elements = explode('/', $_SERVER['REQUEST_URI']);

		if (SQSS_DEBUG > 1)
		{
			$this->error_log(__METHOD__."; url elements: ".print_r($this->url_elements,true));
		}

		$this->parse_args();

		if (SQSS_DEBUG > 0)
		{
			$this->error_log(__METHOD__."; parms: ".print_r($this->parms,true));
		}
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

		$multipart = "multipart/form-data";

		$content_type = false;
		if (isset($_SERVER['CONTENT_TYPE']))
		{
			$content_type = $_SERVER['CONTENT_TYPE'];

			if (substr($content_type, 0, strlen($multipart)) == $multipart)
				$content_type = $multipart;
		}

		$body = $this->file;	// parent class reads from input

		if (SQSS_DEBUG > 1)
		{
			$this->error_log("content_type: ".$content_type);
		}

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

			case $multipart:
				parse_str($_POST, $postvars);
				foreach($postvars as $field => $value)
				{
					$parms[$field] = $value;
				}
				$this->format = "multipart";
				break;

			default:
				// we could parse other supported formats here
				break;
		}
		$this->parms = $parms;
	}

	//----------------------------------------------------------------------------
	// REST endpoint functions 
	//----------------------------------------------------------------------------

	//----------------------------------------------------------------------------
	// error 
	//----------------------------------------------------------------------------
	protected function error($args)
	{
		$this->reqtype = $args[1];
		$this->reqstring = "sqss_req_".$this->reqtype;
		$this->errCode = $this->sqss_do_request(sqss_request_type::sqss_req_error, $args);

		if (SQSS_DEBUG > 0)
		{
			$this->error_log(__METHOD__."; args: ".print_r($args,true));
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
		$this->reqtype = sqss_request_type::sqss_req_feedback;
		$this->errCode = $this->sqss_do_request($this->reqtype, $args);

		if (SQSS_DEBUG > 0)
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

		if (SQSS_DEBUG > 0)
		{
			$this->error_log(__METHOD__."; request: ".$request."; parms: ".print_r($this->parms,true));
		}

		switch ($request) {
		    case sqss_request_type::sqss_req_error:

			$this->errCode = array_shift($args);

			goto err;

		    case sqss_request_type::sqss_req_feedback:

			$this->reqstring = "sqss_req_feedback";
			$this->errCode = $this->sqss_send_feedback();
			if ($this->errCode != sqss_error::errSuccess)
				goto err;

			break;

		    default:

			$this->reqstring = "sqss_req_unknown_request_type";
			$this->msg = __METHOD__."; error: unknown query type: $this->reqtype.";

			$this->errCode = array_shift($args);

			goto err;
		}

out:
		$rc = $this->errCode;
		$this->errText = $this->translate_errCode($this->errCode);

		// status 
		$status = array('valid'		=> "$this->valid",
				'version'	=> "$this->version",
				'reqtype'	=> "$this->reqtype",
				'reqstring'	=> "$this->reqstring",
				'uuid'		=> "$this->uuid",
				'stat'		=> "$this->stat",
				'error'		=> "$this->errCode",
				'errortext'	=> "$this->errText",
				'msg'		=> "$this->msg"
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
	// sqss_send_feedback 
	//----------------------------------------------------------------------------
	private function sqss_send_feedback()
	{
                $file = $_FILES['applog']['name'];
                $tmpname = $_FILES['applog']['tmp_name'];
                $filetype = $_FILES['applog']['type'];
                $target = "/uploads/".$file;

		if (move_uploaded_file($tmpname, $target))
		{
	                $this->error_log("file_upload: The file: ". $file. " of type: ". $filetype. " has been uploaded to: ". $target);
		}
		else
		{
	                $this->error_log("file_upload: The file: ". $file." upload failed");
		}

	        if (isset($_POST['dict']))
		{
			$json = $_POST;
			error_log("; dict: ".print_r($json,true));
		}

		if (SQSS_DEBUG > 0)
		{
			$this->error_log(__METHOD__."; json: ".$json);
			$this->error_log(__METHOD__."; json['email']: ".$json['email']);
		}

		if (array_key_exists('email', $json) && $json['email'] != "")
		{
			$to_address = $json['email'];
		}
		else
		{
			$to_address = "support@squarestatesoftware.com";
		}

		if (array_key_exists('msg', $json) && $json['msg'] != "")
		{
			$msg = $json['msg'];
		}
		else
		{
			$msg = "";
		}

		if (array_key_exists('log', $json) && $json['log'] != "")
		{
			$log = $json['log'];
		}
		else
		{
			$log = "";
		}

		if (array_key_exists('status', $json) && $json['status'] != "")
		{
			$status	= $json['status'];
		}
		else
		{
			$status = "";
		}

		if (array_key_exists('name', $json) && $json['name'] != "")
		{
			$appName = $json['name'];
		}
		else
		{
			$appName = "";
		}

		if (array_key_exists('system', $json) && $json['system'] != "")
		{
			$system = $json['system'];
		}
		else
		{
			$system = "";
		}

		if (array_key_exists('model', $json) && $json['model'] != "")
		{
			$model = $json['model'];
		}
		else
		{
			$model = "";
		}

		if (array_key_exists('version', $json) && $json['version'] != "")
		{
			$appVersion = $json['version'];
		}
		else
		{
			$appVersion = "";
		}

		// pass these from client
		$from_address = "admin@squarestatesoftware.com";
		$username = $from_address;
		$password = "gdead*";

		$message = sprintf("<br>App Name: %s<br>System: %s<br>Model: %s<br>Version: %s<br><br>Message: %s<br><br>Server Log: %s<br><br>Server Status: %s<br><br><br>",
				$appName,
				$system,
				$model,
				$appVersion,
				$msg,
				print_r($log,true),
				print_r($status,true));

		$subject = sprintf("%s Feedback",$appName);

		if (SQSS_DEBUG > 0)
		{
			$this->error_log(__METHOD__."; subject: ".$subject);
			$this->error_log(__METHOD__."; messages: ".$message);
		}

		//SMTP needs accurate times, and the PHP time zone MUST be set
		//This should be done in your php.ini, but this is how to do it if you don't have access to that
		date_default_timezone_set('Etc/UTC');

		$mail = new PHPMailer;
		$mail->isSMTP();

		//Enable SMTP debugging
		// 0 = off (for production use)
		// 1 = client messages
		// 2 = client and server messages
		$mail->SMTPDebug = 0;

		$mail->Debugoutput = 'html';
		$mail->Host = "smtpout.secureserver.net";
		$mail->Port = 465;
		$mail->SMTPAuth = true;
		$mail->SMTPSecure = 'ssl';
		$mail->Username = $username; 
		$mail->Password = $password;
		$mail->setFrom($from_address, 'SQSS Server');
		$mail->addAddress($to_address, 'Support');
		$mail->Subject = $subject;

		$mail->msgHTML($message, dirname(__FILE__));
		//$mail->AltBody = $message;

		if ($appName != "")
		{
			$appImageName = sprintf("images/%s.png",$appName);
			$mail->addAttachment($appImageName);
		}

		if (!SQSS_NO_SEND && !$mail->send())
		{
			$this->error_log(__METHOD__."; mail send error: ". $mail->ErrorInfo);
			return sqss_error::errEmailFail;
		}
		else
		{
			$this->error_log(__METHOD__."; mail send success ");
			return sqss_error::errSuccess;
		}
	}

	//----------------------------------------------------------------------------
	// log json error 
	//----------------------------------------------------------------------------
	public function json_error($err)
	{
	    switch ($err) {
		case JSON_ERROR_NONE:
			return ' - No errors';
			break;
		case JSON_ERROR_DEPTH:
			return ' - Maximum stack depth exceeded';
			break;
		case JSON_ERROR_STATE_MISMATCH:
			return ' - Underflow or the modes mismatch';
			break;
		case JSON_ERROR_CTRL_CHAR:
			return ' - Unexpected control character found';
			break;
		case JSON_ERROR_SYNTAX:
			return ' - Syntax error, malformed JSON';
			break;
		case JSON_ERROR_UTF8:
			return ' - Malformed UTF-8 characters, possibly incorrectly encoded';
			break;
		default:
			return ' - Unknown error';
			break;
		}
	}

	//----------------------------------------------------------------------------
	// translate error code into text 
	//----------------------------------------------------------------------------
        public function translate_errCode($errCode)
        {
                switch ($errCode) {
                        case sqss_error::errSuccess:
                                $text = "Server Request Completed";
                                break;
                        case sqss_error::errUserID:
                                $text = "No user ID in request";
                                break;
                        case sqss_error::errProductQueryFail:
                                $text = "Product Query Failed";
                                break;
                        case sqss_error::errProductUpdateFail:
                                $text = "Product Update Failed";
                                break;
                        case sqss_error::errVariationQueryFail:
                                $text = "Product Variation Query Failed";
                                break;
                        case sqss_error::errVariationUpdateFail:
                                $text = "Product Variation Update Failed";
                                break;
                        case sqss_error::errNoProducts:
                                $text = "No Products Available";
                                break;
                        case sqss_error::errUserDBCreateFail:
                                $text = "User Sample Database Creation Failed";
                                break;
                        case sqss_error::errUserDBCreateSuccess:
                                $text = "User Sample Database Creation Success";
                                break;
                        case sqss_error::errUserDBDropFail:
                                $text = "Database Drop Failed";
                                break;
                        case sqss_error::errUserDBDropSourceDB:
                                $text = "Attempted To Delete The Sample Database";
                                break;
                        case sqss_error::errUserDBSelectFail:
                                $text = "Database Select Failed";
                                break;
                        case sqss_error::errImage:
                                $text = "Image Operation Failed";
                                break;
                        case sqss_error::errImageAttachFail:
                                $text = "Image/Post Attachment Operation Failed";
                                break;
                        case sqss_error::errImageMetaFail:
                                $text = "Image/Post Meta Data Operation Failed";
                                break;
                        case sqss_error::errGetAttachmentFail:
                                $text = "Get Post Attachment Failed";
                                break;
                        case sqss_error::errGetMetaFail:
                                $text = "Get Post Meta Data Failed";
                                break;
                        case sqss_error::errAttributesQueryFail:
                                $text = "Attributes Query Failed";
                                break;
                        default:
				$text = "Unknown error code: ".$errCode;
				break;
		}

		return $text;
	}
}

?>
