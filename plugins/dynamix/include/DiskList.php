<?PHP
/* Copyright 2015, Lime Technology
 * Copyright 2015, Bergware International.
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
require_once('Helpers.php');

$disks   = parse_ini_file('state/disks.ini',true);
$var     = parse_ini_file('state/var.ini');
$sec     = parse_ini_file('state/sec.ini',true);
$sec_nfs = parse_ini_file('state/sec_nfs.ini',true);
$sec_afp = parse_ini_file('state/sec_afp.ini',true);
$compute = $_GET['compute'];
$path    = $_GET['path'];
$prev    = $_GET['prev'];

$display           = [];
$display['scale']  = $_GET['scale'];
$display['number'] = $_GET['number'];

// Display export settings
function disk_share_settings($protocol,$share) {
  if (empty($share)) return;
  if ($protocol!='yes' || $share['export']=='-') return "-";
  if ($share['export']=='e') return ucfirst($share['security']);
  return '<em>'.ucfirst($share['security']).'</em>';
}

// Compute all disk shares
if ($compute=='yes') foreach ($disks as $name => $disk) if ($disk['exportable']=='yes') exec("webGui/scripts/disk_size \"$name\" \"ssz2\"");

// Share size per disk
$preserve = ($path==$prev || $compute=='yes');
$ssz2 = array();
foreach (glob("state/*.ssz2", GLOB_NOSORT) as $entry) {
  if ($preserve) {
    $ssz2[basename($entry, ".ssz2")] = parse_ini_file($entry);
  } else {
    unlink($entry);
  }
}

// Build table
$row = 0;
foreach ($disks as $name => $disk) {
  $row++;
  if ($disk['type']=='Flash') continue;
  if ($disk['fsColor']=='grey-off') continue;
  if ($disk['exportable']=='no') continue;
  $export = true;
  $ball = "/webGui/images/{$disk['fsColor']}.png";
  switch ($disk['fsColor']) {
    case 'green-on':  $help = 'All files protected'; break;
    case 'yellow-on': $help = 'All files unprotected'; break;
  }
  echo "<tr>";
  echo "<td><a class='info nohand' onclick='return false'><img src='$ball' class='icon'><span style='left:18px'>$help</span></a><a href='$path/Disk?name=$name' onclick=\"$.cookie('one','tab1',{path:'/'})\">$name</a></td>";
  echo "<td>{$disk['comment']}</td>";
  echo "<td>".disk_share_settings($var['shareSMBEnabled'], $sec[$name])."</td>";
  echo "<td>".disk_share_settings($var['shareNFSEnabled'], $sec_nfs[$name])."</td>";
  echo "<td>".disk_share_settings($var['shareAFPEnabled'], $sec_afp[$name])."</td>";
  $cmd="/webGui/scripts/disk_size"."&arg1=".urlencode($name)."&arg2=ssz2";
  if (array_key_exists($name, $ssz2)) {
    echo "<td>".my_scale(($disk['fsSize'])*1024, $unit)." $unit</td>";
    echo "<td>".my_scale($disk['fsFree']*1024, $unit)." $unit</td>";
    echo "<td><a href='$path/Browse?dir=/mnt/$name'><img src='/webGui/images/explore.png' title='Browse /mnt/$name'></a></td>";
    echo "</tr>";
    foreach ($ssz2[$name] as $share_name => $share_size) {
      if ($share_name!="total") {
        echo "<tr class='share_status_size'>";
        echo "<td>$share_name:</td>";
        echo "<td></td>";
        echo "<td></td>";
        echo "<td></td>";
        echo "<td></td>";
        echo "<td class='disk-$row-1'>".my_scale($share_size*1024, $unit)." $unit</td>";
        echo "<td class='disk-$row-2'>".my_scale($disk['fsFree']*1024, $unit)." $unit</td>";
        echo "<td><a href='/update.htm?cmd=$cmd' target='progressFrame' title='Recompute...' onclick='$(\".disk-$row-1\").html(\"Please wait...\");$(\".disk-$row-2\").html(\"\");'><i class='fa fa-refresh icon'></i></a></td>";
        echo "</tr>";
      }
    }
  } else {
    echo "<td><a href='/update.htm?cmd=$cmd' target='progressFrame' onclick=\"$(this).text('Please wait...')\">Compute...</a></td>";
    echo "<td>".my_scale($disk['fsFree']*1024, $unit)." $unit</td>";
    echo "<td><a href='$path/Browse?dir=/mnt/$name'><img src='/webGui/images/explore.png' title='Browse /mnt/$name'></a></td>";
    echo "</tr>";
  }
}
if (!isset($export)) {
  echo "<tr><td colspan='8' style='text-align:center'>There are no exportable disk shares</td></tr>";
}
?>
