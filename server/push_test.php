#!/usr/bin/env php
<?php
// php push_test.php --list | php push_test.php --device=ID --send
require_once __DIR__.'/push_common.php';
if (php_sapi_name()!=='cli') exit(1);
try {
 $db=pushDb();
 if (in_array('--list',$argv,true)) {
  foreach($db->query('SELECT id,platform,enabled,last_seen_at FROM cc_push_devices ORDER BY id') as $r) echo implode(' | ',$r).PHP_EOL;
  exit(0);
 }
 $deviceId=0; foreach($argv as $a) if(strpos($a,'--device=')===0) $deviceId=(int)substr($a,9);
 if (!$deviceId || !in_array('--send',$argv,true)) throw new Exception('Usage: php push_test.php --list OR php push_test.php --device=ID --send');
 $s=$db->prepare('SELECT fcm_token FROM cc_push_devices WHERE id=? AND enabled=1'); $s->execute(array($deviceId)); $token=$s->fetchColumn();
 if (!$token) throw new Exception('Device not found or disabled');
 $cfg=pushConfig(); $access=pushAccessToken($cfg);
 list($status,$body)=pushSend($cfg,$access,$token,'FreePBX QStat','Тестовое уведомление с сервера',array('type'=>'test','screen'=>'incoming'));
 echo 'FCM HTTP '.$status.PHP_EOL;
 if ($status!==200) {list($code,$message)=pushError($status,$body); echo $code.' '.$message.PHP_EOL; exit(1);}
 echo 'Accepted by FCM (not proof of device display).'.PHP_EOL;
} catch(Exception $e) {fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
