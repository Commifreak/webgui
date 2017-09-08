<?PHP
/* Copyright 2005-2017, Lime Technology
 * Copyright 2012-2017, Bergware International.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 */
?>
<?
$docroot = $docroot ?: $_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';
require_once "$docroot/webGui/include/Helpers.php";

$path  = $_POST['path'];
$var   = parse_ini_file('state/var.ini');
$devs  = parse_ini_file('state/devs.ini',true);
$disks = parse_ini_file('state/disks.ini',true);
$diskio= @parse_ini_file('state/diskload.ini');
$sum   = ['count'=>0, 'temp'=>0, 'fsSize'=>0, 'fsUsed'=>0, 'fsFree'=>0, 'ioReads'=>0, 'ioWrites'=>0, 'numReads'=>0, 'numWrites'=>0, 'numErrors'=>0];
extract(parse_plugin_cfg('dynamix',true));

require_once "$docroot/webGui/include/CustomMerge.php";

function in_parity_log($log,$timestamp) {
  if (file_exists($log)) {
    $handle = fopen($log, 'r');
    while (($line = fgets($handle)) !== false) {
      if (strpos($line,$timestamp)!==false) break;
    }
    fclose($handle);
  }
  return !empty($line);
}
function device_info(&$disk,$online) {
  global $path, $var;
  $name = $disk['name'];
  $fancyname = $disk['type']=='New' ? $name : my_disk($name);
  $type = $disk['type']=='Flash' || $disk['type']=='New' ? $disk['type'] : 'Device';
  $action = strpos($disk['color'],'blink')===false ? 'down' : 'up';
  if ($var['fsState']=='Started' && $type!='Flash') {
    $cmd = $type=='New' ? "cmd=/webGui/scripts/hd_parm&arg1=$action&arg2=$name" : "cmdSpin$action=$name";
    $ctrl = "<a href='update.htm?$cmd&csrf_token={$var['csrf_token']}' title='Click to spin $action device' class='none' target='progressFrame' onclick=\"$.removeCookie('one',{path:'/'});\"><i class='fa fa-sort-$action spacing'></i></a>";
  } else
    $ctrl = '';
  switch ($disk['color']) {
    case 'green-on': $help = 'Normal operation, device is active'; break;
    case 'green-blink': $help = 'Device is in standby mode (spun-down)'; break;
    case 'blue-on': $help = 'New device'; break;
    case 'blue-blink': $help = 'New device, in standby mode (spun-down)'; break;
    case 'yellow-on': $help = $disk['type']=='Parity' ? 'Parity is invalid' : 'Device contents emulated'; break;
    case 'yellow-blink': $help = $disk['type']=='Parity' ? 'Parity is invalid, in standby mode (spun-down)' : 'Device contents emulated, in standby mode (spun-down)'; break;
    case 'red-on': case 'red-blink': $help = $disk['type']=='Parity' ? 'Parity device is disabled' : 'Device is disabled, contents emulated'; break;
    case 'red-off': $help = $disk['type']=='Parity' ? 'Parity device is missing' : 'Device is missing (disabled), contents emulated'; break;
    case 'grey-off': $help = 'Device not present'; break;
  }
  $status = "$ctrl<a class='info nohand' onclick='return false'><img src='/webGui/images/{$disk['color']}.png' class='icon'><span>$help</span></a>";
  $link = (strpos($disk['status'], 'DISK_NP')===false || $disk['name']=="cache") ? "<a href=\"".htmlspecialchars("$path/$type?name=$name")."\">".$fancyname."</a>" : $fancyname;
  switch ($disk['luksState']) {
    case 0: $luks = ""; break;
    case 1: $luks = "<i class='padlock fa ".($online?'fa-unlock-alt green-text':'fa-lock grey-text')."' title='Encrypted'></i>"; break;
    case 2: $luks = "<i class='padlock fa fa-lock red-text' title='Missing encryption key'></i>"; break;
    case 3: $luks = "<i class='padlock fa fa-lock red-text' title='Wrong encryption key'></i>"; break;
   default: $luks = "<i class='padlock fa fa-lock red-text' title='Unknown error'></i>"; break;
  }
  return $status.$link.$luks;
}
function device_browse(&$disk) {
  global $path;
  $dir = $disk['name']=='flash' ? "/boot" : "/mnt/{$disk['name']}";
  return "<a href=\"".htmlspecialchars("$path/Browse?dir=$dir")."\"><img src='/webGui/images/explore.png' title='Browse $dir'></a>";
}
function device_desc(&$disk) {
  global $var;
  $size = my_scale($disk['size'] ? $disk['size']*1024 : $disk['sectors']*$disk['sector_size'],$unit);
  $log = $var['fsState']=='Started' ? "<a href=\"#\" title=\"Disk Log Information\" onclick=\"openBox('/webGui/scripts/disk_log&arg1={$disk['device']}','Disk Log Information',600,900,false);return false\"><i class=\"fa fa-hdd-o icon\"></i></a>" : "";
  return  $log.my_id($disk['id'])." - $size $unit ({$disk['device']})";
}
function assignment(&$disk) {
  global $var, $devs;
  $out = "<form method='POST' name=\"{$disk['name']}Form\" action='/update.htm' target='progressFrame'>";
  $out .= "<input type='hidden' name='changeDevice' value='apply'>";
  $out .= "<input type='hidden' name='csrf_token' value='{$var['csrf_token']}'>";
  $out .= "<select class=\"slot\" name=\"slotId.{$disk['idx']}\" onChange=\"{$disk['name']}Form.submit()\">";
  $empty = ($disk['idSb']!='' ? 'no device' : 'unassigned');
  if ($disk['id']!='') {
    $out .= "<option value=\"{$disk['id']}\" selected>".device_desc($disk)."</option>";
    $out .= "<option value=''>$empty</option>";
  } else
    $out .= "<option value='' selected>$empty</option>";
  if ($disk['type']=="Cache")
    foreach ($devs as $dev) {$out .= "<option value=\"{$dev['id']}\">".device_desc($dev)."</option>";}
  else
    foreach ($devs as $dev) if ($dev['tag']==0) {$out .= "<option value=\"{$dev['id']}\">".device_desc($dev)."</option>";}
  return "$out</select></form>";
}
function fs_info(&$disk) {
  global $display;
  if ($disk['fsStatus']=='-') {
    echo "<td colspan='5'></td>";
    return;
  } elseif ($disk['fsStatus']=='Mounted') {
    echo "<td>".str_replace('luks:','',$disk['fsType'])."</td>";
    echo "<td>".my_scale($disk['fsSize']*1024,$unit)." $unit</td>";
    if ($display['text']%10==0) {
      echo "<td>".my_scale($disk['fsUsed']*1024,$unit)." $unit</td>";
    } else {
      $used = $disk['fsSize'] ? 100-round(100*$disk['fsFree']/$disk['fsSize']) : 0;
      echo "<td><div class='usage-disk'><span style='margin:0;width:$used%' class='".usage_color($disk,$used,false)."'><span>".my_scale($disk['fsUsed']*1024,$unit)." $unit</span></span></div></td>";
    }
    if ($display['text']<10 ? $display['text']%10==0 : $display['text']%10!=0) {
      echo "<td>".my_scale($disk['fsFree']*1024,$unit)." $unit</td>";
    } else {
      $free = $disk['fsSize'] ? round(100*$disk['fsFree']/$disk['fsSize']) : 0;
      echo "<td><div class='usage-disk'><span style='margin:0;width:$free%' class='".usage_color($disk,$free,true)."'><span>".my_scale($disk['fsFree']*1024,$unit)." $unit</span></span></div></td>";
    }
    echo "<td>".device_browse($disk)."</td>";
  } else
    echo "<td>".str_replace('luks:','',$disk['fsType'])."</td><td colspan='4' style='text-align:center'>{$disk['fsStatus']}";
}
function my_diskio($data) {
  return my_scale($data,$unit,1)." $unit/s";
}
function array_offline(&$disk,$w) {
  $warning = $w ? '<span class="red-text"><em>ALL DATA ON THIS DISK WILL BE ERASED WHEN ARRAY IS STARTED</em></span>' : '';
  echo "<tr>";
  switch ($disk['status']) {
  case 'DISK_NP':
  case 'DISK_NP_DSBL':
    echo "<td>".device_info($disk,false)."</td>";
    echo "<td>".assignment($disk)."</td>";
    echo "<td colspan='9'></td>";
    break;
  case 'DISK_OK':
    $warning = '';
  case 'DISK_INVALID':
  case 'DISK_DSBL':
  case 'DISK_DSBL_NEW':
  case 'DISK_NEW':
    echo "<td>".device_info($disk,false)."</td>";
    echo "<td>".assignment($disk)."</td>";
    echo "<td>".my_temp($disk['temp'])."</td>";
    echo "<td colspan='8'>$warning</td>";
    break;
  case 'DISK_NP_MISSING':
    echo "<td>".device_info($disk,false)."<br><span class='diskinfo'><em>Missing</em></span></td>";
    echo "<td>".assignment($disk)."<em>{$disk['idSb']} - ".my_scale($disk['sizeSb']*1024,$unit)." $unit</em></td>";
    echo "<td colspan='9'></td>";
    break;
  case 'DISK_WRONG':
    echo "<td>".device_info($disk,false)."<br><span class='diskinfo'><em>Wrong</em></span></td>";
    echo "<td>".assignment($disk)."<em>{$disk['idSb']} - ".my_scale($disk['sizeSb']*1024,$unit)." $unit</em></td>";
    echo "<td>".my_temp($disk['temp'])."</td>";
    echo "<td colspan='8'>$warning</td>";
    break;
  }
  echo "</tr>";
}
function array_online(&$disk) {
  global $sum, $diskio;
  $dev = $disk['device'];
  $data = isset($diskio[$dev]) ? explode(' ',$diskio[$dev]) : [];
  if (is_numeric($disk['temp'])) {
    $sum['count']++;
    $sum['temp'] += $disk['temp'];
  }
  $sum['ioReads'] += $data[0];
  $sum['ioWrites'] += $data[1];
  $sum['numReads'] += $disk['numReads'];
  $sum['numWrites'] += $disk['numWrites'];
  $sum['numErrors'] += $disk['numErrors'];
  if (isset($disk['fsFree'])) {
    $disk['fsUsed'] = $disk['fsSize']-$disk['fsFree'];
    $sum['fsSize'] += $disk['fsSize'];
    $sum['fsUsed'] += $disk['fsUsed'];
    $sum['fsFree'] += $disk['fsFree'];
  }
  echo "<tr>";
  switch ($disk['status']) {
  case 'DISK_NP':
    if ($disk['name']=="cache") {
      echo "<td>".device_info($disk,true)."</td>";
      echo "<td><em>Not installed</em></td>";
      echo "<td colspan='4'></td>";
      fs_info($disk);
    }
    break;
  case 'DISK_NP_DSBL':
    echo "<td>".device_info($disk,true)."</td>";
    echo "<td><em>Not installed</em></td>";
    echo "<td colspan='4'></td>";
    fs_info($disk);
    break;
  case 'DISK_DSBL':
  default:
    echo "<td>".device_info($disk,true)."</td>";
    echo "<td>".device_desc($disk)."</td>";
    echo "<td>".my_temp($disk['temp'])."</td>";
    echo "<td><span class='diskio'>".my_diskio($data[0])."</span><span class='number'>".my_number($disk['numReads'])."</span></td>";
    echo "<td><span class='diskio'>".my_diskio($data[1])."</span><span class='number'>".my_number($disk['numWrites'])."</span></td>";
    echo "<td>".my_number($disk['numErrors'])."</td>";
    fs_info($disk);
    break;
  }
  echo "</tr>";
}
function my_clock($time) {
  if (!$time) return 'less than a minute';
  $days = floor($time/1440);
  $hour = $time/60%24;
  $mins = $time%60;
  return plus($days,'day',($hour|$mins)==0).plus($hour,'hour',$mins==0).plus($mins,'minute',true);
}
function read_disk($dev, $part) {
  global $var;
  switch ($part) {
  case 'color':
    return exec("hdparm -C ".escapeshellarg("/dev/$dev")."|grep -Po active") ? 'blue-on' : 'blue-blink';
  case 'temp':
    $smart = "/var/local/emhttp/smart/$dev";
    if (!file_exists($smart) || (time()-filemtime($smart)>=$var['poll_attributes'])) exec("smartctl -n standby -A ".escapeshellarg("/dev/$dev")." >".escapeshellarg($smart)." &");
    $temp = exec("awk '\$1==190||\$1==194{print \$10;exit}' $smart");
    return $temp ?: '*';
  }
}
function show_totals($text) {
  global $var, $display, $sum;
  echo "<tr class='tr_last'>";
  echo "<td><img src='/webGui/images/sum.png' class='icon'>Total</td>";
  echo "<td>$text</td>";
  echo "<td>".($sum['count']>0 ? my_temp(round($sum['temp']/$sum['count'],1)) : '*')."</td>";
  echo "<td><span class='diskio'>".my_diskio($sum['ioReads'])."</span><span class='number'>".my_number($sum['numReads'])."</span></td>";
  echo "<td><span class='diskio'>".my_diskio($sum['ioWrites'])."</span><span class='number'>".my_number($sum['numWrites'])."</span></td>";
  echo "<td>".my_number($sum['numErrors'])."</td>";
  echo "<td></td>";
  if (strstr($text,'Array') && ($var['startMode']=='Normal')) {
    echo "<td>".my_scale($sum['fsSize']*1024,$unit)." $unit</td>";
    if ($display['text']%10==0) {
      echo "<td>".my_scale($sum['fsUsed']*1024,$unit)." $unit</td>";
    } else {
      $used = $sum['fsSize'] ? 100-round(100*$sum['fsFree']/$sum['fsSize']) : 0;
      echo "<td><div class='usage-disk'><span style='margin:0;width:$used%' class='".usage_color($display,$used,false)."'><span>".my_scale($sum['fsUsed']*1024,$unit)." $unit</span></span></div></td>";
    }
    if ($display['text']<10 ? $display['text']%10==0 : $display['text']%10!=0) {
      echo "<td>".my_scale($sum['fsFree']*1024,$unit)." $unit</td>";
    } else {
      $free = $sum['fsSize'] ? round(100*$sum['fsFree']/$sum['fsSize']) : 0;
      echo "<td><div class='usage-disk'><span style='margin:0;width:$free%' class='".usage_color($display,$free,true)."'><span>".my_scale($sum['fsFree']*1024,$unit)." $unit</span></span></div></td>";
    }
    echo "<td></td>";
  } else
    echo "<td colspan=4></td>";
  echo "</tr>";
}
function array_slots() {
  global $var;
  $min = max($var['sbNumDisks'], 3);
  $max = $var['MAX_ARRAYSZ'];
  $out = "<form method='POST' action='/update.htm' target='progressFrame'>";
  $out .= "<input type='hidden' name='csrf_token' value='{$var['csrf_token']}'>";
  $out .= "<input type='hidden' name='changeSlots' value='apply'>";
  $out .= "<select class='auto' name='SYS_ARRAY_SLOTS' onChange='this.form.submit()'>";
  for ($n=$min; $n<=$max; $n++) {
    $selected = ($n == $var['SYS_ARRAY_SLOTS'])? ' selected' : '';
    $out .= "<option value='$n'{$selected}>$n</option>";
  }
  $out .= "</select></form>";
  return $out;
}
function cache_slots() {
  global $var;
  $min = $var['cacheSbNumDisks'];
  $max = $var['MAX_CACHESZ'];
  $out = "<form method='POST' action='/update.htm' target='progressFrame'>";
  $out .= "<input type='hidden' name='csrf_token' value='{$var['csrf_token']}'>";
  $out .= "<input type='hidden' name='changeSlots' value='apply'>";
  $out .= "<select class='auto' name='SYS_CACHE_SLOTS' onChange='this.form.submit()'>";
  for ($n=$min; $n<=$max; $n++) {
    $option = $n ? $n : 'none';
    $selected = ($n == $var['SYS_CACHE_SLOTS'])? ' selected' : '';
    $out .= "<option value='$n'{$selected}>$option</option>";
  }
  $out .= "</select></form>";
  return $out;
}
switch ($_POST['device']) {
case 'array':
  if ($var['fsState']=='Stopped') {
    foreach ($disks as $disk) {if ($disk['type']=='Parity') array_offline($disk,true);}
    echo "<tr class='tr_last'><td style='height:12px' colspan='11'></td></tr>";
    foreach ($disks as $disk) {if ($disk['type']=='Data') array_offline($disk,$var['mdResyncAction']=='clear');}
    echo "<tr class='tr_last'><td><img src='/webGui/images/sum.png' class='icon'>Slots:</td><td colspan='9'>".array_slots()."</td><td></td></tr>";
  } else {
    foreach ($disks as $disk) {if ($disk['type']=='Parity' && $disk['status']!='DISK_NP_DSBL') array_online($disk);}
    foreach ($disks as $disk) {if ($disk['type']=='Data') array_online($disk);}
    if ($display['total']) show_totals('Array of '.my_word($var['mdNumDisks']).' devices');
  }
  break;
case 'flash':
  $disk = &$disks['flash'];
  $data = $diskio ? explode(' ',$diskio[$disk['device']]) : [];
  $disk['fsUsed'] = $disk['fsSize']-$disk['fsFree'];
  echo "<tr>";
  echo "<td>".device_info($disk,true)."</td>";
  echo "<td>".device_desc($disk)."</td>";
  echo "<td>*</td>";
  echo "<td><span class='diskio'>".my_diskio($data[0])."</span><span class='number'>".my_number($disk['numReads'])."</span></td>";
  echo "<td><span class='diskio'>".my_diskio($data[1])."</span><span class='number'>".my_number($disk['numWrites'])."</span></td>";
  echo "<td>".my_number($disk['numErrors'])."</td>";
  fs_info($disk);
  echo "</tr>";
  break;
case 'cache':
  if ($var['fsState']=='Stopped') {
    foreach ($disks as $disk) {if ($disk['type']=='Cache') array_offline($disk,false);}
    echo "<tr class='tr_last'><td><img src='/webGui/images/sum.png' class='icon'>Slots:</td><td colspan='9'>".cache_slots()."</td><td></td></tr>";
  } else {
    foreach ($disks as $disk) {if ($disk['type']=='Cache') array_online($disk);}
    if ($display['total'] && $var['cacheSbNumDisks']>1) show_totals('Pool of '.my_word($var['cacheNumDevices']).' devices');
  }
  break;
case 'open':
  foreach ($devs as $disk) {
    $dev = $disk['device'];
    $data = $diskio ? explode(' ',$diskio[$dev]) : [];
    $disk['name'] = $dev;
    $disk['type'] = 'New';
    $disk['color'] = read_disk($dev,'color');
    $disk['temp'] = read_disk($dev,'temp');
    echo "<tr>";
    echo "<td>".device_info($disk,true)."</td>";
    echo "<td>".device_desc($disk)."</td>";
    echo "<td>".my_temp($disk['temp'])."</td>";
    echo "<td><span class='diskio'>".my_diskio($data[0])."</span><span class='number'>".my_number($data[2])."</span></td>";
    echo "<td><span class='diskio'>".my_diskio($data[1])."</span><span class='number'>".my_number($data[3])."</span></td>";
    if (file_exists("/tmp/preclear_stat_$dev")) {
      $text = exec("cut -d'|' -f3 /tmp/preclear_stat_$dev|sed 's:\^n:\<br\>:g'");
      if (strpos($text,'Total time')===false) $text = 'Preclear in progress... '.$text;
      echo "<td colspan='6' style='text-align:right'><em>$text</em></td>";
    } else
      echo "<td colspan='6'></td>";
    echo "</tr>";
  }
  break;
case 'parity':
  $data = [];
  if ($var['mdResync']>0) {
    $data[] = my_scale($var['mdResync']*1024,$unit)." $unit";
    $data[] = my_clock(floor((time()-$var['sbUpdated'])/60));
    $data[] = my_scale($var['mdResyncPos']*1024,$unit)." $unit (".number_format(($var['mdResyncPos']/($var['mdResync']/100+1)),1,substr($display['number'],0,1),'')." %)";
    $data[] = my_scale($var['mdResyncDb']*1024/$var['mdResyncDt'],$unit, 1)." $unit/sec";
    $data[] = my_clock(round(((($var['mdResyncDt']*(($var['mdResync']-$var['mdResyncPos'])/($var['mdResyncDb']/100+1)))/100)/60),0));
    $data[] = $var['sbSyncErrs'];
    echo implode(';',$data);
  } else {
    if ($var['sbSynced']==0 || $var['sbSynced2']==0) break;
    $log = '/boot/config/parity-checks.log';
    $timestamp = str_replace(['.0','.'],['  ',' '],date('M.d H:i:s',$var['sbSynced2']));
    if (in_parity_log($log,$timestamp)) break;
    $duration = $var['sbSynced2'] - $var['sbSynced'];
    $status = $var['sbSyncExit'];
    $speed = ($status==0) ? my_scale($var['mdResyncSize']*1024/$duration,$unit,1)." $unit/s" : "Unavailable";
    $error = $var['sbSyncErrs'];
    $year = date('Y',$var['sbSynced2']);
    if ($status==0||file_exists($log)) file_put_contents($log,"$year $timestamp|$duration|$speed|$status|$error\n",FILE_APPEND);
  }
  break;
}
?>
