<?php
/*PhpDoc:
name: simplif.php
title: simplif.php - simplification topologique des communes
includes: [ topomap.inc.php, mongodbclient.inc.php ]
tables:
  - name: c_g2__pol
    title: c_g2_pol - communes simplifiées à la résolution de 1km définies comme des polygones fondés sur les limites
    database: [adminexpress]
    columns:
      _id:
        title: _id - code INSEE
      polygons:
        title: "polygons - [ POLYGON ] / POLYGON ::= {area: float, rings: [ RING ]} / RING ::= [(LimId | '- '.LimId)]"
        doc: |
          liste de polygones, chacun défini par une surface et une liste de rings, chacun défini
          par une liste d'identifiants de limites ou de limites inverses
doc: |
  simplification topologique des communes.
  Lecture en entrée de la collection c_pol
  Construction de la structure de carte topologique
  Suppression:
    - des petites iles marines ou terrestres
    - des autres petites faces, cad des faces qui ne sont pas la face la plus grande
  Enregistrement du résultat dans la collection c_g2_pol
journal: |
  15/12/2017:
    ajout de Lim::completeFi() avant Feature::delAllSmallFaces() pour traiter le bug de la commune 06162
    ne marche pas:
      Erreur: limite K29273:29239:1 non trouvée dans c_g2_lim pour 29239
      ring: -K29273:29239,K29239:,-K29273:29239:1,K29239::1,K29239:29259
      Erreur sur 29239, skipped
      Erreur: limite K06162:06163:1 non trouvée dans c_g2_lim pour 06163
      ring: -K06162:06163,K06163:06062,K06163:06132,K06163:06013,K06163:,-K06162:06163:1,K06163::1
      Erreur sur 06163, skipped

  12/12/2017:
    effacement de la face (non ile) 1 dans l'objet 57554
    Face::deleteNonIsland()@57554:1
    Blade::deleteNonIsland()@-K57708:57554:1

  2-7/12/2017
    disjonction des classes de gestion de la carte topologique
  27/11-2/12/2017
    refonte en partant des limites et des polygones
*/
//ini_set('memory_limit', '5120M');
require_once __DIR__.'/topomap.inc.php';
require_once __DIR__.'/mongodbclient.inc.php';

$adminexp = mongoDbClient()->adminexp;

$codeinsees = [];
//$codeinsees = ['2A','2B'];
//$codeinsees = ['971'];
//$codeinsees = ['29'];
//$codeinsees = ['06'];
//$codeinsees = ['0','1','2','3','4','5','6'];
//$codeinsees = ['7','8','9'];
//$codeinsees = ['8'];


// Lecture des communes définies par leurs limites
$nbf = 0;
foreach($adminexp->c_pol->find([]) as $feature) {
  $feature = json_decode(json_encode($feature), true);
  if ($codeinsees and !in_array(substr($feature['_id'],0,strlen($codeinsees[0])), $codeinsees)) continue;
  //print_r($feature);
  new Feature($feature['_id'], $feature['polygons']);
  //echo "nbf=",++$nbf,"\n";
}
if (($argc==2) and ($argv[1]=='showall')) {
  Feature::showAll();
  die();
}
if (($argc==3) and ($argv[1]=='show')) {
  Feature::get($argv[2])->show();
  die();
}
if (($argc==2) and ($argv[1]=='check')) {
  MPol::checkAll();
  die();
}
Lim::completeFi();
Feature::delAllSmallFaces();
$adminexp->c_g2_pol->drop();
echo Feature::storeAll($adminexp->c_g2_pol)," objets enregistrés\n";

