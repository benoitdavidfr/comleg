<?php
/*PhpDoc:
name: mkpol.php
title: mkpol.php - fabrication des polygones à partir des limites
tables:
  - name: {layer}_pol
    title: {layer}_pol - collection générique pol - {layer} ::= ( region | departement | commune )
    database: [adminexpress]
    columns:
      _id:
        title: _id - code INSEE
      type:
        title: type - vaut Feature
      bbox:
        title: bbox - boite composée de 4 coordonnées xmin, ymin, xmax, ymax
      geometry:
        title: geometry - geometry GeoJSON de la boite donc de type Polygon
      polygons:
        title: "polygons - [ POLYGON ] / POLYGON ::= {area: float, rings: [ RING ]} / RING ::= [(LimId | '- '.LimId)]"
        doc: |
          liste de polygones, chacun défini par une surface et une liste de rings, chacun défini
          par une liste d'identifiants de limites ou de limites inverses
doc: |
  Ce script redéfinit les features initiaux à partir de leurs limites
  L'algorithme:
    - lit les limites dans MongoDB et les affecte à chaque feature limité
    - reconstruit les anneaux (ring) pour chaque objet
    - reconstruit chaque objet comme ensemble de polygones et le stocke dans MongoDB
  Chaque feature est soit un polygone, soit un ensemble de polygones.
  Chaque polygone est défini comme une liste de rings, le premier étant l'extérieur, les autres des trous.
  Chaque ring est une liste de limites ou de limites inverses.
  Le résultat est stocké dans une collection {layer}_pol où {layer} est region, departement ou commune

  Le script gère 3 cas particuliers:
    - il supprime la limite de communes K14756:14174 qui est un artefact généré par mklimdb.php du à un cas particulier
    - il supprime les limites de régions K44::1 et K44::2 dans la région 44 (Grand Est)
    - il supprime les limites de départements K51: et K51::1 dans la département 51

journal: |
  30/11/2017:
    correction d'un bug dans la structuration polygone <-> trou
  28/11/2017:
    ajout du calcul de surface par polygone, modification du schema de {layer}_pol
  21/11/2017:
    effectue une correction liée à un cas particulier entre les communes 14756 et 14174
  19/11/2017:
    effectue des corrections liées aux erreurs dans les données IGN
    voir erreurs_ign.yaml
  2/11/2017:
    2 communes en erreur dans buildRing: 14174, 14756
  1/11/2017:
    création
*/
ini_set('html_errors', false);
ini_set('memory_limit', '512M');

require_once __DIR__.'/mongodbclient.inc.php';
require_once __DIR__.'/../geometry/inc.php';

if ($argc <= 1) {
  echo "usage: php $argv[0] {r|d|c}\n";
  die();
}
$layerName = $argv[1];


//***
// Phase 0: initialisation des collections MongoDB et gestion des cas particuliers
//***
$adminexp = mongoDbClient()->adminexp;


// La collection en entrée à partir de laquelle les limtes sont lues
$limCollectionName = $layerName.'_lim';
Lim::$collection = $adminexp->$limCollectionName;

// Suppression d'un artefact généré par mklimdb.php du à un cas particulier
if ($layerName == 'c') {
  echo "Suppression de la limite de communes K14756:14174\n";
  Lim::$collection->deleteOne(['_id'=>'K14756:14174']);
}
// Suppression des limites dues à des erreurs IGN
elseif ($layerName == 'r') {
  echo "Correction d'erreurs sur les données IGN:\n";
  echo " - suppression des limites de région K44::1 et K44::2\n";
  Lim::$collection->deleteOne(['_id'=>'K44::1']);
  Lim::$collection->deleteOne(['_id'=>'K44::2']);
}
elseif ($layerName == 'd') {
  echo "Correction d'erreurs sur les données IGN:\n";
  echo " - suppression des limites du département K51: et K51::1\n";
  Lim::$collection->deleteOne(['_id'=>'K51:']);
  Lim::$collection->deleteOne(['_id'=>'K51::1']);
}

// La collection en sortie dans laquelle sont stockées les features
$mpolCollectionName = $layerName.'_pol';
MPol::$collection = $adminexp->$mpolCollectionName;
MPol::$collection->drop();


//***
// Phase 1: Lecture des limites et répartition par feature
//***

// Sur-classe de Lim et InvLim, porte la méthode followedBy()
class LimOrInv {
  // vrai ssi this est suivi par lim
  function followedBy(LimOrInv $lim) {
    $ret = (($this->ptn()[0]==$lim->pt0()[0]) and ($this->ptn()[1]==$lim->pt0()[1]));
    //echo "followedBy(this=$this, lim=$lim) -> ",($ret?'OK':'ko'),"\n";
    return $ret;
  }
};

// Limite correspondant à une LineString
class Lim extends LimOrInv {
  static $collection=null;
  private $id;
  private $properties; // [ k => v ]
  private $bbox; // Bbox
  private $pt0; // [num, num]
  private $ptn; // [ num, num]
  
  function __construct($id, $properties, $bbox, $pt0, $ptn) {
    $this->id = $id;
    $this->properties = $properties;
    $this->bbox = new Bbox(implode(',',$bbox));
    $this->pt0 = $pt0;
    $this->ptn = $ptn;
  }
  
  function __toString() { return $this->id; }
  
  function id() { return $this->id; }
  function pt0() { return $this->pt0; }
  function ptn() { return $this->ptn; }
  function bbox() { return $this->bbox; }
  
  function coordinates() {
    $lim = self::$collection->findOne(['_id'=>$this->id]);
    $lim = json_decode(json_encode($lim), true);
    return $lim['geometry']['coordinates'];
  }
};

// Limite prise dans le sens inverse
class InvLim extends LimOrInv {
  private $inv; // Lim, la limite inverse
  function __construct($lim) { $this->inv = $lim; }
  
  function __toString() { return '-'.$this->inv; }
  
  function id() { return '- '.$this->inv->id(); }
  function pt0() { return $this->inv->ptn(); }
  function ptn() { return $this->inv->pt0(); }
  function bbox() { return $this->inv->bbox(); }

  function coordinates() { return array_reverse($this->inv->coordinates()); }
};

// Boucle d'un Polygone constituée de liste de limites ou inverses
class Ring {
  private $lims; // [ LimOrInv ]
  function __construct(array $lims) { $this->lims = $lims ; }
  function lims() { return $this->lims; }
};

// Les tableaux contenus dans $mpols sont similaires aux MPol
// Il est plus simple de les gérer comme tableaux que comme objets pour leur construction
//$codeinsee = '02232';
$mpols = []; // [ key => [ 'lims'=>[ LimOrInv ], 'rings'=>[ Ring ] ]]
foreach(Lim::$collection->find([]) as $f) {
  $f = json_decode(json_encode($f), true);
  /*if (isset($codeinsee)) {
    $right = $f['properties']['right'];
    $left = (isset($f['properties']['left']) ? $f['properties']['left'] : null);
    if (($right<>$codeinsee) and ($left<>$codeinsee))
      continue;
  }*/
  $coord = $f['geometry']['coordinates'];
  $pt0 = $coord[0];
  $ptn = $coord[(count($coord)-1)];
  $lim = new Lim($f['_id'], $f['properties'], $f['bbox'], $pt0, $ptn);
  if (($pt0[0]==$ptn[0]) and ($pt0[1]==$ptn[1])) { // LineString fermée
    $mpols[$f['properties']['right']]['rings'][] = new Ring([$lim]);
    if (isset($f['properties']['left']))
      $mpols[$f['properties']['left']]['rings'][] = new Ring([new InvLim($lim)]);
  }
  else { // LineString ouverte
    $mpols[$f['properties']['right']]['lims'][] = $lim;
    if (isset($f['properties']['left']))
      $mpols[$f['properties']['left']]['lims'][] = new InvLim($lim);
  }
}

// affiche un tableau mpol, utilisé pour le déverminage
function show_mpol(string $key, array $mpol) {
  echo "$key:\n";
  if (isset($mpol['lims'])) {
    echo "  lims:\n";
    foreach ($mpol['lims'] as $lim)
      echo "   - $lim\n";
  }
  if (isset($mpol['rings'])) {
    echo "  rings:\n";
    foreach ($mpol['rings'] as $ring) {
      echo "   - :\n";
      foreach ($ring->lims() as $lim)
        echo "     - $lim\n";
    }
  }
}
//echo "argc=$argc\n"; die();
if (($argc==3) and ($argv[2]=='show_mpols')) {
  foreach ($mpols as $key => $mpol)
    show_mpol($key, $mpol);
  die("FIN ligne ".__LINE__."\n");
}
if (($argc==4) and ($argv[2]=='show_mpol')) {
  show_mpol($argv[3], $mpols[$argv[3]]);
  die("FIN ligne ".__LINE__."\n");
}


//***
// Phase 2 : Structuration du tableau créé en phase 1 en objets MPol et enregistrement de chaque objet MPol en base
//***
// représente un polygone
class Pol {
  private $rings; // [ [ LimOrInv ]] - le premier est l'extérieur, les suivants sont des trous
  private $bbox;  // Bbox
  
  function __construct(array $lims) {
    $this->rings = [ $lims ];
    $this->bbox = new Bbox;
    foreach($lims as $lim)
      $this->bbox->union($lim->bbox());
  }
  function rings() { return $this->rings; }
  function bbox() { return $this->bbox; }
  
  // [ [ [ x y ] ] ]
  function coordinates() {
    $polCoord = [];
    foreach($this->rings as $ring) {
      $ringCoord = [];
      foreach ($ring as $lim) {
        $ringCoord = array_merge($ringCoord, $lim->coordinates());
      }
      $polCoord[] = $ringCoord;
    }
    return $polCoord;
  }
  
  // teste si le polygone this est inclus dans le polygone pol
  function isIncludedIn(Pol $pol, string $key): bool {
    if (!$this->bbox->isIncludedIn($pol->bbox))
      return false;
    // un point de this
    $pt = $this->rings[0][0]->pt0();
    $pt = new Point($pt);
    // le polygone
    $polygon = new Polygon($pol->coordinates());
    $ret = $polygon->pointInPolygon($pt);
    if ($ret)
      echo "Trou détecté dans $key\n";
    return $ret;
  }
  
  function addHole(Pol $hole) {
    if (count($hole->rings)<>1)
      throw new Exception("Erreur d'ajout d'un trou");
    $this->rings[] = $hole->rings[0];
  }
  
  function area(): float {
    $geom = new Polygon($this->coordinates());
    return - $geom->area();
  }
  
  function show($indent) {
    foreach ($this->rings as $ring) {
      echo "${indent}  - ring:\n";
      foreach ($ring as $lim) {
        echo "${indent}      - $lim\n";
      }
    }
  }
};

class MPol {
  static $collection = null;
  private $key;
  private $lims;
  private $rings; // [ Ring ]
  private $pols; // [ Pol ]
  
  function __construct(string $key, array $mpol) {
    $this->key = $key;
    $this->lims = isset($mpol['lims']) ? $mpol['lims'] : [];
    $this->rings = isset($mpol['rings']) ? $mpol['rings'] : [];
  }
  
  // recherche de la limite suivante, renvoie le num dans lims ou -1
  function findFollowingLim($limprec) {
    foreach ($this->lims as $i => $lim) {
      if ($limprec->followedBy($lim)) {
        return $i;
      }
    }
    return -1;
  }
  
  // construit les boucles à partir des limites
  function buildRing() {
    //echo "buildRing on $this->key\n";
    if (!$this->lims)
      return false; // il n'y a plus de ring à construire
    $limprec = array_shift($this->lims);
    $ring = [ $limprec ];
    //echo "  demarrage sur $limprec\n";
    while (true) {
      $i = $this->findFollowingLim($limprec);
      if ($i == -1)
        break;
      $lim = $this->lims[$i];
      unset($this->lims[$i]);
      $ring[] = $lim;
      $limprec = $lim;
    }
    if ($limprec->followedBy($ring[0])) {
      //echo "  Construction OK\n";
      $this->rings[] = new Ring($ring);
      return true;
    }
    throw new Exception ("Erreur dans buildRing sur $this->key, limprec=$limprec");
  }
  
  function buildPolygons() {
    $pols = []; // [ [ [ LimOrInv ]]] - chaque polygone est une [ ring ] et chaque ring est une [ limite ]
    foreach($this->rings as $ring) {
      $pols[] = new Pol($ring->lims());
    }
    $this->rings = [];
    $n = count($pols);
    for($i=0; $i < $n-1; $i++) {
      for($j=$i+1; $j < $n; $j++) {
        if (!isset($pols[$j]))
          continue;
        if ($pols[$i]->isIncludedIn($pols[$j], $this->key)) {
          $pols[$j]->addHole($pols[$i]);
          unset($pols[$i]);
          continue 2; // passer au i suivant
        }
        elseif ($pols[$j]->isIncludedIn($pols[$i], $this->key)) {
          $pols[$i]->addHole($pols[$j]);
          unset($pols[$j]);
        }
      }
    }
    $this->pols = array_values($pols);
  }
  
  function show() {
    echo "$this->key:\n";
    if ($this->lims) {
      echo "  lims:\n";
      foreach ($this->lims as $lim)
        echo "   - $lim\n";
    }
    if ($this->rings) {
      echo "  rings:\n";
      foreach ($this->rings as $ring) {
        echo "   - :\n";
        foreach ($ring->lims() as $lim)
          echo "     - $lim\n";
      }
    }
    if ($this->pols) {
      foreach ($this->pols as $pol) {
        echo "  - pol:\n";
        $pol->show('    ');
      }
    }
  }
  
  // calcule le bbox en fonction des pols
  function bbox() {
    $bbox = new BBox;
    foreach ($this->pols as $pol) {
      $bbox->union($pol->bbox());
    }
    return $bbox;
  }
  
  // Chaque MPol est enregistré comme un feature dont la géométrie correspond au bbox
  function store() {
    $bbox = $this->bbox();
    $doc = [
      '_id'=> $this->key,
      'type'=>'Feature',
      'bbox'=> $bbox->asArray(),
      'geometry'=> $bbox->asPolygon()->geojson(),
      'polygons'=> [], // [ POLYGON ] / POLYGON ::= ['area'=>float, 'rings'=>[ RING ]] / RING ::= [ LimId | - LimId ]
    ];
    foreach ($this->pols as $pol) {
      $rings = []; // [ RING ]
      foreach ($pol->rings() as $ring) {
        $ringAsListOfId = []; // [ LimId | - LimId ]
        foreach ($ring as $lim) {
          $ringAsListOfId[] = $lim->id();
        }
        $rings[] = $ringAsListOfId;
      }
      $doc['polygons'][] = [
        'area'=> $pol->area(),
        'rings'=> $rings,
      ];
    }
    self::$collection->insertOne($doc);
  }
};

foreach ($mpols as $key => $mpol) {
  try {
    $mpol = new MPol($key, $mpol);
    while ($mpol->buildRing()) {
    }
    $mpol->buildPolygons();
    //$mpol->show();
    $mpol->store();
  } catch(Exception $e) {
    echo "Erreur sur l'objet $key : ",$e->getMessage(),"\n";
  }
  $mpol = null;
}

$indexname = MPol::$collection->createIndex(['geometry'=> '2dsphere'],['name'=> 'geometry']);
echo "Création de l'index $indexname sur $mpolCollectionName\n";
