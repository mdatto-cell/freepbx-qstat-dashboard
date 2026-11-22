<?php
require_once __DIR__ . '/qstat.inc.php';
function pushDb() {
 global $dsn,$dbUser,$dbPass;
 return new PDO($dsn,$dbUser,$dbPass,array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false));
}
function pushConfig() {
 $path = getenv('QSTAT_FIREBASE_SERVICE_ACCOUNT');
 if (!$path || !is_readable($path)) throw new Exception('Set QSTAT_FIREBASE_SERVICE_ACCOUNT to readable private JSON file');
 $data = json_decode(file_get_contents($path),true);
 if (!is_array($data) || empty($data['project_id']) || empty($data['client_email']) || empty($data['private_key'])) throw new Exception('Invalid Firebase service account JSON');
 return $data;
}
function pushHttp($url,$body,$headers) {
 if (!function_exists('curl_init')) throw new Exception('PHP cURL extension required');
 $ch=curl_init($url);
 curl_setopt_array($ch,array(CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>20));
 $response=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch);
 if ($response===false) throw new Exception('Network error: '.$err);
 return array($status,$response);
}
function pushAccessToken($config) {
 $now=time(); $header=pushB64(json_encode(array('alg'=>'RS256','typ'=>'JWT')));
 $claims=pushB64(json_encode(array('iss'=>$config['client_email'],'scope'=>'https://www.googleapis.com/auth/firebase.messaging','aud'=>'https://oauth2.googleapis.com/token','iat'=>$now,'exp'=>$now+3500)));
 $unsigned=$header.'.'.$claims; $signature='';
 if (!openssl_sign($unsigned,$signature,$config['private_key'],OPENSSL_ALGO_SHA256)) throw new Exception('JWT signing failed');
 $jwt=$unsigned.'.'.pushB64($signature);
 list($status,$body)=pushHttp('https://oauth2.googleapis.com/token',http_build_query(array('grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer','assertion'=>$jwt)),array('Content-Type: application/x-www-form-urlencoded'));
 $json=json_decode($body,true);
 if ($status!==200 || empty($json['access_token'])) throw new Exception('OAuth token failed HTTP '.$status.' '.substr($body,0,250));
 return $json['access_token'];
}
function pushB64($s) {return rtrim(strtr(base64_encode($s),'+/','-_'),'=');}
function pushSend($config,$access,$token,$title,$body,$data) {
 $payload=array('message'=>array('token'=>$token,'notification'=>array('title'=>$title,'body'=>$body),'data'=>$data,'android'=>array('priority'=>'HIGH','notification'=>array('channel_id'=>'qstat_missed_calls','click_action'=>'com.freepbx.qstat.OPEN_MISSED')),'apns'=>array('payload'=>array('aps'=>array('sound'=>'default')))));
 return pushHttp('https://fcm.googleapis.com/v1/projects/'.rawurlencode($config['project_id']).'/messages:send',json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),array('Authorization: Bearer '.$access,'Content-Type: application/json; charset=UTF-8'));
}
function pushError($status,$body) {
 $j=json_decode($body,true); $code='';
 if (isset($j['error']['details']) && is_array($j['error']['details'])) foreach($j['error']['details'] as $d) if(isset($d['errorCode'])) $code=$d['errorCode'];
 if (!$code && isset($j['error']['status'])) $code=$j['error']['status'];
 return array($code,substr(preg_replace('/[\r\n]+/',' ',isset($j['error']['message'])?$j['error']['message']:$body),0,250));
}
