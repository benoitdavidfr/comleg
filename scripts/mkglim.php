<?php
/*PhpDoc:
name: mkglim.php
title: mkglim.php - genéralisation des limites de communes
tables:
  c_g2_lim:
    title: c_g2_lim - collection des limites de communes au niveau de généralisation 2
    database: [adminexpress]
    columns:
      _id:
        title: _id - identifiant de limite de la forme 'K':{right}:{left}?(:{no})?
        doc: |
          où:
            - {right} est le code INSEE de l'objet droit
            - {left} est le code INSEE de l'objet gauche s'il y en a un
            - {no} est une numéro en séquence à partir de 1, pour le premier partie vide
      properties:
        title: properties - propriétés right et left des codes INSEE droite et évent. gauche
      bbox:
        title: bbox - boite composée de 4 coordonnées xmin, ymin, xmax, ymax
      geometry:
        title: geometry - geometry GeoJSON de type LineString
doc : |
  Généralisation des limites de communes à la résolution 1e-2 degrés
  Les limites sont nommées {x}_lim où {x} est r/region, d/departement ou c/commune
  Je nomme {x}_g{g}_lim la généralisation des limites au niveau de généralisation {g}
  Les coordonnées doivent être arrondies à la résolution.
  Rappel:
    1° @ 45°(Bdx) = 40 000 km/360 * cos(lat) = 79 km en longitude
    1° = 40 000 km/360 = 111 km en latitude
  L'algorithme simplifie les lignes avec l'algo de Douglas & Peucker en fonction du seuil de distance 1e-2 degrés
    1) une ligne non fermée ne doit pas être réduite à un point
    2) une ligne fermée ne doit pas être réduite à un point ou à un segment
  Lorsqu'une ligne est trop simplifiée, le niveau de résolution est augmenté.

  Seules sont traitées les limites utilisées dans c_g2_pol et non affectées à un macro-noeud.
  Dans un second temps, on vériofie que chaque anneau correspond à un polygone ayant une surface
  Si ce n'est pas le cas la résolution est augmentée pour chaque limite de l'anneau.
  La collection c_g2_lim est ainsi constituée.

journal: |
  15/12/2017:
    modif du test sur id de brin pour intégrer dans c_g2_lim
    les brins uniquement présents par l'inverse sont pris en compte
  13/12/2017:
    gestion des ereurs par réception d'exception
    génération totale OK sauf erreur sur commune 06163
    Erreur: limite K29273:29239:1 non trouvée dans c_g2_lim pour 29239
    ring: -K29273:29239,K29239:,-K29273:29239:1,K29239::1,K29239:29259
    Erreur limite K29273:29239:1 non trouvée dans c_g2_lim sur checkRing sur 29239, skipped
    Erreur: limite K06162:06163:1 non trouvée dans c_g2_lim pour 06163
    ring: -K06162:06163,K06163:06062,K06163:06132,K06163:06013,K06163:,-K06162:06163:1,K06163::1
    Erreur limite K06162:06163:1 non trouvée dans c_g2_lim sur checkRing sur 06163, skipped

    dans simplif.php:
      effacement de la face (non ile) 1 dans l'objet 06162
      Face::deleteNonIsland()@06162:1
      Blade::deleteNonIsland()@K06162::1

  12/12/2017:
    PHP Fatal error:  Uncaught Exception: limite K57554:57147 non trouvée dans c_g2_lim in /var/www/html/admingeo/adminexpress/geomofring.inc.php:53

  11/12/2017:
    Erreur lors du traitement de l'ensemble de la France:
      PHP Fatal error:  Uncaught Exception: Erreur dans Blade::fiInv boucle 
  9-10/12/2017:
    première version
*/
require_once __DIR__.'/mongodbclient.inc.php';
require_once __DIR__.'/topomap.inc.php';
require_once __DIR__.'/geomofring.inc.php';
require_once __DIR__.'/../geometry/inc.php';

$glevel0 = 2; // Niveau de généralisation souhaité exprimé en nbre de chiffres significatifs en degrés 
    
$adminexp = mongoDbClient()->adminexp;

class Mkglim {
  static $limGlevels = []; // niveau de chaque limite
  
  // insert la limite limid dans c_g2_lim en simplifiant la géométrie de c_lim au niveau glevel
  // vérifie que la limite n'est pas réduite à un point, si c'est le cas glevel est augmenté
  static function simplifyAndInsert(string $limid, int $glevel, $c_lim, $c_g2_lim): void {
    //echo "Mkglim::simplifyAndInsert(limid=$limid)\n";
    $lim = $c_lim->findOne(['_id'=>$limid]);
    $lim = json_decode(json_encode($lim), true);
    if (!isset($lim['properties']['left']))
      $glevel++;
    $linestring = new LineString($lim['geometry']['coordinates']);
    $linestring = $linestring->simplify(5 * 10 ** - $glevel);
    while (!$linestring) {
      $glevel++;
      echo "linestring vide pour $limid, tentative avec une résolution augmentée glevel=$glevel\n";
      $linestring = new LineString($lim['geometry']['coordinates']);
      $linestring = $linestring->simplify(5 * 10 ** - $glevel);
      if (!$linestring and ($glevel > 5)) {
        echo "linestring vide pour $limid après tentative avec une résolution glevel=$glevel\n";
        die("FIN ligne ".__LINE__."\n");
      }
    }
    $linestring = $linestring->filter($glevel);
    while ($linestring->isClosed() and (count($linestring->coordinates()) < 4)) {
      if ($glevel >= 5) {
        echo "Pour $limid, linestring fermée de moins de 4 points pour glevel=$glevel, abandon\n";
        die("FIN ligne ".__LINE__."\n");
      }
      echo "Pour $limid, linestring fermée de moins de 4 points pour glevel=$glevel\n";
      $glevel++;
      $linestring = new LineString($lim['geometry']['coordinates']);
      $linestring = $linestring->simplify(5 * 10 ** - $glevel);
      $linestring = $linestring->filter($glevel);
    }
    $lim['geometry'] = $linestring->geojson();
    $lim['bbox'] = $linestring->bbox()->asArray();
    $c_g2_lim->insertOne($lim);
    self::$limGlevels[$limid] = $glevel;
  }
  
  // met à jour la limite limid dans c_g2_lim en simplifiant la géométrie de c_lim au niveau glevel
  // si glevel est moins élevé que celui déjà enregistré alors l'opération n'est pas effectuée
  static function simplifyAndUpdate(string $limid, int $glevel, $c_lim, $c_g2_lim): void {
    if (self::$limGlevels[$limid] >= $glevel)
      return;
    echo "simplifyAndUpdate(limid=$limid, glevel=$glevel)\n";
    $lim = $c_lim->findOne(['_id'=>$limid]);
    $lim = json_decode(json_encode($lim), true);
    $linestring = new LineString($lim['geometry']['coordinates']);
    $linestring = $linestring->simplify(5 * 10 ** - $glevel);
    $linestring = $linestring->filter($glevel);
    $lim['geometry'] = $linestring->geojson();
    $lim['bbox'] = $linestring->bbox()->asArray();
    $c_g2_lim->replaceOne(['_id' => $limid], $lim);
    self::$limGlevels[$limid] = $glevel;
  }
};


// Vérifie que l'anneau a une surface supérieure à 10 ** (- 2 * $glevel - 1)
// et que ses segments ne s'intersectenrt pas
function checkRing(string $fid, array $ring, int $glevel, bool $isHole, $c_g2_lim): bool {
  $distTreshold = 10 ** - $glevel; // seuil utilisé pour l'algo D&P
  $areaTreshold = 10 ** (- $glevel * 2 - 1);
  $geom = geomOfRing($fid, $ring, $c_g2_lim);
  // l'orientation des anneaux conduit à des surfaces négatives pour les extérieurs et positives pour les trous
  $area = ($isHole ? +1 : -1 ) * $geom->area();
  if ($area < $areaTreshold) {
    if (!$isHole)
      echo "commune $fid:\n";
    else
      echo "trou dans commune $fid:\n";
    echo "  ring: ",implode(',',$ring),"\n";
    echo "  geom: $geom\n";
    echo "  area: $area\n";
    echo "  ALERTE: surface < 10 ** ",- $glevel * 2 - 1,"\n";
    return false;
  }

  // test d'intersection entre 2 segments de l'anneau
  $coords = $geom->points();
  for ($i = 0; $i < count($coords)-2; $i++) {
    $seg1 = [$coords[$i], $coords[$i+1]];
    for ($j = $i+2; $j < count($coords)-1; $j++) {
      if (($i==0) and ($j==count($coords)-2))
        continue;
      $seg2 = [$coords[$j], $coords[$j+1]];
      if (Point::interSegSeg($seg1, $seg2)) {
        if (!$isHole)
          echo "commune $fid:\n";
        else
          echo "trou dans commune $fid:\n";
        echo "  ALERTE: les segments $i et $j s'intersectent\n";
        return false;
      }
    }
  }
  return true;
}

// augmentation du niveau de résolution des limites d'un anneau
function modifyRing(string $fid, array $ring, int $glevel, $c_lim, $c_g2_lim): void {
  foreach ($ring as $bid) {
    $limId = (substr($bid,0,1)=='-') ? substr($bid,1) : $bid;
    if (!MacroNode::macroNodeOfLimId($limId))
      Mkglim::simplifyAndUpdate($limId, $glevel, $c_lim, $c_g2_lim);
  }
}


$codeinsees = [];
//$codeinsees = ['2A','2B'];
//$codeinsees = ['971'];
//$codeinsees = ['29'];
//$codeinsees = ['06'];
//$codeinsees = ['2A'];

// Lit les macro-noeuds afin d'exclure ces limites de la collection
foreach($adminexp->c_g2_mnd->find([]) as $mnd) {
  $mnd = json_decode(json_encode($mnd), true);
  //echo "mnd="; print_r($mnd);
  new MacroNode($mnd);
}

// reconstruit la collection c_g2_lim:
// 1) à partir des limites utilisées par les communes simplifiées
// 2) en excluant les limites affectées à un macro-noeud
$adminexp->c_g2_lim->drop();
$insertedLims = []; // liste des id de limite (sous la forme [ limId => 1 ]) insérées dans c_g2_lim
foreach($adminexp->c_g2_pol->find([]) as $f) {
  $f = json_decode(json_encode($f), true);
  //print_r($f);
  if ($codeinsees and !in_array(substr($f['_id'],0,strlen($codeinsees[0])), $codeinsees)) continue;
  //echo "f="; print_r($f);
  foreach($f['polygons'] as $pol) {
    foreach($pol['rings'] as $ring) {
      foreach ($ring as $bid) {
        $limid = (substr($bid,0,1)=='-' ? substr($bid,1) : $bid);
        if (!isset($insertedLims[$limid]) and !MacroNode::macroNodeOfLimId($limid)) {
          Mkglim::simplifyAndInsert($limid, $glevel0, $adminexp->c_lim, $adminexp->c_g2_lim);
          $insertedLims[$limid] = 1;
        }
      }
    }
  }
}
unset($insertedLims);

// Construction de la carte topologique
foreach($adminexp->c_g2_pol->find([]) as $mpol) {
  $mpol = json_decode(json_encode($mpol), true);
  if ($codeinsees and !in_array(substr($mpol['_id'],0,strlen($codeinsees[0])), $codeinsees)) continue;
  new Feature($mpol['_id'], $mpol['polygons']);
}
Lim::completeFi();
Lim::buildNodes();

if ($argc > 1) {
  // affichage de geomOfRing() pour une commune
  if ($argv[1]=='showgeom') {
    if ($argc < 4)
      die("usage: php $argv[0] $argv[1] {insee} {glevel}\n");
    $glevel = $argv[3];
    $f = $adminexp->c_g2_pol->findOne(['_id'=>$argv[2]]);
    $f = json_decode(json_encode($f), true);
    echo "_id: $f[_id]\n";
    foreach($f['polygons'] as $pol) {
      foreach($pol['rings'] as $noring => $ring) {
        echo "rings:\n  -\n";
        //modifyRing($f['_id'], $ring, $glevel, $adminexp->c_lim, $adminexp->c_g2_lim);
        if (checkRing($f['_id'], $ring, $glevel, ($noring<>0), $adminexp->c_g2_lim))
          echo "    checkRing: true\n";
        else
          echo "    checkRing: false\n";
        $geomOfRing = geomOfRing($f['_id'], $ring, $adminexp->c_g2_lim);
        //print_r($geomOfRing);
        echo "    geom: $geomOfRing\n";
      }
    }
  }
  die("Fin ligne ".__LINE__."\n");
}

// Vérification des polygones
foreach($adminexp->c_g2_pol->find([]) as $f) {
  $f = json_decode(json_encode($f), true);
  //print_r($f);
  if ($codeinsees and !in_array(substr($f['_id'],0,strlen($codeinsees[0])), $codeinsees)) continue;
  //echo "f="; print_r($f);
  foreach($f['polygons'] as $pol) {
    foreach($pol['rings'] as $noring => $ring) {
      try {
        $glevel = $glevel0;
        while (!checkRing($f['_id'], $ring, $glevel, ($noring<>0), $adminexp->c_g2_lim)) {
          if (++$glevel > 5) {
            echo "ERREUR glevel > 5 pour $f[_id]\n";
            break;
          }
          modifyRing($f['_id'], $ring, $glevel, $adminexp->c_lim, $adminexp->c_g2_lim);
       }
      } catch (Exception $e) {
        $stderr = fopen('php://stderr', 'w');
        fprintf($stderr,"Erreur %s sur checkRing sur %s, skipped\n", $e->getMessage(), $f['_id']);
      }
    }
  }
}