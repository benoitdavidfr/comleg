<?php
/*PhpDoc:
name: exportmnd.php
title: exportmnd.php - export des macro-noeuds de MongoDB en geojson
includes: [ mongodbclient.inc.php, fencoder.inc.php ]
*/
require_once __DIR__.'/mongodbclient.inc.php';
require_once __DIR__.'/fencoder.inc.php';

$adminexp = mongoDbClient()->adminexp;

$gendate = date(DateTime::ATOM);
$encoder = FeatureEncoder::create('geojson', ['zoom'=>20]);

foreach($adminexp->c_g2_mnd->find([]) as $mnd) {
  $mnd = json_decode(json_encode($mnd), true);
  //echo "mnd="; print_r($mnd);
  $mnd['properties'] = ['lims'=>implode(',',$mnd['lims'])];
  $encoder->feature($mnd);
  //echo "mnd-$mnd[_id]:\n",'  ',implode("\n  ",$mnd['lims']),"\n";
}
$encoder->end();
