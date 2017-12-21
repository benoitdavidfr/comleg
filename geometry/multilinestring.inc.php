<?php
/*PhpDoc:
name:  multilinestring.inc.php
title: multilinestring.inc.php - définition d'une collection de lignes
includes: [ geometry.inc.php ]
classes:
doc: |
journal: |
  22/10/2017:
    création
*/
require_once __DIR__.'/multigeom.inc.php';

/*PhpDoc: classes
name:  MultiLineString
title: class MultiLineString extends MultiGeom - Liste de lignes
methods:
*/
class MultiLineString extends MultiGeom {
  /*PhpDoc: methods
  name:  __construct
  title: function __construct($param) - initialise un MultiLineString à partir d'un WKT ou de [LineString] ou de [[[num,num]]]
  */
  function __construct($param) {
    if (is_array($param)) {
      $this->geom = [];
      foreach ($param as $ls) {
        if (is_object($ls) and (get_class($ls)=='LineString'))
          $this->geom[] = $ls;
        elseif (is_array($ls))
          $this->geom[] = new LineString($ls);
        else
          throw new Exception("Parametre non reconnu dans MultiLineString::__construct()");
      }
    }
    elseif (is_string($param)) {
      $this->geom = [];
      $lspattern = '\([-0-9.e ,]+\)';
      $pattern = "!^MULTILINESTRING\s*\(($lspattern),?!";
      while (preg_match($pattern, $param, $matches)) {
        $this->geom[] = new LineString("LINESTRING$matches[1]");
        $param = preg_replace($pattern, 'MULTILINESTRING(', $param, 1);
      }
      if ($param<>'MULTILINESTRING()')
        throw new Exception("Parametre '$param' non reconnu dans MultiLineString::__construct()");
    }
    else
      throw new Exception("Parametre non reconnu dans MultiLineString::__construct()");
  }

  static function test_new() {
    // Test de prise en compte d'un MULTILINESTRING
    $geomstr = <<<EOT
MULTILINESTRING ((153042 6799129,153043 6799174,153063 6799199),(154613 6803109.5,154568 6803119,154538.89999999999 6803145))
EOT;

    $mls = new MultiLineString($geomstr);
    echo "multilinestring=$mls\n";
    echo "wkt=",$mls->wkt(),"\n";
    echo "GeoJSON:",json_encode($mls->geojson()),"\n";
  }

  /*PhpDoc: methods
  name:  wkt
  title: function wkt() - génère une chaine de caractère correspondant au WKT avec l'entete
  */
  function wkt(int $nbdigits=null):string { return 'MULTILINESTRING'.$this; }
};


if (basename(__FILE__)<>basename($_SERVER['PHP_SELF'])) return;
echo "<html><head><meta charset='UTF-8'><title>multilinestring</title></head><body><pre>";
require_once __DIR__.'/inc.php';

if (!isset($_GET['test'])) {
  echo <<<EOT
</pre>
<h2>Test de la classe MultiLineString</h2>
<ul>
  <li><a href='?test=test_new'>test_new</a>
</ul>\n
EOT;
  die();
}
else {
  $test = $_GET['test'];
  MultiLineString::$test();
}

