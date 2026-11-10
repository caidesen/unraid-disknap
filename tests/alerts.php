<?php
require __DIR__.'/../source/alerts.php';
function check_alert($ok,$message){if(!$ok)throw new RuntimeException($message);}
$dir=sys_get_temp_dir().'/disknap-alert-test-'.bin2hex(random_bytes(6));mkdir($dir);
try{
 $db=dn_db_open($dir.'/test.sqlite',true);
 $disk=['disk1'=>['type'=>'Data','rotational'=>'1','id'=>"disk'one",'device'=>'sdb','status'=>'DISK_OK','spundown'=>'0']];
 $state=dn_sample(dn_empty(),$disk,10000,1000,'boot',5);dn_db_save($db,$state);
 $step=function($flag,$elapsed=300,$boot='boot')use(&$state,&$disk,$db){
  $disk['disk1']['spundown']=$flag;
  $next=dn_sample($state,$disk,$state['sample_at']+$elapsed,$state['uptime']+$elapsed,$boot,5);
  dn_db_save($db,$next,$state);$state=$next;
 };
 $messages=[];$sender=function($s,$d,$m)use(&$messages){$messages[]=[$s,$d,$m];return true;};
 $step('1');check_alert(dn_alerts($db,[],$state,$sender)===0,'休眠不提醒');
 $step('0');check_alert(dn_alerts($db,[],$state,$sender)===1,'一次短休眠后唤醒即通知');
 check_alert(strpos($messages[0][1],'5 分钟')!==false,'通知包含休眠时长');
 check_alert(dn_alerts($db,[],$state,$sender)===0,'同一记录不重复通知');
 $step('1');$step('0');check_alert(dn_alerts($db,[],$state,$sender)===0,'同盘冷却期间不提醒');
 $db->exec('DELETE FROM alert_state');
 check_alert(dn_alerts($db,['alerts_enabled'=>false],$state,$sender)===0,'关闭通知');
 check_alert(dn_alerts($db,['short_sleep_minutes'=>4],$state,$sender)===0,'超过设置时长不提醒');
 $failed=false;try{dn_alerts($db,[],$state,function(){return false;});}catch(RuntimeException $e){$failed=true;}
 check_alert($failed && $db->querySingle('SELECT count(*) FROM alert_state')===0,'发送失败不写冷却');
 check_alert(dn_alerts($db,[],$state,$sender)===1,'发送失败后可重试');
 $db->exec('DELETE FROM alert_state');
 $step('1');$step('1');$step('0');
 check_alert(dn_alerts($db,[],$state,$sender)===1,'正好10分钟也通知');
 $step('1');$step('0',3600);
 check_alert(dn_alerts($db,[],$state,$sender)===0,'中断后唤醒不提醒');
 $step('1');$step('1');$step('1');$step('0');
 check_alert(dn_alerts($db,[],$state,$sender)===0,'正常长休眠不提醒');
 $step('1');$step('');$step('1');$step('0');
 check_alert(dn_alerts($db,[],$state,$sender)===0,'未知状态打断休眠段');
 $step('1');$step('1',300,'newboot');$step('0',300,'newboot');
 check_alert(dn_alerts($db,[],$state,$sender)===0,'重启打断休眠段');
 $step('1',300,'newboot');$step('0',300,'newboot');
 check_alert(dn_alerts($db,[],$state,$sender)===1,'重启后新短休眠正常提醒');
 $step('0',3600,'newboot');$step('1',300,'newboot');$step('0',300,'newboot');
 check_alert(dn_alerts($db,[],$state,$sender)===1,'冷却到期后再次提醒');
 $db->exec('DELETE FROM alert_state');
 $step('0',300,'newboot');check_alert(dn_alerts($db,[],$state,$sender)===0,'持续运行不通知');
 foreach([['short_sleep_minutes'=>0],['short_sleep_minutes'=>1441],['alerts_enabled'=>'yes'],['alert_cooldown_minutes'=>1441]] as $bad){
  $failed=false;try{dn_alert_config($bad);}catch(RuntimeException $e){$failed=true;}check_alert($failed,'配置边界校验');
 }
 $db->close();echo "短休眠通知测试通过\n";
}finally{foreach(glob($dir.'/*') as $file)unlink($file);rmdir($dir);}
