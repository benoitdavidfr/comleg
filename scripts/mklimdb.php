<?php
/*PhpDoc:
name: makelimdb.php
title: makelimdb.php - fabrication des limites avec stockage en BD
tables:
  {layer}_lim
    title: {layer}_lim - collection générique de limites - {layer} ::= ( r:region | d:departement | c:commune )
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
  Fabrique les limites des régions, départements et communes à partir des fichiers SHP
  Fonctionne en 3 phases:
  1) génération des fichiers GeoJSON à partir des fichiers SHP
  2) fabrication des segments correspondants aux limites des polygones et stockage dans MongoDB
  3) structuration de ces segments sous la forme de limites ayant un code à droite, éventuellement un à gauche
    et une géométrie, et stockage du résultat dans MongoDB dans une collection {layer}_lim
  Pour commune suppression des communes de Paris, Lyon et Marseille redondantes avec leurs arrondissements.
  L'empreinte mémoire est normalement faible car les résultats intermédiaires sont stockés dans MongoDB
journal: |
  2/12/2017:
    chgt de nom des collections
  18/11/2017:
    recopie des limites dans une nouvelle collection pour avoir des id plus courts
  7/11/2017:
    ajout de mongodbclient.inc.php pour rendre le script indépendant de l'URI MongoDB
  2/11/2017:
    ajout d'un filtre sur la géométrie pour éviter 2 points sucessifs identiques
      points successifs identiques dans filterLineString()
        pour key=50129, 83069, 97613, 97605, 97610, 97603, 97612, 97609, 97606, 97602
  1/11/2017:
    amélioration de la gestion des métadonnées
    définition d'une cmde all qui fait tout
    PHP Fatal error:  Uncaught MongoDB\Driver\Exception\RuntimeException: Can't extract geo keys:
      { _id: "K50129:50129:-1.583479810365 49.669539241328,-1.583479810365 49.669539241328",
        type: "Feature", 
        properties: { right: "50129", left: "50129" }, 
        bbox: [ -1.583479810364681, 49.66953924132848, -1.583479810364681, 49.66953924132848 ], 
        geometry: { 
          type: "LineString", 
          coordinates: [
            [ -1.583479810364681, 49.66953924132848 ], 
            [ -1.583479810364681, 49.66953924132848 ]
          ]
        }
      } 
     GeoJSON LineString must have at least 2 vertices: [ [ -1.583479810364681, 49.66953924132848 ], [ -1.5
     in /Users/benoit/Sites/admingeo/vendor/mongodb/mongodb/src/Operation/CreateIndexes.php:153

  30/10/2017:
    première version opérationnelle
*/
ini_set('memory_limit', '1280M');
require_once __DIR__.'/filtergeom.inc.php';
require_once __DIR__.'/mongodbclient.inc.php';
require_once __DIR__.'/../geometry/inc.php';

$meta = [
  '_id'=> implode(' ',$argv),
  'info'=> "génération des limites, l'_id indique la couche et la phase",
  'loaddate'=>date(DateTime::ATOM)
];

$adminexp = mongoDbClient()->adminexp;

// Décompose les objets en un ensemble de segments
class Seg {
  static $collection=null; // collection MongoDB stockant les segments
  static $pointFormat = "%.12f %.12f"; // format utilisé pour clé dans Seg, un double a 15 chiffres significatifs
  
  // construit les segments à partir d'un feature
  static function buildFromFeature(array $feature, string $key) {
    if ($feature['geometry']['type'] == 'Polygon')
      self::buildFromPolygon($feature['geometry']['coordinates'], $key);
    elseif ($feature['geometry']['type'] == 'MultiPolygon') {
      foreach ($feature['geometry']['coordinates'] as $polygon)
        self::buildFromPolygon($polygon, $key);
    }
  }

  // construit les segments à partir d'un polygone
  static function buildFromPolygon(array $polygon, string $key) {
    foreach ($polygon as $ring) {
      $pt0 = array_shift($ring);
      $label0 = sprintf(self::$pointFormat, $pt0[0], $pt0[1]);
      foreach ($ring as $pt1) {
        $label1 = sprintf(self::$pointFormat, $pt1[0], $pt1[1]);
        try {
          self::$collection->insertOne([
            '_id'=>"G$label0,$label1",
            'key'=>$key,
          ]);
        } catch (Exception $e) {
          echo "Erreur ",$e->getMessage()," sur key $key\n";
        }
        $pt0 = $pt1;
        $label0 = $label1;
      }
    }
  }

  static function show() {
    echo "segs = \n";
    foreach (self::$collection->find([]) as $seg) {
      echo '  ',$seg->_id,',',$seg->key,"\n";
    }
  }
  
  // retourne l'étiquette à gauche du segment ou null
  static function left(array $pt0, array $pt1) {
    $label0 = sprintf(self::$pointFormat, $pt0[0], $pt0[1]);
    $label1 = sprintf(self::$pointFormat, $pt1[0], $pt1[1]);
    $seg = self::$collection->findOne(['_id'=>"G$label1,$label0"]); 
    if ($seg)
      return $seg->key;
    else
      return null;
  }
};

// Une fois les segments construits, on rebalaie chaque feature pour créer des limites homogènes
class Feature {
  static function add(array $feature, string $key) {
    if ($feature['geometry']['type'] == 'Polygon')
      self::addPolygon($feature['geometry']['coordinates'], $key);
    elseif ($feature['geometry']['type'] == 'MultiPolygon') {
      foreach ($feature['geometry']['coordinates'] as $polygon)
        self::addPolygon($polygon, $key);
    }
  }
  
  // génère les limites correspondant à un polygone
  static function addPolygon(array $polygon, string $key) {
    foreach ($polygon as $ring) {
      $edges = [];
      while((count($ring)>1)) {
        $pt0 = array_shift($ring);
        $pt1 = $ring[0];
        $left0 = Seg::left($pt0, $pt1);
        $edge = new Edge([$pt0,$pt1], $key, $left0);
        $ring = $edge->aggregate($ring);
        $edges[] = $edge;
      }
      
      // en fonction de la position du premier point la dernière et la première limites peuvent être combinées
      if (count($edges)>1) {
        $lastEdge = $edges[count($edges)-1];
        if ($newEdge = $lastEdge->combine($edges[0])) {
          $edges[0] = $newEdge;
          unset($edges[count($edges)-1]);
        }
      }
      foreach ($edges as $edge)
        $edge->store();
    }
  }
};

// Classe des limites
// Sait se construire et se stocker dans MongoDB
class Edge {
  static $collection=null; // collection temporaire MongoDB
  // format utilisé pour clé dans Lim, un double a 15 chiffres significatifs
  const BBOXFORMAT = "%.12f %.12f,%.12f %.12f";
  private $geom=[]; // [point]
  private $right=null; // étiquette à droite
  private $left=null; // étiquette à gauche
  
  // nouvelle limite à partir du premier segment
  function __construct(array $geom, $right, $left) {
    $this->geom = $geom;
    $this->right = $right;
    $this->left = $left;
  }
  
  // agrège à la limite créée les segments suivants à condition qu'ils aient même zone à gauche
  function aggregate(array $ring) {
    while((count($ring)>1)) {
      if (Seg::left($ring[0],$ring[1])==$this->left) {
        array_shift($ring);
        $this->geom[] = $ring[0];
      }
      else
        return $ring;
    }
    return $ring;
  }
  
  // si les 2 limites ont mêmes côtés et se suivent géométriquement alors concaténation, sinon retourne null
  function combine(Edge $edge1) {
    if (($this->right <> $edge1->right) or ($this->left <> $edge1->left))
      return null;
    $lastPt = $this->geom[count($this->geom)-1];
    $firstPt = $edge1->geom[0];
    if (($lastPt[0]<>$firstPt[0]) or ($lastPt[0]<>$firstPt[0]))
      return null;
    $geom = $edge1->geom;
    array_shift($geom);
    $geom = array_merge($this->geom, $geom);
    return new Edge($geom, $this->right, $this->left);
  }
  
  // enregistre une limite dans la MongoDB dans la collection en variable de classe
  // Utilise la clé pour tester si la limite existe déjà dans l'autre sens
  function store() {
    $id = 'K'.$this->right;
    $idinv = null;
    $properties = [
      'right'=> $this->right,
    ];
    if ($this->left) {
      $properties['left'] = $this->left;
      $id .= ':'.$this->left;
      $idinv = 'K'.$this->left.':'.$this->right;
    }
    $linestring = new LineString($this->geom);
    $bbox = $linestring->bbox()->asArray();
    $bboxstr = sprintf(self::BBOXFORMAT, $bbox[0], $bbox[1], $bbox[2], $bbox[3]);
    $id .= ':'.$bboxstr;
    if ($idinv) {
      $idinv .= ':'.$bboxstr;
      if (self::$collection->findOne(['_id'=>$idinv]))
        return;
    }
    self::$collection->insertOne([
      '_id'=> $id,
      'properties'=> $properties,
      'bbox'=> $bbox,
      'geometry'=>[
        'type'=>'LineString',
        'coordinates'=>$this->geom,
      ],
    ]);
  }
  
  // le format de l'identifiant de limite est de la forme 'K':{right}:{left}?(:{no})?
  static function store2($collection, $lim) {
    $lim = json_decode(json_encode($lim), true);
    //echo "lim="; print_r($lim);
    $oldid = $lim['_id'];
    $id = 'K'.$lim['properties']['right'].':';
    if (isset($lim['properties']['left']))
      $id .= $lim['properties']['left'];
    $lim['_id'] = $id;
    for ($i=1; $i<1000; $i++) {
      try {
        $collection->insertOne($lim);
        return;
      }
      catch (Exception $e) {
        $lim['_id'] = $id.':'.$i;
      }
    }
    throw new Exception("copyToCollection() impossible pour $oldid");
  }
  
  // recopie dans une autre collection en changeant l'id et suppression de la collection temporaire
  static function copyToNewCollection($collection2) {
    //echo "Edge::copyToNewCollection()\n";
    $collection2->drop();
    foreach (self::$collection->find([]) as $lim) {
      self::store2($collection2, $lim);
    }
    self::$collection->drop();
  }
};

// liste des fichiers à charger
$base = [
  // Le répertoire racine
  'root' => __DIR__.'/../ADMIN-EXPRESS'
            //.'/ADMIN-EXPRESS_1-1__SHP__FRA_2017-10-16/ADMIN-EXPRESS/1_DONNEES_LIVRAISON_2017-10-16',
            .'/ADMIN-EXPRESS_1-1__SHP__FRA_2017-11-15/ADMIN-EXPRESS/1_DONNEES_LIVRAISON_2017-11-15',
  'paths' => [ // chemin du répertoire dans root en fonction métropole / DOM
    'M' => '/ADE_1-1_SHP_LAMB93_FR',
    'D971' => '/ADE_1-1_SHP_UTM20W84GUAD_D971',
    'D972' => '/ADE_1-1_SHP_UTM20W84MART_D972',
    'D973' => '/ADE_1-1_SHP_UTM22RGFG95_D973',
    'D974' => '/ADE_1-1_SHP_RGR92UTM40S_D974',
    'D976' => '/ADE_1-1_SHP_RGM04UTM38S_D976',
  ],
  'layers' => [ // liste [collection => [ shp, keyname ]] 
    'r' => ['shp'=> '/REGION.shp', 'keyname'=> 'INSEE_REG'],
    'd' => ['shp'=> '/DEPARTEMENT.shp', 'keyname'=> 'INSEE_DEP'],
    'c' => ['shp'=> '/COMMUNE.shp', 'keyname'=> 'INSEE_COM'],
  ],
];

// all permet de générer les cmdes à piper avec sh
if (($argc==2) and ($argv[1]=='all')) {
  foreach(['r','d','c'] as $layer)
    foreach(['ogr','mkseg','mklim','cleanseg'] as $phase) {
      $cmde = "php $argv[0] $layer $phase\n";
      echo "echo $cmde$cmde";
    }
  die();
}
if ($argc <= 2) {
  echo "php $argv[0] {r|d|c} ogr\n";
  echo "php $argv[0] {r|d|c} mkseg\n";
  echo "php $argv[0] {r|d|c} mklim\n";
  echo "php $argv[0] {r|d|c} cleanseg\n";
  echo "php $argv[0] all | sh\n";
  die();
}
$layer = $argv[1];
$cmde = $argv[2];

if (!isset($base['layers'][$layer])) {
  die("Couche $layer inconnue\n");
}
$shp = $base['layers'][$layer]['shp'];
$keyname = $base['layers'][$layer]['keyname'];

// génération du fichier GeoJSON par ogr2ogr
if ($cmde=='ogr') {
  foreach ($base['paths'] as $code => $path) {
    $path = $base['root'].$path.$shp;
    if (!is_file($path)) {
      echo "couche $shp absente pour $code<br>\n";
      continue;
    }
    $bname = substr($path, 0, strrpos($path,'.'));
    if (is_file("$bname.json")) {
      unlink("$bname.json");
    }
    $command = "/usr/bin/ogr2ogr -f GeoJSON -t_srs EPSG:4326 $bname.json $path";
    //echo "command=$command<br>\n\n";
    $string = exec($command, $output, $return_var);
    if ($return_var<>0) {
      print_r($output);
      die("Erreur sur commande $command");
    }
  }
  $adminexp->meta->replaceOne(['_id'=>$meta['_id']], $meta, ['upsert'=>true]);
  die("Fin $cmde sur $layer\n");
}

// création de la collection des segments
$segcollname = $layer.'_seg';
Seg::$collection = $adminexp->$segcollname;
if ($cmde=='mkseg') {
  Seg::$collection->drop();
  foreach ($base['paths'] as $code => $path) {
    $path = $base['root'].$path.$shp;
    $bname = substr($path, 0, strrpos($path,'.'));
    $fp = fopen("$bname.json",'r');
    if (!$fp)
      throw new Exception("Erreur d'ouverture de $bname.json");
    while (($line = fgets($fp)) !== FALSE) {
      if (strncmp($line,'{ "type": "Feature",', 20)<>0)
        continue;
      $line = rtrim($line);
      $len = strlen($line);
      if (substr($line,$len-1,1)==',')
        $line = substr($line,0,$len-1);
      //echo "line=$line\n";
      $feature = json_decode($line, true);
      if (!$feature)
        throw new Exception("Erreur '".self::json_message_error(json_last_error())
                            ."' dans json_encode() sur: $line");
      $key = $feature['properties'][$keyname];
      if (in_array($key,['13055','69123','75056'])) {
        echo "$key skipped\n";
        continue;
      }
      $feature['geometry'] = filterGeometry($feature['geometry'], $key);
      Seg::buildFromFeature($feature, $key);
    }
    fclose($fp);
  }
  $adminexp->meta->replaceOne(['_id'=>$meta['_id']], $meta, ['upsert'=>true]);
  die("Fin $cmde sur $layer\n");
}

// suppression de la collection des segments
if ($cmde=='cleanseg') {
  Seg::$collection->drop();
  die("Fin $cmde sur $layer\n");
}

// définition de la collection temporaire des limites
$edgeCollName = $layer.'_limtmp';
Edge::$collection = $adminexp->$edgeCollName;
if ($cmde=='mklim') {
  Edge::$collection->drop();
  $indexname = Edge::$collection->createIndex(['geometry'=> '2dsphere'],['name'=> 'geometry']);
  echo "Création de l'index $indexname sur $layer lim\n";
  foreach ($base['paths'] as $code => $path) {
    $path = $base['root'].$path.$shp;
    $bname = substr($path, 0, strrpos($path,'.'));
    $fp = fopen("$bname.json",'r');
    if (!$fp)
      throw new Exception("Erreur d'ouverture de $bname.json");
    while (($line = fgets($fp)) !== FALSE) {
      if (strncmp($line,'{ "type": "Feature",', 20)<>0)
        continue;
      $line = rtrim($line);
      $len = strlen($line);
      if (substr($line,$len-1,1)==',')
        $line = substr($line,0,$len-1);
      //echo "line=$line\n";
      $feature = json_decode($line, true);
      if (!$feature)
        throw new Exception("Erreur '".self::json_message_error(json_last_error())
                            ."' dans json_encode() sur: $line");
      $key = $feature['properties'][$keyname];
      if (in_array($key,['13055','69123','75056'])) {
        echo "$key skipped\n";
        continue;
      }
      $feature['geometry'] = filterGeometry($feature['geometry'], $key);
      Feature::add($feature, $key);
    }
    fclose($fp);
  }
  $edgeCollName = $layer.'_lim';
  Edge::copyToNewCollection($adminexp->$edgeCollName);
  $indexname = $adminexp->$edgeCollName->createIndex(['geometry'=> '2dsphere'],['name'=> 'geometry']);
  $adminexp->meta->replaceOne(['_id'=>$meta['_id']], $meta, ['upsert'=>true]);
  die("Fin $cmde sur $layer\n");
}
