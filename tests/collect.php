<?php
require __DIR__.'/../source/collect.php';
function verify_collect($ok,$message){if(!$ok)throw new RuntimeException($message);}
$dir=sys_get_temp_dir().'/disknap-collect-test-'.bin2hex(random_bytes(6));mkdir($dir);
try{
 $db=dn_db_open($dir.'/test.sqlite',true);
 $disks=[];
 foreach(['disk1','disk2'] as $slot)$disks[$slot]=['type'=>'Data','rotational'=>'1','id'=>$slot,'device'=>$slot==='disk1'?'sdb':'sdc','status'=>'DISK_OK','spundown'=>'0'];
 $sent=[];$send=function($s,$d,$m)use(&$sent){$sent[]=$d;return true;};
 $failFirst=function($s,$d,$m)use($send){return strpos($d,'disk1 ')===0?false:$send($s,$d,$m);};
 dn_collect($db,[],$disks,1000,1000,'boot',$send);
 foreach($disks as &$disk)$disk['spundown']='1';unset($disk);
 dn_collect($db,[],$disks,1300,1300,'boot',$send);
 foreach($disks as &$disk)$disk['spundown']='0';unset($disk);
 $failed=false;try{dn_collect($db,[],$disks,1600,1600,'boot',$failFirst);}catch(RuntimeException $e){$failed=true;}
 verify_collect($failed && count($sent)===1,'一盘发送失败不阻止其他盘发送');
 verify_collect($db->querySingle('SELECT count(*) FROM pending_alerts')===1,'失败提醒持久化待发');
 verify_collect($db->querySingle('SELECT count(*) FROM alert_state')===1,'仅成功盘进入冷却');
 verify_collect($db->querySingle('SELECT count(*) FROM observations')===3,'发送失败后采样已保存');
 $db->close();$db=dn_db_open($dir.'/test.sqlite',true);
 $failed=false;try{dn_collect($db,[],$disks,1660,1660,'boot',$failFirst);}catch(RuntimeException $e){$failed=true;}
 verify_collect($failed && $db->querySingle('SELECT count(*) FROM observations')===3,'未到采样时间也重试，不重复采样');
 verify_collect(dn_collect($db,[],$disks,1900,1900,'boot',$send)===1,'新采样无新唤醒仍重发失败提醒');
 verify_collect(count($sent)===2 && strpos($sent[1],'disk1 ')===0,'成功盘不重发，失败盘成功补发');
 verify_collect($db->querySingle('SELECT count(*) FROM pending_alerts')===0,'成功后移除待发提醒');
 verify_collect($db->querySingle('SELECT uptime FROM alert_state WHERE disk_id="disk1"')==1900,'冷却从补发成功时开始');
 verify_collect(dn_collect($db,[],$disks,1960,1960,'boot',$send)===0,'补发成功后不重复通知');
 $db->exec('DELETE FROM alert_state');
 foreach($disks as &$disk)$disk['spundown']='1';unset($disk);
 dn_collect($db,[],$disks,2200,2200,'boot',$send);
 foreach($disks as &$disk)$disk['spundown']='0';unset($disk);
 try{dn_collect($db,[],$disks,2500,2500,'boot',function(){throw new RuntimeException('模拟通知进程失败');});}catch(RuntimeException $e){}
 verify_collect($db->querySingle('SELECT count(*) FROM pending_alerts')===2,'通知抛出异常也保留待发提醒');
 verify_collect(dn_collect($db,['alerts_enabled'=>false],$disks,2560,2560,'boot',$send)===0
  && $db->querySingle('SELECT count(*) FROM pending_alerts')===0,'关闭通知清除待发，避免重新启用后补发旧提醒');
 dn_collect($db,[],$disks,2800,2800,'boot',$send);
 foreach($disks as &$disk)$disk['spundown']='1';unset($disk);
 dn_collect($db,[],$disks,3100,3100,'boot',$send);
 foreach($disks as &$disk)$disk['spundown']='0';unset($disk);
 try{dn_collect($db,[],$disks,3400,3400,'boot',function(){return false;});}catch(RuntimeException $e){}
 verify_collect($db->querySingle('SELECT count(*) FROM pending_alerts')===2,'重启前存在待发提醒');
 verify_collect(dn_collect($db,[],$disks,3500,100,'newboot',$send)===0
  && $db->querySingle('SELECT count(*) FROM pending_alerts')===0,'重启后清除旧启动待发提醒');
 $db->close();echo "采集与通知重试测试通过\n";
}finally{foreach(glob($dir.'/*') as $file)unlink($file);rmdir($dir);}
