<?php
/*PhpDoc:
name: genpol.php
title: genpol.php - génère les polygones en GeoJSON et SVG
doc: |
journal: |
  16-18/12/2017
    Suppression de la gestion de nbdigits dans l'affichage des coordonnées
    Les coordonnées doivent être convenablement arrondies avant l'affichage
  10-13/12/2017
    refonte
    génération effective
*/
//ini_set('memory_limit', '512M');
require_once __DIR__.'/mongodbclient.inc.php';
require_once __DIR__.'/topomap.inc.php';
require_once __DIR__.'/geomofring.inc.php';
require_once __DIR__.'/fencoder.inc.php';
require_once __DIR__.'/../geometry/inc.php';

//liste des regions
$regions = [
  'idf' => ['75','77','78','91','92','93','94','95'],
  'hdf' => ['02','59','60','62','80'],
  'gdest' => ['08','10','51','52','54','55','57','67','68','88'], // grand-est
  'bfc' => ['21','25','39','58','70','71','89','90'],
  'ara' => ['01','03','07','15','26','38','42','43','63','69','73','74'],
  'paca' => ['04','05','06','13','83','84'],
  'occitanie' => ['09','11','12','30','31','32','34','46','48','65','66','81','82'],
  'naquitaine' => ['16','17','19','23','24','33','40','47','64','79','86','87'],
  'pdl' => ['44','49','53','72','85'],
  'bzh' => ['22','29','35','56'],
  'nmdi' => ['14','27','50','61','76'],
  'cvdl' => ['18','28','36','37','41','45'],
  'corse' => ['2A','2B'],
  'dom' => ['97'],
];


if (($argc < 3)
    or !in_array($argv[1],array_merge(array_keys($regions),['nat','reg']))
    or !in_array($argv[2],['geojson','svg'])) {
  echo "usage: php $argv[0] {geo} {format}\n",
       "{geo} vaut:\n",
       " - nat : national\n",
       " - reg : par région\n",
       " - une des valeurs: ",implode(',',array_keys($regions)),"\n",
       "{format} vaut:\n",
       " - geojson : génération en GeoJSON\n",
       " - svg : génération en SVG\n";
  die();
}
$geo = $argv[1];
$format = $argv[2];

if ($geo == 'reg') {
  $ext = ($format=='geojson' ? 'json' : 'svg');
  foreach (array_keys($regions) as $reg)
    echo "php $argv[0] $reg $format > $reg.$ext\n";
  die();
}

$adminexp = mongoDbClient()->adminexp;

function mpolGeom(string $fid, array $pols, $c_g2_lim): array {
  $mpolGeom = [];
  foreach ($pols as $pol) {
    $polGeom = [];
    foreach ($pol['rings'] as $ring) {
      $polGeom[] = geomOfRing($fid, $ring, $c_g2_lim)->coordinates();
    }
    $mpolGeom[] = $polGeom;
  }
  if (count($mpolGeom)==1)
    return ['type'=>'Polygon', 'coordinates'=>$mpolGeom[0]];
  else
    return ['type'=>'MultiPolygon', 'coordinates'=>$mpolGeom];
}

// Lecture des macro-noeuds afin de les utiliser dans la génération des géométries
foreach($adminexp->c_g2_mnd->find([]) as $mnd) {
  $mnd = json_decode(json_encode($mnd), true);
  //echo "mnd="; print_r($mnd);
  new MacroNode($mnd);
}

$codeinsees = [];
//$codeinsees = ['971'];
//$codeinsees = ['29'];
  
// Génération de la carte topologique
foreach($adminexp->c_g2_pol->find([]) as $mpol) {
  $mpol = json_decode(json_encode($mpol), true);
  if ($codeinsees and !in_array(substr($mpol['_id'],0,strlen($codeinsees[0])), $codeinsees)) continue;
  new Feature($mpol['_id'], $mpol['polygons']);
}
Lim::completeFi();
Lim::buildNodes();

function genpol(array $codeinsees, string $format, $adminexp) {
  //echo "Génération des polygones généralisés\n";
  $gendate = date(DateTime::ATOM);
  $encoder = FeatureEncoder::create($format, [
    'zoom'=> 6,
    'metadata'=> [
      'title'=> "Fichier des polygones généralisés des communes françaises",
      'creator'=> "Benoit DAVID - MTES/CGDD/DRI/MIG",
      'description'=> [
        "Données dérivées d'AdminExpress (voir source) générées le $gendate. Version béta.",
        "Simplification des limites entre communes par l'algo de Douglas & Peucker avec un seuil de 0.01 degré.",
        "Suppression des limites inférieures à 0.02 degrés et de certaines petites îles.",
        "Chaque commune est décrite par au moins 1 polygone de surface non nulle.",
        "Certains polygones peuvent être dégénérés par l'algorithme de simplification.",
      ],
      'date'=> "Fichier généré le $gendate",
      'format'=> 'mime: image/svg+xml',
      'source'=> [
        'href'=> 'http://professionnels.ign.fr/adminexpress',
        'text'=> "AdminExpress du 15/11/2017",
      ],
      'coverage'=> [
        'href'=> 'http://www.geonames.org/countries/FR/france.html',
        'text'=> "France",
      ],
      'rights'=> [
        'href'=> 'https://www.etalab.gouv.fr/licence-ouverte-open-licence',
        'text'=> "Licence ouverte Etalab"
      ],
    ],
  ]);
  foreach($adminexp->c_g2_pol->find([]) as $mpol) {
    $mpol = json_decode(json_encode($mpol), true);
    if ($codeinsees and !in_array(substr($mpol['_id'],0,strlen($codeinsees[0])), $codeinsees)) continue;
    try {
      $encoder->feature([
        'type'=> 'Feature',
        'properties'=> ['insee'=>$mpol['_id']],
        'geometry'=> mpolGeom($mpol['_id'], $mpol['polygons'], $adminexp->c_g2_lim),
      ]);
    }
    catch (Exception $e) {
      $stderr = fopen('php://stderr', 'w');
      fprintf($stderr,"Erreur sur %s, skipped\n", $mpol['_id']);
    }
  }
  $encoder->end();
}

if ($geo=='nat')
  genpol([], $format, $adminexp);
else
  genpol($regions[$geo], $format, $adminexp);
  
