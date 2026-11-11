<?php
require __DIR__.'/../source/lib.php';
function storage_check($ok,$message){if(!$ok)throw new RuntimeException($message);}
function pool_disk($name,$rotational='0'){return ['name'=>$name,'type'=>'Cache','device'=>'device','status'=>'DISK_OK','rotational'=>$rotational];}
$mounts="/dev/a /mnt/fast btrfs rw 0 0\n/dev/b /mnt/ssd zfs rw 0 0\n/dev/c /mnt/ssd2 btrfs rw 0 0\n";
$disks=['fast'=>pool_disk('fast'),'fast2'=>pool_disk('fast2'),'ssd'=>pool_disk('ssd')];
storage_check(dn_auto_database_path(['fast','ssd'],$disks,['shareCachePool'=>'fast'],$mounts)==='/mnt/fast/appdata/disknap/stats.sqlite','优先 appdata 的 SSD 池');
storage_check(dn_auto_database_path(['fast'],$disks,[],$mounts)==='/mnt/fast/appdata/disknap/stats.sqlite','唯一 SSD 池自动选择');
storage_check(dn_auto_database_path(['fast','ssd'],array_replace($disks,['ssd'=>pool_disk('ssd','1')]),['shareCachePool'=>'ssd'],$mounts)==='/mnt/fast/appdata/disknap/stats.sqlite','appdata 在机械池时回退唯一 SSD 池');
foreach([
 [['fast','ssd'],$disks,[],$mounts],
 [['fast'],array_replace($disks,['fast2'=>pool_disk('fast2','1')]),[],$mounts],
 [['fast'],['fast'=>array_replace(pool_disk('fast'),['rotational'=>''])],[],$mounts],
 [['fast'],['fast'=>array_replace(pool_disk('fast'),['status'=>'DISK_NP'])],[],$mounts],
 [['fast'],$disks,[],"tmpfs /mnt/fast tmpfs rw 0 0\n"],
 [[],[],[],$mounts]
] as $case){$rejected=false;try{dn_auto_database_path(...$case);}catch(RuntimeException $e){$rejected=true;}storage_check($rejected,'多候选、混合机械盘、未知、离线或未挂载时不误选');}
$separate=['ssd'=>pool_disk('ssd'),'ssd2'=>pool_disk('ssd2','1')];
storage_check(dn_auto_database_path(['ssd','ssd2'],$separate,[],$mounts)==='/mnt/ssd/appdata/disknap/stats.sqlite','名称含数字的独立池不误当成员');
storage_check(dn_database_path(['database_path'=>'/mnt/custom/disknap.sqlite'])==='/mnt/custom/disknap.sqlite','显式路径不重新选择');
$configFile=tempnam(sys_get_temp_dir(),'disknap-settings-');
try{
 file_put_contents($configFile,json_encode(['database_path'=>'/mnt/user/invalid.sqlite']));
 $page=file_get_contents(__DIR__.'/../source/DiskNapSettings.page');
 $page=substr($page,strpos($page,'<?php'));
 $page=str_replace(['/usr/local/emhttp/plugins/disknap/lib.php','/boot/config/plugins/disknap/config.json'],[realpath(__DIR__.'/../source/lib.php'),$configFile],$page);
 $var=['csrf_token'=>'test'];ob_start();eval('?>'.$page);$html=ob_get_clean();
 storage_check(strpos($html,'role="alert"')!==false && strpos($html,'name="database_path"')!==false,'路径识别失败仍显示可填写的设置表单');
}finally{unlink($configFile);}
echo "存储池选择测试通过\n";
