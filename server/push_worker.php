#!/usr/bin/env php
<?php
require_once __DIR__.'/push_common.php';
if (php_sapi_name()!=='cli') exit(1);
$fp=fopen('/tmp/qstat-push-worker.lock','c'); if (!$fp || !flock($fp,LOCK_EX|LOCK_NB)) exit(0);
try {
 $db=pushDb();
 // Recover leases after process interruption; FCM delivery is at-least-once.
 $db->exec("UPDATE cc_push_deliveries SET state='pending',locked_until=NULL,next_attempt_at=NOW() WHERE state='sending' AND locked_until<NOW()");
 // Materialize deliveries for all devices active at processing time.
 $db->exec("INSERT IGNORE INTO cc_push_deliveries(event_id,device_id,state,attempts,next_attempt_at,created_at,updated_at) SELECT e.id,d.id,'pending',0,NOW(),NOW(),NOW() FROM cc_push_events e CROSS JOIN cc_push_devices d WHERE d.enabled=1 AND e.created_at >= DATE_SUB(NOW(),INTERVAL 7 DAY)");
 $jobs=$db->query("SELECT x.id,x.device_id,x.event_id,d.fcm_token,e.client_name,e.client_number,e.queue_number,e.callid,e.report_id FROM cc_push_deliveries x JOIN cc_push_devices d ON d.id=x.device_id JOIN cc_push_events e ON e.id=x.event_id WHERE x.state='pending' AND x.next_attempt_at<=NOW() AND d.enabled=1 ORDER BY x.id LIMIT 100")->fetchAll();
 if (!$jobs) exit(0);
 $cfg=pushConfig(); $access=pushAccessToken($cfg);
 foreach($jobs as $j) {
  $claim=$db->prepare("UPDATE cc_push_deliveries SET state='sending', attempts=attempts+1, locked_until=DATE_ADD(NOW(),INTERVAL 2 MINUTE),updated_at=NOW() WHERE id=? AND state='pending'");$claim->execute(array($j['id'])); if (!$claim->rowCount()) continue;
  $name=$j['client_name']?$j['client_name']:$j['client_number']; $body=$name.' · Очередь '.$j['queue_number'];
  $status=0;$response='';
  try {list($status,$response)=pushSend($cfg,$access,$j['fcm_token'],'Пропущенный звонок',$body,array('type'=>'missed_call','call_id'=>(string)$j['report_id'],'callid'=>(string)$j['callid'],'screen'=>'incoming'));}
  catch(Exception $e) {$response=$e->getMessage();}
  if ($status===200) {$s=$db->prepare("UPDATE cc_push_deliveries SET state='sent',sent_at=NOW(),locked_until=NULL,last_error=NULL,updated_at=NOW() WHERE id=?");$s->execute(array($j['id']));continue;}
  list($code,$message)=pushError($status,$response);
  if ($code==='UNREGISTERED' || $code==='SENDER_ID_MISMATCH') {
   $s=$db->prepare('UPDATE cc_push_devices SET enabled=0,updated_at=NOW() WHERE id=?');$s->execute(array($j['device_id']));
  }
  $permanent=($code==='UNREGISTERED'||$code==='SENDER_ID_MISMATCH'||$code==='INVALID_ARGUMENT'||$status===400||$status===403||$status===404);
  $state=($permanent || $status===401) ? 'failed' : 'pending';
  // Limit attempts; retry transient errors with exponential backoff.
  $s=$db->prepare("UPDATE cc_push_deliveries SET state=?,last_error=?,locked_until=NULL,next_attempt_at=DATE_ADD(NOW(),INTERVAL ? SECOND),updated_at=NOW() WHERE id=?");
  $attempt=$db->prepare('SELECT attempts FROM cc_push_deliveries WHERE id=?');$attempt->execute(array($j['id']));$n=(int)$attempt->fetchColumn();
  if ($n>=8) $state='failed';
  $delay=min(3600,30*pow(2,min($n,7)));
  $s->execute(array($state,$message,$delay,$j['id']));
  error_log('QStat FCM HTTP '.$status.' '.$code.' '.$message);
 }
} catch(Exception $e) {error_log('QStat push worker: '.$e->getMessage());fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
