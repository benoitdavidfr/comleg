<?php
/*PhpDoc:
name:  geomcoll.inc.php
title: geomcoll.inc.php - définition d'une collection hétérogène de géométries élémentaires
includes: [ geometry.inc.php ]
classes:
doc: |
journal: |
  22/10/2017:
    création
*/
require_once __DIR__.'/geometry.inc.php';

/*PhpDoc: classes
name:  GeometryCollection
title: Class GeometryCollection - Liste d'objets élémentaires
methods:
doc: |
  Un objet GeomCollection peut être composé de géométries élémentaires de différents types
*/
class GeometryCollection extends Geometry {
  
  /*PhpDoc: methods
  name:  __construct
  title: function __construct($geomstr) - initialise un GeomCollection à partir d'un WKT ou d'une liste de Geometry
  */
  function __construct($param) {
    $this->geom = [];
    if (is_array($param)) {
      foreach ($param as $elt) {
        if (is_object($elt) and in_array(get_class($elt), ['Point','LineString','Polygon']))
          $this->geom[] = $elt;
        else {
          echo "elt ="; var_dump($elt);
          throw new Exception("Elt du parametre non reconnu dans GeometryCollection::__construct()");
        }
      }
    }
    if (is_array($param)) {
      $this->geom = $param;
      return;
    }
    if (is_string($param)) {
      $ring = '\([-0-9. ,]+\),?';
      $pattern = "!^GEOMETRYCOLLECTION\((POLYGON\s*\(($ring)*\)|(LINESTRING|POINT)\s*\([-0-9.e ,]+\)),?!";
      while (preg_match($pattern, $param, $matches)) {
//        echo "matches="; print_r($matches);
        $this->geom[] = Geometry::fromWkt($matches[1]);
        $param = preg_replace($pattern, 'GEOMETRYCOLLECTION(', $param, 1);
      }
      if ($param<>'GEOMETRYCOLLECTION()')
        throw new Exception("Parametre '$param' non reconnu dans GeometryCollection::__construct()");
    }
  }
  
  // Test de prise en compte d'un MULTIPOLYGON
  static function test_new() {
    // Test de prise en compte d'un GEOMTRYCOLLECTION
    $geomstr = <<<EOT
GEOMETRYCOLLECTION(POLYGON((153042 6799129,153043 6799174,153063 6799199),(1 1,2 2)),POLYGON((154613 6803109.5,154568 6803119,154538.89999999999 6803145)),LINESTRING(153042 6799129,153043 6799174,153063 6799199),LINESTRING(154613 6803109.5,154568 6803119,154538.89999999999 6803145),POINT(153042 6799129),POINT(153043 6799174),POINT(153063 6799199))
EOT;

    $geomcoll = new GeometryCollection($geomstr);
    echo "geomcoll=$geomcoll\n";
    echo "wkt=",$geomcoll->wkt(),"\n";
    echo "GeoJSON:",json_encode($geomcoll->geojson()),"\n";
    //echo "GeoJSON:",json_encode($geomcoll->geojson(),JSON_PRETTY_PRINT),"\n";

    $geomcoll = new GeometryCollection([
        new Polygon('POLYGON((153042 6799129,153043 6799174,153063 6799199))'),
        new Point('POINT(153063 6799199)'),
    ]);
    echo "wkt=",$geomcoll->wkt(),"\n";
    echo "GeoJSON:",json_encode($geomcoll->geojson()),"\n";

    $gc2 = Geometry::fromGeoJSON($geomcoll->geojson());
  }
  
  /*PhpDoc: methods
  name:  filter
  title: function filter($nbdigits) - filtre la géométrie en supprimant les points intermédiaires successifs identiques
  */
  function filter($nbdigits) {
    $called_class = get_called_class();
    $collection = [];
    foreach ($this->geom as $geom) {
//      echo "geom=$geom<br>\n";
      $filtered = $geom->filter($nbdigits);
//      echo "filtered=$filtered<br>\n";
      $collection[] = $filtered;
    }
    return new $called_class($collection);
  }
  
  /*PhpDoc: methods
  name:  __toString
  title: function __toString() - génère une chaine de caractère correspondant au WKT sans l'entete
  */
  function __toString() {
    $str = '';
    foreach($this->geom as $geom)
      $str .= ($str?',':'').$geom->wkt();
    return '('.$str.')';
  }
  
  /*PhpDoc: methods
  name:  chgCoordSys
  title: function chgCoordSys($src, $dest) - créée un nouveau GeomCollection en changeant le syst. de coord. de $src en $dest
  */
  function chgCoordSys($src, $dest) {
    $called_class = get_called_class();
//    echo "get_called_class=",get_called_class(),"<br>\n";
    $collection = [];
    foreach($this->collection as $geom)
      $collection[] = $geom->chgCoordSys($src, $dest);
    return new $called_class($collection);
  }
    
  /*PhpDoc: methods
  name:  wkt
  title: function wkt() - génère une chaine de caractère correspondant au WKT avec l'entete
  */
  function wkt(int $nbdigits=null):string { return 'GEOMETRYCOLLECTION'.$this; }
  
  /*PhpDoc: methods
  name:  geojsonGeometry
  title: function geojsonGeometry() - retourne un tableau Php qui encodé en JSON correspondra à la geometry GeoJSON
  */
  function geojson():array {
    $geometries = [];
    foreach ($this->geom as $geom)
      $geometries[] = $geom->geojson();
    return [
      'type'=>get_called_class(),
      'geometries'=>$geometries,
    ];
  }
  
  /*PhpDoc: methods
  name:  draw
  title: function draw() - itère l'appel de draw sur chaque élément
  */
  function draw($drawing, $stroke='black', $fill='transparent', $stroke_with=2) {
    foreach($this->collection as $geom)
      $geom->draw($drawing, $stroke, $fill, $stroke_with);
  }
};


if (basename(__FILE__)<>basename($_SERVER['PHP_SELF'])) return;
echo "<html><head><meta charset='UTF-8'><title>geomcoll</title></head><body><pre>";

require_once __DIR__.'/inc.php';

if (!isset($_GET['test'])) {
  echo <<<EOT
</pre>
<h2>Test de la classe GeometryCollection</h2>
<ul>
  <li><a href='?test=test_new'>test_new</a>
</ul>\n
EOT;
  die();
}
else {
  $test = $_GET['test'];
  GeometryCollection::$test();
}

