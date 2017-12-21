<?php
/*PhpDoc:
name:  multipoint.inc.php
title: multipoint.inc.php - définition d'une collection de points
includes: [ geometry.inc.php ]
classes:
doc: |
journal: |
  22/10/2017:
    création
*/
require_once __DIR__.'/multigeom.inc.php';

/*PhpDoc: classes
name:  MultiPoint
title: class MultiPoint extends MultiGeom - Liste de points
methods:
*/
class MultiPoint extends MultiGeom {
  /*PhpDoc: methods
  name:  __construct
  title: function __construct($param) - initialise un MultiPoint à partir d'un WKT ou de [Point] ou de [[num,num]]
  */
  function __construct($param) {
    if (is_array($param)) {
      $this->geom = [];
      foreach ($param as $point) {
        if (is_object($point) and (get_class($point)=='Point'))
          $this->geom[] = $point;
        elseif (is_array($point))
          $this->geom[] = new Point($point);
        else
          throw new Exception("Parametre non reconnu dans MultiPoint::__construct()");
      }
      return;
    }
    elseif (is_string($param)) {
      $this->geom = [];
      $ptpattern = '[-0-9.e ]+';
      $pattern = "!^MULTIPOINT\s*\(($ptpattern),?!";
      while (preg_match($pattern, $param, $matches)) {
        $this->geom[] = new Point("POINT($matches[1])");
        $param = preg_replace($pattern, 'MULTIPOINT(', $param, 1);
      }
      if ($param<>'MULTIPOINT()')
        throw new Exception("Parametre non reconnu dans MultiPoint::__construct($param)");
    }
    else
      throw new Exception("Parametre non reconnu dans MultiPoint::__construct()");
  }
  
  static function test_new() {
    // Test de prise en compte d'un MULTIPOINT
    $multipoint = Geometry::fromWkt('MULTIPOINT (153042 6799129,153043 6799174,153063 6799199)');
    echo "multipoint=$multipoint\n";
    echo "wkt=",$multipoint->wkt(),"\n";

    foreach ([
      'MULTIPOINT(30 30,200 200)',
      //'MULTIPOINTxx(30 30,200 200)',
      [[10,10],[20,20]],
      [[10,10],[20,20,60]],
      //[[10,10],[20,20,60,80]],
      [new Point([0,0]), new Point([5,10])],
    ] as $param) {
      $mp = new MultiPoint($param);
      if (is_array($param)) {
        echo "new MultiPoint("; print_r($param); echo " -> $mp\n";
        //print_r($ls);
      }
      else
        echo "new MultiPoint($param) -> $mp\n";
      echo "wkt:",$mp->wkt(),"\n";
      echo "GeoJSON:",json_encode($mp->geojson()),"\n";
    }
  }
  
  /*PhpDoc: methods
  name:  wkt
  title: function wkt() - génère une chaine de caractère correspondant au WKT avec l'entete
  */
  function wkt():string { return 'MULTIPOINT'.$this; }
  
  /*PhpDoc: methods
  name:  round
  title: function round($nbdigits) - arrondit les points avec le nb de chiffres indiqués
  */
  function round(int $nbdigits): MultiPoint {
    $pts = [];
    foreach ($this->geom as $pt) {
      $pts[] = $pt->round($nbdigits);
    }
    return new MultiPoint($pts);
  }
};


if (basename(__FILE__)<>basename($_SERVER['PHP_SELF'])) return;
echo "<html><head><meta charset='UTF-8'><title>multipoint</title></head><body><pre>";
require_once __DIR__.'/inc.php';

if (!isset($_GET['test'])) {
  echo <<<EOT
</pre>
<h2>Test de la classe MultiPoint</h2>
<ul>
  <li><a href='?test=test_new'>test_new</a>
</ul>\n
EOT;
  die();
}
else {
  $test = $_GET['test'];
  MultiPoint::$test();
}

