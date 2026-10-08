<?php
// CLI only: create service credentials without displaying them or passing them in process arguments.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/../../app/config.php';
if (!hub_is_installed()) exit("Install PAN first.\n");
if ($argc!==3) exit("Usage: php setup.php ABSOLUTE_PRIVATE_DIRECTORY PAN_URL\n");
$dir=$argv[1]; $url=rtrim($argv[2],'/');
if (!preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~',$dir)) exit("Private directory must be absolute.\n");
if (!is_dir($dir) && !mkdir($dir,0700,true)) exit("Cannot create private directory.\n");
$dir=realpath($dir); $root=realpath(HUB_ROOT);
$cmpDir=str_replace('\\','/',$dir); $cmpRoot=str_replace('\\','/',$root);
if (PHP_OS_FAMILY==='Windows') { $cmpDir=strtolower($cmpDir); $cmpRoot=strtolower($cmpRoot); }
if ($cmpDir===$cmpRoot || strpos($cmpDir,$cmpRoot.'/')===0) exit("Private directory must be outside PAN web root.\n");
$parts=parse_url($url);
if (!$parts || !isset($parts['host']) || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment']) || !(($parts['scheme']??'')==='https' || (($parts['scheme']??'')==='http' && in_array($parts['host'],['localhost','127.0.0.1','[::1]'],true)))) exit("Use HTTPS PAN URL, or HTTP localhost.\n");
$file=$dir.DIRECTORY_SEPARATOR.'connector.env';
if (file_exists($file)) exit("connector.env exists; keep existing credentials or move it before setup.\n");
$cfg=hub_config(); if (empty($cfg['api_key'])) exit("PAN API key is missing.\n");
$token=bin2hex(random_bytes(32));
$values=['PAN_CONNECTOR_TOKEN'=>$token,'PAN_CONNECTOR_PORT'=>'3210','PAN_URL'=>$url,'PAN_API_KEY'=>$cfg['api_key'],'PAN_PROFILE_DIR'=>str_replace('\\','/',$dir).'/profiles','PAN_HEADLESS'=>'true','PAN_SYNC_MINUTES'=>'0','PAN_SYNC_PROFILE'=>'default'];
$env=''; foreach($values as $k=>$v) { if(strpbrk((string)$v,"\r\n\"")!==false) exit("Invalid configuration value.\n"); $env.=$k.'="'.$v.'"'."\n"; }
if (file_put_contents($file,$env,LOCK_EX)===false) exit("Cannot write connector.env.\n"); chmod($file,0600);
hub_config_update(['shopee_server_token'=>$token,'shopee_server_port'=>3210]);
echo "Created private connector.env and configured PAN. Start Node with --env-file pointing to this file.\n";
