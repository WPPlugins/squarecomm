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
// file.php - file functions 
//----------------------------------------------------------------------------

//
// Add the Square State Software API
//
require_once (dirname(__FILE__) . '/../SquareStateAPI/sqssapi.php');

class squarecommfile extends sqssapi
{

        public function __construct()
        {
		error_log(__METHOD__);

	}

	//----------------------------------------------------------------------------
	// gallery - add the image 
	//----------------------------------------------------------------------------
	public function add_file($filename, &$newurl, &$attach_id)
	{
		$prodID = $this->req['id'];

		$rc = sqss_squarecomm_error::errSuccess;
	
		if ($this->debug > 0)
		{
			$this->error_log(__METHOD__."; filename: ".$filename);
		}

		$tmpdir = $this->uploaddir['path']."/tmpdir";
		$srcfile = $tmpdir.'/'.$filename;

		$dstdir = $this->uploaddir['path'];
		//$dstdir = $this->uploaddir['path'].'/sqss';
		//if ($this->uuid != "")
		//	$dstdir = $dstdir.'/'.$this->uuid;
		$dstfile = $dstdir.'/'.$filename;

		if ($this->debug > 0)
		{
			$this->error_log(__METHOD__."; moving file from: ".$srcfile." to: ".$dstfile);
		}

		if (!file_exists($dstdir))
			mkdir($dstdir, 0777, true);

		if (rename($srcfile, $dstfile) != true)
		{
			$msg = sprintf(__METHOD__."; %s move failed;",$dstfile);
			return 0;
		}

		$dsturl = $this->uploaddir['url'];
		$dsturl = $dsturl.'/'.$filename;

		// INSERT NEW ATTACHMENT

		//$filetype = wp_check_filetype (basename($dsturl), null);
		$filetype = mime_content_type (basename($dsturl));

		// Prepare an array of post data for the attachment.
		$args = array(
			'guid'			=> $dsturl,
			//'post_mime_type'	=> $filetype['type'],
			'post_mime_type'	=> $filetype,
			'post_title'		=> basename($dsturl),
			'post_content'		=> "",
			'post_status'		=> 'inherit'
		);

		$id = wp_insert_attachment ($args, $dstfile, $prodID);
		if ($id == "")
		{
			$msg = sprintf(__METHOD__."; product image attachment insert failed;");
			$rc = sqss_squarecomm_error::errImageAttachFail;
			goto out;
		}

		// Generate the metadata for the attachment, and update the database record.
		$data = wp_generate_attachment_metadata ($id, $dstfile);

		if ($this->debug > 0)
		{
			$this->error_log(__METHOD__."; filepath : ".$dstfile);
			$this->error_log(__METHOD__."; attach id: ".$id);
			$this->error_log(__METHOD__."; attach data: ".print_r($data, true));
		}

		//$result = update_post_meta( $id, '_wp_attachment_metadata', $data );
		$sql = "UPDATE $wpdb->postmeta "
			. "SET meta_value='$data' "
			. "WHERE post_id = '$id' "
			. "	AND meta_key = '_wp_attachment_metadata'";
	
		$result = $wpdb->get_results($sql);
		if ($wpdb->last_error)
		{
			$this->error_log(__METHOD__."; query error: ".$wpdb->last_error);
			return sqss_squarecomm_error::errVariationUpdateFail;
 		}
		if ($result == 0)
		{
			$msg = sprintf(__METHOD__."; post_meta update failed;");
			return 999;
		}

		$attach_id = $id;
		$newurl = $dsturl;

out:

		return $rc;
	}

	//----------------------------------------------------------------------------
	// replace the image file
	//----------------------------------------------------------------------------
	public function replace_file($fileurl, $filename, &$newurl, &$attach_id)
	{
		//
		// move the uploaded file from $uploaddir/tmpdir into $uploaddir/sqss/[uuid]/
		//

		$prodID = $this->req['id'];

		//$filetype = wp_check_filetype (basename($fileurl), null);
		$filetype = mime_content_type (basename($fileurl));
		$urlcomp = parse_url($fileurl);
		$filepath = $_SERVER['DOCUMENT_ROOT'].$urlcomp['path'];

		if ($this->debug > 0)
		{
			$this->error_log(__METHOD__."; filepath: ".basename($filepath));
		}
 
		$tmpdir = $this->uploaddir['path']."/tmpdir";
		$srcfile = $tmpdir.'/'.$filename;
		$parts = pathinfo($filepath);

		$dstdir = $parts['dirname'];

		$pos = strpos($dstdir,'/sqss/');
		if (!$pos)
		{
			$dstdir = $dstdir.'/sqss';
			if ($this->uuid != "")
				$dstdir = $dstdir.'/'.$this->uuid;
		}
		$dstfile = $dstdir.'/'.$filename;

		$this->error_log(__METHOD__."; moving file from: ".$srcfile." to: ".$dstfile);

		if (!file_exists($dstdir))
			mkdir($dstdir, 0777, true);

		if (rename($srcfile, $dstfile) != true)
		{
			$this->msg = sprintf(__METHOD__."; %s move failed;",$dstfile);
			return 0;
		}

		$relpath = _wp_relative_upload_path($dstfile);

		$dsturl = $this->uploaddir['baseurl'].'/'.$relpath;

		$sizes = array();
		$this->get_thumbnail_sizes($sizes);
	
		foreach ($sizes as $key => $size)
		{	
			if ($key == 'thumbnail' || $key == 'medium' || $key == 'large')
				$this->resize_image($dstfile, $size[0], $size[1]);
			else
				$this->resize_image($dstfile, $size[0], 0);
		}

		// get the product attachments

		//$result = $this->get_product_image_attachment( $prodID, $attach_ID, $dstfile );
		$result = get_image_attachment( $dstfile, $attach_ID );
		if ($result == 0)
		{
			return 0;
		}

		$newurl = $dsturl;

		return sqss_squarecomm_error::errSuccess;
	}

	//----------------------------------------------------------------------------
	// revert the image file to its original
	//----------------------------------------------------------------------------
	public function revert_file($fileurl)
	{
		//
		// update the file attachment to reflect image file reversion to original
		//

		$prodID = $this->req['id'];

		//$filetype = wp_check_filetype (basename($fileurl), null);
		$filetype = mime_content_type (basename($fileurl));
		$urlcomp = parse_url($fileurl);
		$filepath = $_SERVER['DOCUMENT_ROOT'].$urlcomp['path'];

		if ($this->debug > 0)
		{
			$this->error_log(__METHOD__."; filepath: ".basename($filepath));
		}

		$parts = pathinfo($filepath);
		$dstdir = $parts['dirname'];
		$origfile = $dstdir.'/'.basename($filepath);

		$dstdir = $dstdir.'/sqss';
		if ($this->uuid != "")
			$dstdir = $dstdir.'/'.$this->uuid;
		$dstfile = $dstdir.'/'.basename($filepath);

		// get the product attachments

		$result = $this->get_product_image_attachment( $prodID, $attach_ID, $dstfile );
		if ($result != 0)
		{
			return sqss_squarecomm_error::errSuccess;
		}
/*
		$args = array(
			'posts_per_page'	=> -1,
			'post_mime_type'	=> 'image',
			'post_type'		=> 'attachment',
			'post_parent'		=> $prodID
		);

		$attachments = array();
		$attachments = get_posts($args);

		foreach ($attachments as $attachment)
		{
			// we only care about the attached image file 
			//if ($attachment->post_parent == $prodID)
			{
				$sql = "SELECT meta_value "
					. "FROM $wpdb->postmeta "
					. "WHERE post_id = '$attachment->ID' "
					. 	"AND meta_key = '_wp_attached_file'";
				$val = $wpdb->get_results($sql);
				if ($wpdb->last_error)
				{
					$this->error_log(__METHOD__."; query error: ".$wpdb->last_error);
					return sqss_squarecomm_error::errGetMetaFail;
 				}
				$result = json_decode(json_encode($val[0]), true);
				if ($result == "")
				{
					$msg = sprintf(__METHOD__."; post_meta get failed; status: (empty value)");
					return 0;
				}

				// if attachment value matches new file name then update
				if ($result != _wp_relative_upload_path($dstfile))
					continue;

				//$result = update_post_meta( $attachment->ID, '_wp_attached_file', _wp_relative_upload_path($origfile) );
				$uploadpath = _wp_relative_upload_path($origfile);

				$sql = "UPDATE $wpdb->postmeta "
					. "SET meta_value='$uploadpath' "
					. "WHERE post_id = '$attachment->ID' "
					. "	AND meta_key = '_wp_attachment_file'";

				$result = $wpdb->get_results($sql);
				if ($wpdb->last_error)
				{
					$this->error_log(__METHOD__."; query error: ".$wpdb->last_error);
					return sqss_squarecomm_error::errVariationUpdateFail;
 				}
				if ($resul) == 0)
				{
					$msg = sprintf(__METHOD__."; post_meta update failed; status: ".$result);
					return 0;
				}

				// Generate the metadata for the attachment, and update the database record.
				$data = wp_generate_attachment_metadata ($attachment->ID, $origfile);

				//$result = get_post_meta( $attachment->ID, '_wp_attachment_metadata', true );
				$sql = "SELECT meta_value "
					. "FROM $wpdb->postmeta "
					. "WHERE post_id = '$attachment->ID' "
					. 	"AND meta_key = '_wp_attached_metadata'";
				$val = $wpdb->get_results($sql);
				if ($wpdb->last_error)
				{
					$this->error_log(__METHOD__."; query error: ".$wpdb->last_error);
					return sqss_squarecomm_error::errGetMetaFail;
 				}
				$result = json_decode(json_encode($val[0]), true);
				if ($result == "")
				{
					$msg = sprintf(__METHOD__."; post_meta get failed; status: (empty value)");
					return 0;
				}

				// if attachment value matches new data then skip update
				if ($result != $data)
				{
					//$result = update_post_meta( $attachment->ID, '_wp_attachment_metadata', $data );
					$sql = "UPDATE $wpdb->postmeta "
						. "SET meta_value='$data' "
						. "WHERE post_id = '$attachment->ID' "
						. "	AND meta_key = '_wp_attachment_metadata'";

					$result = $wpdb->get_results($sql);
					if ($wpdb->last_error)
					{
						$this->error_log(__METHOD__."; query error: ".$wpdb->last_error);
						return sqss_squarecomm_error::errVariationUpdateFail;
 					}
					if ($result == 0)
					{
						$msg = sprintf(__METHOD__."; post_meta update failed;");
						return 0;
					}
				}

				$attach_id = $attachment->ID;

				return sqss_squarecomm_error::errSuccess;
			}
		}
*/
		$msg = sprintf("revert_file: attachment for image: ".$fileurl." not found");

		return 0;
	}

	//----------------------------------------------------------------------------
	// delete the image file to its original
	//----------------------------------------------------------------------------
	public function delete_file($fileurl)
	{
		//
		// delete the file attachment to reflect image file delete
		//

		$prodID = $this->req['id'];

		//$filetype = wp_check_filetype (basename($fileurl), null);
		$filetype = mime_file_type (basename($fileurl));
		$urlcomp = parse_url($fileurl);
		$filepath = $_SERVER['DOCUMENT_ROOT'].$urlcomp['path'];

		if ($this->debug > 0)
		{
			$this->error_log(__METHOD__."; filepath: ".basename($filepath));
		}

		$parts = pathinfo($filepath);
		$dstdir = $parts['dirname'];
		$origfile = $dstdir.'/'.basename($filepath);

		$dstdir = $dstdir.'/sqss';
		if ($this->uuid != "")
			$dstdir = $dstdir.'/'.$this->uuid;
		$dstfile = $dstdir.'/'.basename($filepath);

		// get the product attachments

		$result = $this->get_product_image_attachment( $prodID, $attach_ID, $dstfile );
		if ($result != 0)
		{
			return sqss_squarecomm_error::errSuccess;
		}
/*
		$args = array(
			'posts_per_page'	=> -1,
			'post_mime_type'	=> 'image',
			'post_type'		=> 'attachment',
			'post_parent'		=> $prodID
		);

		$attachments = array();
		$attachments = get_posts($args);

		foreach ($attachments as $attachment)
		{
			// we only care about the attached image file 
			if ($attachment->post_parent == $prodID)
			{
				//$result = get_post_meta( $attachment->ID, '_wp_attached_file', true );
				$sql = "SELECT meta_value "
					. "FROM $wpdb->postmeta "
					. "WHERE post_id = '$attachment->ID' "
					. 	"AND meta_key = '_wp_attached_file'";
				$val = $wpdb->get_results($sql);
				if ($wpdb->last_error)
				{
					$this->error_log(__METHOD__."; query error: ".$wpdb->last_error);
					return sqss_squarecomm_error::errGetMetaFail;
 				}
				$result = json_decode(json_encode($val[0]), true);
				if ($result == "")
				{
					$msg = sprintf(__METHOD__."; post_meta get failed; status: (empty value)");
					return 0;
				}

				// if attachment value matches new file name then update
				if ($result != _wp_relative_upload_path($dstfile))
					continue;

				$uploadpath = _wp_relative_upload_path($origfile);
				$sql = "UPDATE $wpdb->postmeta "
					. "SET meta_value='$uploadpath' "
					. "WHERE post_id = '$attachment->ID' "
					. "	AND meta_key = '_wp_attachment_file'";

				$result = $wpdb->get_results($sql);
				if ($wpdb->last_error)
				{
					$this->error_log(__METHOD__."; query error: ".$wpdb->last_error);
					return sqss_squarecomm_error::errVariationUpdateFail;
 				}
				if ($resul) == 0)
				{
					$msg = sprintf(__METHOD__."; post_meta update failed; status: ".$result);
					return 0;
				}

				// Generate the metadata for the attachment, and update the database record.
				$data = wp_generate_attachment_metadata ($attachment->ID, $origfile);

				//$result = get_post_meta( $attachment->ID, '_wp_attachment_metadata', true );
				if ($result == "")
				{
					$msg = sprintf(__METHOD__."; post_meta get failed; status: (empty value)");
					return 0;
				}

				// if attachment value matches new data then skip update
				if ($result != $data)
				{
					$sql = "UPDATE $wpdb->postmeta "
						. "SET meta_value='$data' "
						. "WHERE post_id = '$attachment->ID' "
						. "	AND meta_key = '_wp_attachment_metadata'";

					$result = $wpdb->get_results($sql);
					if ($wpdb->last_error)
					{
						$this->error_log(__METHOD__."; query error: ".$wpdb->last_error);
						return sqss_squarecomm_error::errVariationUpdateFail;
 					}
					if ($result == 0)
					{
						$msg = sprintf(__METHOD__."; post_meta update failed;");
						return 0;
					}
				}

				$attach_id = $attachment->ID;

				return sqss_squarecomm_error::errSuccess;
			}
		}
*/
		$msg = sprintf(__METHOD__."; attachment for image: ".$fileurl." not found");

		return 0;
	}

	//----------------------------------------------------------------------------
	// image url - get the original image url
	//----------------------------------------------------------------------------
	public function get_original_fileurl($fileurl)
	{
		$retUrl = "";

		$pos = strpos($fileurl,'/sqss/');
		if ($pos)
		{
			$parts = pathinfo($fileurl);
			$retUrl = substr($fileurl, 0, $pos).'/'.$parts['filename'].'.'.$parts['extension'];	
		}	
		else
		{
			$retUrl = $fileurl;
		}

		return $retUrl;
	}

	//----------------------------------------------------------------------------
	// image sizes 
	//----------------------------------------------------------------------------
	public function get_thumbnail_sizes(&$sizes)
	{
		global $_wp_additional_image_sizes;

		foreach (get_intermediate_image_sizes() as $s)
		{
 			$sizes[$s] = array(0, 0);
 			if (in_array ($s, array('thumbnail', 'medium', 'large')))
			{
 				$sizes[$s][0] = get_option($s . '_size_w');
 				$sizes[$s][1] = get_option($s . '_size_h');
 			}
			else
			{
 				if (isset ($_wp_additional_image_sizes)
					&& isset ($_wp_additional_image_sizes[$s]))
 					$sizes[$s] = array ($_wp_additional_image_sizes[$s]['width'], $_wp_additional_image_sizes[$s]['height'],);
 			}
 		}
	}

	//----------------------------------------------------------------------------
	// image resize 
	//----------------------------------------------------------------------------
	public function resize_image($file, $width, $height)
	{
		$image_properties = getimagesize($file);
		$image_width = $image_properties[0];
		$image_height = $image_properties[1];
		$image_ratio = $image_width / $image_height;
		$type = $image_properties["mime"];

		if (!$width && !$height)
		{
			$width = $image_width;
			$height = $image_height;
		}

		if (!$width)
		{
			$width = intval($height * $image_ratio);
		}
		if (!$height)
		{
			$height = intval($width / $image_ratio);
		}

		if ($type == "image/jpeg")
		{
			$thumb = imagecreatefromjpeg($file);
		}
		else if ($type == "image/png")
		{
			$thumb = imagecreatefrompng($file);
		}
		else
		{
			return false;
		}

		$parts = pathinfo($file);
		$target = $parts['dirname'].'/'.$parts['filename'].'-'.$width.'x'.$height.'.'.$parts['extension'];

		$temp_image = imagecreatetruecolor($width, $height);
		imagecopyresampled($temp_image, $thumb, 0, 0, 0, 0, $width, $height, $image_width, $image_height);
		$thumbnail = imagecreatetruecolor($width, $height);
		imagecopyresampled($thumbnail, $temp_image, 0, 0, 0, 0, $width, $height, $width, $height);

		if ($type == "image/jpeg")
		{
			imagejpeg($thumbnail, $target);
		}
		else
		{
			imagepng($thumbnail, $target);
		}

		imagedestroy($temp_image);
		imagedestroy($thumbnail);
	}

	//----------------------------------------------------------------------------
	// image upload 
	//----------------------------------------------------------------------------
	public function image_upload()
	{
		//
		// upload the file archive and extract contents into $uploaddir/tmpdir
		//

		if ($this->debug > 0)
		{
			error_log("----------------------");
			error_log("- image_upload() "); -
			error_log("----------------------");

			$fp = fopen("/tmp/spimage_trace.txt", "a"); //creates a file to trace your data
			fwrite($fp,"upload_dir \n");
			fwrite($fp, print_r($this->uploaddir, true));
			fwrite($fp,"GET \n");
			fwrite($fp, print_r($_GET, true));
			fwrite($fp,"POST \n");
			fwrite($fp, print_r($_POST, true));//displays the POST
			fwrite($fp,"FILES \n");
			fwrite($fp,print_r($_FILES,true));//display the FILES
			fclose($fp);
		}

		$path = $_POST['path'];
		$file = $_FILES['file']['name'];
		$tmpname = $_FILES['file']['tmp_name'];
		$filetype = $_FILES['file']['type'];
		$target = $this->uploaddir['path']."/".$file;

		$rc = 1;

		//----------------------------------------------------------------------------
		// remove existing/older version of the image file
		//----------------------------------------------------------------------------
		if (file_exists($target))
		{
			unlink($target);
		}

		//----------------------------------------------------------------------------
		// copy file to target location
		//----------------------------------------------------------------------------
		$result = 0;	
		if ($result == 0)
		{
			$this->msg = __METHOD__."; Image file: $file; upload to: $target failed: ".$_FILES['file']['error'];
			$this->stat = "fail";
			$rc = 0;
			goto out;
		}

		//----------------------------------------------------------------------------
		// change file permissions
		//----------------------------------------------------------------------------
		chmod($target, 0766);

		$this->error_log(__METHOD__."; The file: ". $file. " of type: ". $filetype. " has been uploaded to: ". $target);

		//----------------------------------------------------------------------------
		// unzip archive 
		//----------------------------------------------------------------------------
		$zip = new ZipArchive;
		$tmpdir = $this->uploaddir['path']."/tmpdir";

		if ($zip->open($target) === TRUE)
		{
			$zip->extractTo($tmpdir);
			$zip->close();
		}
		else
		{
			$this->msg = __METHOD__."; Zip archive open failed: $target";
			$stat = "fail";
			$rc = 0;
			goto out;
		}

		if ($this->debug > 0)
		{
			$this->error_log(__METHOD__."; image directory: ".$tmpdir);
			$extensions = array('jpg','png');
			$files = $this->getDirectoryTree($tmpdir,$extensions); 

			foreach ($files as $file)
			{	
				$url = $this->uploaddir['url']."/".$file;
				$this->error_log(__METHOD__."; image file: ".$file);
			}
		}

out:
		return sqss_squarecomm_error::errSuccess;
	}

	//----------------------------------------------------------------------------
	// get image directory contents 
	//----------------------------------------------------------------------------
	public function getDirectoryTree ($outerDir , $x)
	{
		$dirs = array_diff (scandir($outerDir), Array( ".", ".." ));
		$filenames = Array();

		foreach ($dirs as $d)
		{
			if (is_dir($outerDir."/".$d) )
			{
				$filenames[$d] = getDirectoryTree ($outerDir."/".$d , $x);
			}
			else
			{
				foreach ($x as $y)
				{
					if (($y)?ereg($y.'$',$d):1)
						$filenames[$d] = $d;
				}
			}
		}

		return $filenames;
	}

	//----------------------------------------------------------------------------
	// get product attachment 
	//----------------------------------------------------------------------------
	public function get_product_image_attachment ($prodID, &$attach_id, $name)
	{
		$rc = sqss_squarecomm_error::errImageAttachFail; 

error_log("gpia: prodID: ".$prodID."; genmeta: ".$genmeta);
		$attachments = array();

		$sql = "SELECT ID, post_title, post_type, post_name, post_excerpt, post_modified "
			. "FROM $wpdb->posts "
			. "WHERE post_parent = '$prodID' "
			. 	"AND post_type = 'attachment' "
			.	"AND post_mime_type = 'image'";
		$res = $wpdb->get_results($sql);
		if ($wpdb->last_error)
		{
			$this->error_log(__METHOD__."; query error: ".$wpdb->last_error);
			return sqss_squarecomm_error::errProductQueryFail;
 		}
		$attachments = json_decode(json_encode($res[0]), true);

error_log("gpia: name: ".$name);
		foreach ($attachments as $attachment)
		{
			// we only care about the attached image file 

error_log("gpia: attachment->id: ".$attachment->ID);
			if ($attachment->post_parent == $prodID)
			{
				$sql = "SELECT meta_value "
					. "FROM $wpdb->postmeta "
					. "WHERE post_id = '$attachment->ID' "
					. 	"AND meta_key = '_wp_attached_file'";
				$val = $wpdb->get_results($sql);
				if ($wpdb->last_error)
				{
					$this->error_log(__METHOD__."; query error: ".$wpdb->last_error);
					return sqss_squarecomm_error::errGetMetaFail;
 				}
				$result = json_decode(json_encode($val[0]), true);
				if ($result == "")
				{
					$msg = sprintf(__METHOD__.": post_meta get failed; status: (empty value)");
					return $rc;
				}

error_log("gpia: attachment: ".$result);
//error_log("gpia: relative name: "._wp_relative_upload_path($name));
				// if attachment value matches new file name then skip update
				if ($result != _wp_relative_upload_path($name))
				{
					//$result = update_post_meta( $attachment->ID, '_wp_attached_file', _wp_relative_upload_path($name) );
					$uploadpath = _wp_relative_upload_path($name);
					$sql = "UPDATE $wpdb->postmeta "
						. "SET meta_value='$uploadpath' "
						. "WHERE post_id = '$id' "
						. "	AND meta_key = '_wp_attachment_file'";

					$result = $wpdb->get_results($sql);
					if ($wpdb->last_error)
					{
						$this->error_log(__METHOD__."; query error: ".$wpdb->last_error);
						return sqss_squarecomm_error::errVariationUpdateFail;
			 		}
					if ($result == 0)
					{
						$msg = sprintf(__METHOD__."; post_meta update failed; status: ".$result);
						return $rc;
					}
				}

				// Generate the metadata for the attachment, and update the database record.
				$data = wp_generate_attachment_metadata ($attachment->ID, $name);

				$sql = "SELECT meta_value "
					. "FROM $wpdb->postmeta "
					. "WHERE post_id = '$attachment->ID' "
					. 	"AND meta_key = '_wp_attached_metadata'";
				$val = $wpdb->get_results($sql);
				if ($wpdb->last_error)
				{
					$this->error_log(__METHOD__."; query error: ".$wpdb->last_error);
					return sqss_squarecomm_error::errGetMetaFail;
 				}
				$result = json_decode(json_encode($val[0]), true);
				if ($result == "")
				{
					$msg = sprintf(__METHOD__."; post_meta get failed; status: (empty value)");
					return $rc;
				}

				// if attachment value matches new data then skip update
				if ($result != $data)
				{
					//$result = update_post_meta( $attachment->ID, '_wp_attachment_metadata', $data );
					$sql = "UPDATE $wpdb->postmeta "
						. "SET meta_value='$data' "
						. "WHERE post_id = '$id' "
						. "	AND meta_key = '_wp_attachment_metadata'";

					$result = $wpdb->get_results($sql);
					if ($wpdb->last_error)
					{
						$this->error_log(__METHOD__."; query error: ".$wpdb->last_error);
						return sqss_squarecomm_error::errVariationUpdateFail;
			 		}
					if ($result == 0)
					{
						$msg = sprintf(__METHOD__."; post_meta update failed;");
						return $rc;
					}
				}

				$attach_id = $attachment->ID;
error_log("gpia: attach_id: ".$attach_id);

				return sqss_squarecomm_error::errSuccess;
			}
		}
	}

	//----------------------------------------------------------------------------
	// get attachment 
	//----------------------------------------------------------------------------
	public function get_image_attachment ($url, &$attach_id)
	{
		$rc = sqss_squarecomm_error::errGetAttachmentFail; 

		$attachments = array();
		$sql = "SELECT ID, post_title, post_type, post_name, post_excerpt, post_modified "
			. "FROM $wpdb->posts "
			. "WHERE post_type = 'attachment' "
			.	"AND post_mime_type = 'image'";
		$res = $wpdb->get_results($sql);
		if ($wpdb->last_error)
		{
			$this->error_log(__METHOD__."; query error: ".$wpdb->last_error);
			return sqss_squarecomm_error::errProductQueryFail;
 		}
		$attachments = json_decode(json_encode($res[0]), true);

error_log("gia: url: ".$url);
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
				return sqss_squarecomm_error::errGetMetaFail;
 			}
			$result = json_decode(json_encode($val[0]), true);
			if ($result == "")
			{
				$msg = sprintf(__METHOD__."; post_meta get failed; status: (empty value)");
				return $rc;
			}

			$attachUrl = wp_get_attachment_url($attachment->ID);
error_log("gia: attachment: ".$result);
error_log("gia: attachUrl: ".$attachUrl);
			if ($url == $attachUrl)
			{
				// found it
				$attach_id = $attachment->ID;
				return sqss_squarecomm_error::errSuccess;
			}
		}

		return $rc;
	}

	//----------------------------------------------------------------------------
	// recursive remove directory 
	//----------------------------------------------------------------------------
	public function rrmdir($dir)
	{
		foreach (glob($dir . '/*') as $file)
		{
			if (is_dir($file))
				rrmdir($file);
			else
				unlink($file);
		}
		rmdir($dir);
	}

}

?>
