#!/usr/bin/env php
<?php

/* cheap, command-line script to demonstrate
 * use of the SimpleNote Swiss Army Knife
 */

require_once('snsakcore.php');

$debug = false;
$api_url = 'https://simple-note.appspot.com/';

if (count($argv) == 1)
	{
	echo "Usage:\n";
	echo "snsak.php operation [email]\n";
	echo "operations:\n";
	echo "list - list notes\n";
	echo "dedupe - remove duplicate notes\n";
	echo "hash2tag - make SimpleNotes tags from any hashtags in notes\n";
	echo "tag2hash - make hashtags from any SimpleNotes tags applied to notes\n";
	echo "You will be prompted for your SimpleNote password.\n";
	return;
	}
	
if (count($argv) < 2)
	{
	$email = getline("Email: ");
	}
else
	{
	$email = $argv[2];
	}


$pw = getline("Password: ");

$snsak = new snsak($api_url, $email, $pw);
$snsak->debug = $debug;

switch ($argv[1])
	{
	case 'dedupe':
		$d = $snsak->de_dupe();
		if (! $d[0]) die($d[1]."\n");
		echo $d[1]."\n";
		break;
	case 'hash2tag':
		$h = $snsak->tags_from_hash_tags();
		if (!$h[0]) die ($h[1]."\n");
		echo $h[1]."\n";
		break;
	case 'tag2hash':
		$h = $snsak->hash_tags_from_tags();
		if (!$h[0]) die ($h[1]."\n");
		echo $h[1]."\n";
		break;
	case 'list':
		$l = $snsak->retrieve_list();
		if (! $l[0]) die($l[1]."\n");
		foreach ($l[1] as $tr)
			{
			print_r($tr);
			}
		break;
	}

function getline($prompt)
	{
	if (function_exists('readline'))
		{
		$v = readline($prompt);
		readline_add_history($v);
		return $v;
		}
	echo "$prompt";	
	$fp = fopen("php://stdin","r");
	$v = rtrim(fgets($fp, 1024));
	fclose($fp);
	return $v;
	}
?>