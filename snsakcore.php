<?php
/**
 * SimpleNote Swiss Army Knife
 * Class for interacting with SimpleNote API.
 * 
 * This code, and any intellectual property contained herein
 * (excluding any property belonging to Simperium, Inc) should be
 * considered released under an MIT-style license.
 * 
 * Copyright (c) 2011, Samuel Goldstein <samuelg@fogbound.net>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */
class snsak {
	protected $api_url;
	protected $mongo, $db, $cachename;
	protected $email, $password, $token;
	protected $logged_in;
	public $debug = false;
	
	function __construct($api_url, $email, $password)
	{
		$this->api_url = $api_url;
		if (substr($this->api_url,-1,1) == '/')
			{
			$this->api_url = substr($this->api_url,0,strlen($this->api_url)-1);
			}
       	if (! class_exists('Mongo'))
			{
			die('snsak requires Mongo to be installed');
			}
		if (! function_exists('curl_init'))
			{
			die('snsak requires the cURL library to access SimpleNotes');
			}
		$this->email = $email;		
		$this->password = $password;
		$this->logged_in = false;
		$this->mongo = new Mongo();
		$cachename = preg_replace('/[^a-zA-Z0-9]/','',$email);
		$this->db = $this->mongo->cache->$cachename;
	}

	/**
	 * Goes through a note collection, and creates SimpleNote tags from hashtags in the
	 * note content.
	 * @return mixed array with two elements: boolean success or failure, and message
	 */
	function tags_from_hash_tags()
	{
		$ref = $this->refresh_local_cache();
		if (!$ref[0]) return $ref;
		if ($this->debug) echo $ref[1]."\n";
		$cursor = $this->db->find(array('deleted'=>0));
		$tags = 0;
		foreach ($cursor as $key => $value)
			{
			$hashtags = array();
			preg_match_all('/\#([\w\-_]+)/s', $value['content'], $hashtags,PREG_PATTERN_ORDER);
			$added = false;
			foreach ($hashtags[1] as $tt)
				{
				if (! in_array($tt,$value['tags']))
					{
					array_push($value['tags'], $tt);
					$added = true;
					$tags += 1;
					}
				}
			if ($added)
				{
				$uprec = new stdClass;
				$uprec->version = $value['version'];
				$uprec->tags = $value['tags'];
				$uprec->content = $value['content'];
				$upd = $this->update_note($value['key'], $uprec);
				if (!$upd[0]) return $upd;
				$this->db->update(array( 'key' => $key ), $value);
				}

			}
		return array(true,"$tags tags created from hashtags.");
	}

	/**
	 * Goes through a note collection, and creates hashtags at the end of a note from
	 * the note's SimpleNote tags. If a hashtag already exists in the note's content,
	 * it will not be added redundantly.
	 * @return mixed array with two elements: boolean success or failure, and message
	 */
	function hash_tags_from_tags()
	{
		$ref = $this->refresh_local_cache();
		if (!$ref[0]) return $ref;
		if ($this->debug) echo $ref[1]."\n";
		$cursor = $this->db->find(array('deleted'=>0));
		$tags = 0;
		foreach ($cursor as $key => $value)
			{
			$hashtags = array();
			preg_match_all('/\#([\w\-_]+)/s', $value['content'], $hashtags,PREG_PATTERN_ORDER);
			$added = false;
			foreach ($value['tags'] as $tt)
				{
				if (! in_array($tt,$hashtags[1]))
					{
					$value['content'] .= ' #'.$tt;
					$added = true;
					$tags += 1;
					}
				}
			if ($added)
				{
				$uprec = new stdClass;
				$uprec->version = $value['version'];
				$uprec->content = $value['content'];
				$upd = $this->update_note($value['key'], $uprec);
				if (!$upd[0]) return $upd;
				$this->db->update(array( 'key' => $key ), $value);
				}

			}
		return array(true,"$tags hashtags created from tags.");
	}

	/**
	 * Goes through a note collection, removing duplicates. Notes are compared by the MD5 hash of their content.
	 * If duplicate notes happen to have different tags, the remaining note is given the superset of all the tags.
	 * @return mixed array with two elements: boolean success or failure, and message
	 */
	function de_dupe()
		{
		$ref = $this->refresh_local_cache(true);
		if (!$ref[0]) return $ref;
		if ($this->debug) echo $ref[1]."\n";
		$cursor = $this->db->find(array('deleted'=>0));
		$dupelist = array();
		$removed = 0;
		$merged = 0;
		foreach ($cursor as $key => $value)
			{
			if ($value['deleted'] == 0 && !isset($dupelist[$value['key']]))
				{
					// find anyone with a matching hash
					$query = array( 'hash' => $value['hash'], 'deleted'=>0 );
					$subcursor = $this->db->find( $query );
					$tags = $value['tags'];
					$added_tags = false;
					while( $subcursor->hasNext() )
						{
						$dupe = $subcursor->getNext();
						if ($dupe['key'] != $value['key'] && !$dupe['deleted']==1 && !isset($dupelist[$dupe['key']]))
							{
							// build superset of tags
					    	foreach ($dupe['tags'] as $thisTag)
								{
								if (!in_array($thisTag,$tags))
									{
									$tags[]=$thisTag;
									$added_tags = true;
									}
								}
							// delete dupe
							$delrec = new stdClass;
							$delrec->deleted = 1;
							$upd = $this->update_note($dupe['key'], $delrec);
							if (!$upd[0]) return $upd;
							$dupe['deleted'] = 1;
							$this->db->update(array( 'key' => $subcursor->key() ), $dupe);
							$dupelist[$dupe['key']] = 1;
							$removed += 1;
							}
						}
					if ($added_tags)
						{
						$merged += 1;
						$uprec = new stdClass;
						$uprec->content = $value['content'];
						$uprec->tags = $tags;
						$uprec->version = $value['version'];
						$this->update_note($value['key'], $uprec);
						}
				}
			}
			return array(true,"Removed $removed duplicates and merged $merged stray tags.");
		}
	
	/**
	 * Refreshes the local cache (in Mongo database) for this note collection
	 * @param boolean $force force overwrite of everything in cache?
	 * @return mixed array with two elements: boolean success or failure, and message
	 */
	function refresh_local_cache($force = false)
		{
		$inserts = 0;
		$updates = 0;
		$total = 0;
		$l = $this->retrieve_list();
		if (!$l[0]) return $l;
		foreach ($l[1] as $tr)
			{
			$total += 1;
			$query = array( 'key' => $tr['key'] );
			$cv = $this->db->findOne($query);
			if (! $cv)
				{
				// create from server
				$note = $this->retrieve_note($tr['key']);
				if (!$note[0]) return $note;
				$this->db->insert($note[1]);
				$inserts += 1;
				}
			else if ($cv['syncnum'] < $tr['syncnum'] || $force)
				{
				// update from server
				$note = $this->retrieve_note($tr['key']);
				if (!$note[0]) return $note;
				$this->db->update($query, $note[1]);
				$updates += 1;
				}
			}
		return array(true,"$total notes on server, $inserts inserted into local cache, $updates updated in cache");
		}

	

	/**
	 * Given an array containing all notes
	 * @return array with two elements: 1) boolean success or failure, 2) message on failure or array containing notes on success
	 */
	function retrieve_list()
		{
		if (! $this->logged_in)
			{
		 	$l = $this->do_login();
			if (!$l[0]) return $l;
			}
		$length = 90;
		$looping = true;
		$mark = '';
		$fulllist = array();
		while ($looping)
			{
			$res = $this->do_get($this->api_url.'/api2/index?length='.$length.$mark.'&'.$this->token);
			if (!$res[0]) return $res;
			$arr = json_decode($res[1]);
			if (isset($arr->mark))
				{
				$mark='&mark='.$arr->mark;
				}
			else
				{
				$looping = false;
				}
			foreach($arr->data as $td)
				{
				$fulllist[] = (array)$td;
				}
			}
		return array(true,$fulllist);
		}

	/**
	 * Given a note's key, returns the note as an array
	 * @param string $key key of note on SimpleNote server
	 * @return array with two elements, 1) boolean success or failure, 2) message on failure or note on success
	 */
	function retrieve_note($key)
		{
		if (! $this->logged_in)
			{
		 	$l = $this->do_login();
			if (!$l[0]) return $l;	
			}
		$res = $this->do_get($this->api_url.'/api2/data/'.$key.'?'.$this->token);
		if (! $res[0]) return $res;
		$note = json_decode($res[1]);
		$note->hash = md5($note->content);
		return array(true,(array)$note);
		}

	/**
	 * Given note information, update it on the server
	 * @param string $key key of note to update
	 * @param mixed $fields class containing the attributes to update (minimum: content)
	 * @return array with two elements, 1) boolean success or failure, 2) message on failure or note on success
	 */
	function update_note($key, $fields)
		{
		if (! $this->logged_in)
			{
		 	$l = $this->do_login();
			if (!$l[0]) return $l;	
			}
		$res = $this->do_post($this->api_url.'/api2/data/'.$key.'?'.$this->token,json_encode($fields));
		if (! $res[0]) return $res;
		$note = json_decode($res[1]);
		return array(true,(array)$note);
		}

	/**
	 * Given a URL, issues a GET and returns a string
	 * @param string $url URL to GET
	 * @return array with two elements, 1) boolean success or failure, 2) message on failure or GET response on success
	 */
	public function do_get($url)
		{
		if ($this->debug) echo 'GET '.$url."\n";
		$ch = curl_init($url);
		$headers=array();
		curl_setopt($ch, CURLOPT_POST, false);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch,CURLOPT_FAILONERROR,true);

		$val = curl_exec($ch);
		if (curl_errno($ch))
			{
			return array(false,curl_error($ch));
			}
		curl_close($ch);
		return array(true,$val);
		}

	/**
	 * Given an URL, issues a POST and returns the servers response
	 * @param string $url URL to POST to
	 * @param mixed $body array of key/values to POST, or string of preassembled POST content
	 * @param boolean $encode whether or not to url_encode the payload
	 * @return array with two elements, 1) boolean success or failure, 2) message on failure or POST response on success
	 */
	public function do_post($url,$body,$encode=true,$headers=array('Accept-Charset: utf-8'))
		{
		if ($this->debug) echo 'POST '.$url."\n";
		$ch = curl_init($url);
		if (is_array($body))
			{
			$pbodyarr = array();
			foreach ($body as $k=>$v)
				{
				if ($encode)
					{
					$pbodyarr[] = urlencode($k).'='.urlencode($v);
					}
				else
					{
					$pbodyarr[] = $k.'='.$v;	
					}
				}
			$pbody = implode('&',$pbodyarr);
			}
		else
			{
			if ($encode)
				{
				$pbody = urlencode($body);
				}
			else
				{
				$pbody = $body;
				}
			}
		if ($this->debug) echo 'POST body: '.$pbody."\n";
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $pbody);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch,CURLOPT_FAILONERROR,true);

		$val = curl_exec($ch);
		if (curl_errno($ch))
			{
			return array(false,curl_error($ch));
			}
		curl_close($ch);
		return array(true,$val);
		}



	/**
	 * Passes a username/password to SimpleNotes API and gets an access token.
	 * @param string $email email address for logging in
	 * @param string $password unencoded password for logging in
	 * @return array with two elements, 1) boolean success or failure, 2) message
	 */
	function do_login()
		{
		if ($this->logged_in) return array(true,'already logged in');
		$fields = array(
			'email='.$this->email,
			'password='.$this->password
			);
		$res = $this->do_post($this->api_url.'/api/login',base64_encode(implode('&',$fields)),false);
		if (!$res[0]) return $res;
		$this->token = 'auth='.$res[1].'&email='.$this->email;
		$this->logged_in = true;
		return array(true,'success');
		}	
}
?>