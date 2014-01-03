<?php /** @file */

use Sabre\DAV;
require_once('vendor/autoload.php');

require_once('include/attach.php');

class RedInode implements DAV\INode {

	private $attach;

	function __construct($attach) {
		$this->attach = $attach;
	}


	function delete() {
		if(! perm_is_allowed($this->channel_id,'','view_storage'))
			return;

		/**
		 * Since I don't believe this is documented elsewhere -
		 * ATTACH_FLAG_OS means that the file contents are stored in the OS
		 * rather than in the DB - as is the case for attachments.
		 * Exactly how they are stored (what path and filename) are still
		 * TBD. We will probably not be using the original filename but 
		 * instead the attachment 'hash' as this will prevent folks from 
		 * uploading PHP code onto misconfigured servers and executing it.
		 * It's easy to misconfigure servers because we can provide a 
		 * rule for Apache, but folks using nginx will then be susceptible.
		 * Then there are those who don't understand these kinds of exploits
		 * and don't have any idea allowing uploaded PHP files to be executed
		 * by the server could be a problem. We also don't have any idea what
		 * executable types are served on their system - like .py, .pyc, .pl, .sh
		 * .cgi, .exe, .bat, .net, whatever.  
		 */

		if($this->attach['flags'] & ATTACH_FLAG_OS) {
			// FIXME delete physical file
		}
		if($this->attach['flags'] & ATTACH_FLAG_DIR) {
			// FIXME delete contents (recursive?)
		}
		
		q("delete from attach where id = %d limit 1",
			intval($this->attach['id'])
		);

	}

	function getName() {
		return $this->attach['filename'];
	}

	function setName($newName) {

		if((! $newName) || (! perm_is_allowed($this->channel_id,'','view_storage')))
			return;

		$this->attach['filename'] = $newName;
		$r = q("update attach set filename = '%s' where id = %d limit 1",
			dbesc($this->attach['filename']),
			intval($this->attach['id'])
		);

	}

	function getLastModified() {
		return $this->attach['edited'];
	}

}


class RedDirectory extends DAV\Node implements DAV\ICollection {

	private $red_path;
	private $ext_path;
	private $root_dir = '';
	private $auth;



	function __construct($ext_path,&$auth_plugin) {
		logger('RedDirectory::__construct() ' . $ext_path);
		$this->ext_path = $ext_path;
		$this->red_path = ((strpos($ext_path,'/cloud') === 0) ? substr($ext_path,6) : $ext_path);
		if(! $this->red_path)
			$this->red_path = '/';
		$this->auth = $auth_plugin;
		logger('Red_Directory: ' . print_r($this,true));


	}

	function getChildren() {

		logger('RedDirectory::getChildren : ' . print_r($this,true));

		if(get_config('system','block_public') && (! $this->auth->channel_id) && (! $this->auth->observer)) {
			throw new DAV\Exception\Forbidden('Permission denied.');
			return;
		}

		if(! perm_is_allowed($this->auth->channel_id,$this->auth->observer,'view_storage')) {
			throw new DAV\Exception\Forbidden('Permission denied.');
			return;
		}

		return RedCollectionData($this->red_path,$this->auth);

	}


	function getChild($name) {


		logger('RedDirectory::getChild : ' . $name);
		logger('RedDirectory::getChild : ' . print_r($this,true));

		if(get_config('system','block_public') && (! $this->auth->channel_id) && (! $this->auth->observer)) {
			throw new DAV\Exception\Forbidden('Permission denied.');
			return;
		}
 
		if(! perm_is_allowed($this->auth->channel_id,$this->auth->observer,'view_storage')) {
			throw new DAV\Exception\Forbidden('Permission denied.');
			return;
		}

		if($this->red_path === '/' && $name === 'cloud') {
			return new RedDirectory('/cloud', $this->auth);
		}

		$x = RedFileData($this->ext_path . '/' . $name, $this->auth);
		logger('RedFileData returns: ' . print_r($x,true));
		if($x)
			return $x;
		throw new DAV\Exception\NotFound('The file with name: ' . $name . ' could not be found');
		
	}

	function getName() {
		logger('RedDirectory::getName : ' . print_r($this,true));
		logger('RedDirectory::getName returns: ' . basename($this->red_path));

		return (basename($this->red_path));
	}




	function createFile($name,$data = null) {
		logger('RedDirectory::createFile : ' . $name);
		logger('RedDirectory::createFile : ' . print_r($this,true));

		logger('createFile():' . stream_get_contents($data));


		if(! perm_is_allowed($this->auth->channel_id,$this->auth->observer,'write_storage')) {
			logger('createFile: permission denied');
			throw new DAV\Exception\Forbidden('Permission denied.');
			return;
		}

		$mimetype = z_mime_content_type($name);


		$c = q("select * from channel where channel_id = %d limit 1",
			intval($this->auth->channel_id)
		);


		$filesize = 0;
		$hash = random_string();

dbg(1);

        $r = q("INSERT INTO attach ( aid, uid, hash, filename, filetype, filesize, revision, data, created, edited )
            VALUES ( %d, %d, '%s', '%s', '%s', %d, %d, '%s', '%s', '%s' ) ",
            intval($c[0]['channel_account_id']),
            intval($c[0]['channel_id']),
            dbesc($hash),
            dbesc($name),
            dbesc($mimetype),
            intval($filesize),
            intval(0),
            dbesc(stream_get_contents($data)),
            dbesc(datetime_convert()),
            dbesc(datetime_convert())
		);

		$r = q("update attach set filesize = length(data) where hash = '%s' and uid = %d limit 1",
			dbesc($hash),
			intval($c[0]['channel_id'])
		);


dbg(0);
 
	}


	function createDirectory($name) {
		if(! perm_is_allowed($this->auth->channel_id,$this->auth->observer,'write_storage')) {
			throw new DAV\Exception\Forbidden('Permission denied.');
			return;
		}









	}


	function childExists($name) {

		logger('RedDirectory::childExists : ' . print_r($this->auth,true));

		if($this->red_path === '/' && $name === 'cloud') {
			logger('RedDirectory::childExists /cloud: true');
			return true;
		}

		$x = RedFileData($this->ext_path . '/' . $name, $this->auth,true);
		logger('RedFileData returns: ' . print_r($x,true));
		if($x)
			return true;
		return false;

	}

}


class RedFile extends DAV\Node implements DAV\IFile {

	private $data;
	private $auth;
	private $name;

	function __construct($name, $data, &$auth) {
		logger('RedFile::_construct: ' . $name);
		$this->name = $name;
		$this->data = $data;
		$this->auth = $auth;

		logger('RedFile::_construct: ' . print_r($this->data,true));
	}


	function getName() {
		logger('RedFile::getName: ' . basename($this->name));
		return basename($this->name);

	}


	function setName($newName) {
		logger('RedFile::setName: ' . basename($this->name) . ' -> ' . $newName);

		if((! $newName) || (! perm_is_allowed($this->auth->channel_id,$this->auth->observer,'write_storage'))) {
			throw new DAV\Exception\Forbidden('Permission denied.');
			return;
		}

		$newName = str_replace('/','%2F',$newName);

		$r = q("update attach set filename = '%s' where hash = '%s' and id = %d limit 1",
			dbesc($this->data['filename']),
			intval($this->data['id'])
		);

	}



	function put($data) {
		logger('RedFile::put: ' . basename($this->name));
		logger('put():' . stream_get_contents($data));

dbg(1);
		$r = q("update attach set data = '%s' where hash = '%s' and uid = %d limit 1",
			dbesc(stream_get_contents($data)),
			dbesc($this->data['hash']),
			intval($this->data['uid'])
		);
		$r = q("update attach set filesize = length(data) where hash = '%s' and uid = %d limit 1",
			dbesc($this->data['hash']),
			intval($this->data['uid'])
		);
dbg(0);

	}


	function get() {
		logger('RedFile::get: ' . basename($this->name));

		$r = q("select data from attach where hash = '%s' and uid = %d limit 1",
			dbesc($this->data['hash']),
			intval($this->data['uid'])
		);
		if($r) return $r[0]['data'];

	}

	function getETag() {
		logger('RedFile::getETag: ' . basename($this->name));
		return $this->data['hash'];

	}


	function getContentType() {
		return $this->data['filetype'];
	}


	function getSize() {
		return $this->data['filesize'];
	}


	function getLastModified() {
		logger('RedFile::getLastModified: ' . basename($this->name));
		return $this->data['edited'];
	}


}

function RedChannelList(&$auth) {

	$ret = array();

	$r = q("select channel_id, channel_address from channel where not (channel_pageflags & %d)",
		intval(PAGE_REMOVED)
	);

	if($r) {
		foreach($r as $rr) {
			if(perm_is_allowed($rr['channel_id'],$auth->observer,'view_storage')) {
				$ret[] = new RedDirectory('/cloud/' . $rr['channel_address'],$auth);
			}
		}
	}
	return $ret;

}


function RedCollectionData($file,&$auth) {

	$ret = array();

	$x = strpos($file,'/cloud');
	if($x === 0) {
		$file = substr($file,6);
	}


logger('RedCollectionData: ' . $file); 

	if((! $file) || ($file === '/')) {
		return RedChannelList($auth);

	}

	$file = trim($file,'/');
	$path_arr = explode('/', $file);
	
	if(! $path_arr)
		return null;

	$channel_name = $path_arr[0];

	$r = q("select channel_id from channel where channel_address = '%s' limit 1",
		dbesc($channel_name)
	);

logger('dbg1: ' . print_r($r,true));

	if(! $r)
		return null;

	$channel_id = $r[0]['channel_id'];

	$path = '/' . $channel_name;

	$folder = '';

	for($x = 1; $x < count($path_arr); $x ++) {		
		$r = q("select id, hash, filename, flags from attach where folder = '%s' and (flags & %d)",
			dbesc($folder),
			intval($channel_id),
			intval(ATTACH_FLAG_DIR)
		);
		if($r && ( $r[0]['flags'] & ATTACH_FLAG_DIR)) {
			$folder = $r[0]['hash'];
			$path = $path . '/' . $r[0]['filename'];
		}	
	}

logger('dbg2: ' . print_r($r,true));

	if($path !== '/' . $file) {
		logger("RedCollectionData: Path mismatch: $path !== /$file");
		return NULL;
	}

	$ret = array();


	$r = q("select id, uid, hash, filename, filetype, filesize, revision, folder, flags, created, edited from attach where folder = '%s' and uid = %d group by filename",
		dbesc($folder),
		intval($channel_id)
	);

logger('dbg2: ' . print_r($r,true));

	foreach($r as $rr) {
		if($rr['flags'] & ATTACH_FLAG_DIR)
			$ret[] = new RedDirectory('/cloud' . $path . '/' . $rr['filename'],$auth);
		else
			$ret[] = new RedFile('/cloud' . $path . '/' . $rr['filename'],$rr,$auth);
	}

	return $ret;

}

function RedFileData($file, &$auth,$test = false) {

logger('RedFileData:' . $file);


	$x = strpos($file,'/cloud');
	if($x === 0) {
		$file = substr($file,6);
	}

logger('RedFileData2: ' . $file);

	if((! $file) || ($file === '/')) {
		return RedDirectory('/',$auth);

	}

	$file = trim($file,'/');

logger('file=' . $file);

	$path_arr = explode('/', $file);
	
	if(! $path_arr)
		return null;

	logger("file = $file - path = " . print_r($path_arr,true));

	$channel_name = $path_arr[0];


	$r = q("select channel_id from channel where channel_address = '%s' limit 1",
		dbesc($channel_name)
	);

	logger('dbg0: ' . print_r($r,true));

	if(! $r)
		return null;

	$channel_id = $r[0]['channel_id'];

	$path = '/' . $channel_name;

	$folder = '';
//dbg(1);

	require_once('include/security.php');
	$perms = permissions_sql($channel_id);

	$errors = false;

	for($x = 1; $x < count($path_arr); $x ++) {		
dbg(1);
		$r = q("select id, hash, filename, flags from attach where folder = '%s' and uid = %d and (flags & %d) $perms",
			dbesc($folder),
			intval($channel_id),
			intval(ATTACH_FLAG_DIR)
		);
dbg(0);
	logger('dbg1: ' . print_r($r,true));

		if($r && ( $r[0]['flags'] & ATTACH_FLAG_DIR)) {
			$folder = $r[0]['hash'];
			$path = $path . '/' . $r[0]['filename'];
		}	
		if(! $r) {
			$r = q("select id, uid, hash, filename, filetype, filesize, revision, folder, flags, created, edited from attach 
				where folder = '%s' and filename = '%s' and uid = %d $perms group by filename limit 1",
				dbesc($folder),
				basename($file),
				intval($channel_id)

			);
		}
		if(! $r)
			$errors = true;
	}

	logger('dbg1: ' . print_r($r,true));

	if($path === '/' . $file) {
		// final component was a directory.
		return new RedDirectory('/cloud/' . $file,$auth);
	}

	if($errors) {
		if($test)
			return false;
		throw new DAV\Exception\Forbidden('Permission denied.');
		return;
	}

	if($r) {
		if($r[0]['flags'] & ATTACH_FLAG_DIR)
			return new RedDirectory('/cloud' . $path . '/' . $r[0]['filename'],$auth);
		else
			return new RedFile('/cloud' . $path . '/' . $r[0]['filename'],$r[0],$auth);
	}
	return false;
}


