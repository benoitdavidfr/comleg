<?php
/*PhpDoc:
name:  polygon.inc.php
title: polygon.inc.php - définition d'un polygone
includes: [ geometry.inc.php ]
functions:
classes:
journal: |
  21/10/2017:
  - première version
*/
require_once __DIR__.'/geometry.inc.php';
/*PhpDoc: classes
name:  Polygon
title: Class Polygon extends Geometry - Définition d'un Polygon
methods:
doc: |
  protected $geom; // Pour un Polygon: [LineString]
*/
class Polygon extends Geometry {
  static $verbose = 0;

  /*PhpDoc: methods
  name:  lineStrings
  title: function lineStrings() - retourne la liste des LineStrings composant le polygone
  */
  function lineStrings() { return $this->geom; }
    
  /*PhpDoc: methods
  name:  __construct
  title: __construct($param) - construction à partir d'un WKT ou d'un [LineString] ou d'un [[[num,num]]]
  */
  function __construct($param) {
    // echo "Polygon::__construct(param=$param)\n";
    if (self::$verbose)
      echo "Polygon::__construct()\n";
    if (is_array($param)) {
      $this->geom = [];
      foreach ($param as $ls) {
        if (is_object($ls) and (get_class($ls)=='LineString'))
          $this->geom[] = $ls;
        elseif (is_array($ls))
          $this->geom[] = new LineString($ls);
        else
          throw new Exception("Parametre non reconnu dans Polygon::__construct()");
      }
      return;
    }
    elseif (is_string($param) and preg_match('!^(POLYGON\s*)?\(\(!', $param)) {
      $this->geom = [];
      $pattern = '!^(POLYGON\s*)?\(\(\s*([-\d.e]+)\s+([-\d.e]+)(\s+([-\d.e]+))?\s*,?!';
      while (1) {
        // echo "boucle de Polygon::__construct sur param=$param\n";
        $pointlist = [];
        while (preg_match($pattern, $param, $matches)) {
          // echo "matches="; print_r($matches);
          if (isset($matches[5]))
            $pointlist[] = new Point([$matches[2], $matches[3], $matches[5]]);
          else
            $pointlist[] = new Point([$matches[2], $matches[3]]);
          $param = preg_replace($pattern, '((', $param, 1);
        }
        if ($param=='(())') {
          $this->geom[] = new LineString($pointlist);
          return;
        } elseif (preg_match('!^\(\(\),\(!', $param)) {
          $this->geom[] = new LineString($pointlist);
          $param = preg_replace('!^\(\(\),\(!', '((', $param, 1);
        } else
          throw new Exception("Erreur dans Polygon::__construct(), Reste param=$param");
      }
    } else
      throw new Exception("Parametre non reconnu dans Polygon::__construct($param)");
  }
  

  static function test_new() {
    $pol = new Polygon('POLYGON((1 0,0 1,-1 0,0 -1,1 0))');
    echo "pol=$pol\n";
    echo "WKT:",$pol->wkt(),"\n";
    echo "GeoJSON:",json_encode($pol->geojson()),"\n";
  }
  
  /*PhpDoc: methods
  name:  addHole
  title: function addHole(LineString $hole) - aoute un trou au polygone
  */
  function addHole(LineString $hole) { $this->geom[] = $hole; }
  
  /*PhpDoc: methods
  name:  chgCoordSys
  title: function chgCoordSys($src, $dest) - créée un nouveau Polygon en changeant le syst. de coord. de $src en $dest
  */
  function chgCoordSys($src, $dest) {
    $lls = [];
    foreach ($this->geom as $ls)
      $lls[] = $ls->chgCoordSys($src, $dest);
    return new Polygon($lls);
  }
    
  /*PhpDoc: methods
  name:  __toString
  title: function __toString() - affiche la liste des LineString entourée par des ()
  */
  function __toString() { return '('.implode(',',$this->geom).')'; }
  
  /*PhpDoc: methods
  name:  toString
  title: function toString($nbdigits=null) - affiche la liste des LineString entourée par des () en précisant évent. le nbre de chiffres significatifs
  */
  function toString(int $nbdigits=null) {
    $str = '';
    foreach ($this->geom as $ls)
      $str .= ($str?',':'').$ls->toString($nbdigits);
    return '('.$str.')';
  }
  
  /*PhpDoc: methods
  name:  bbox
  title: function bbox() - calcul du rectangle englobant
  */
  function bbox():BBox { return $this->geom[0]->bbox(); }
  
  /*PhpDoc: methods
  name:  filter
  title: function filter($nbdigits) - filtre la géométrie en supprimant les points intermédiaires successifs identiques
  */
  function filter(int $nbdigits):Polygon {
    $result = [];
    foreach ($this->geom as $ls) {
      $filtered = $ls->filter($nbdigits);
      $result[] = $filtered;
    }
    return new Polygon($result);
  }
  
  function isDegenerated(): bool { return (count($this->geom[0]) < 4); }
  
  /*PhpDoc: methods
  name:  coordinates
  title: function coordinates() - renvoie les coordonnées come [ [ [ num ] ] ]
  */
  function coordinates():array {
    $coordinates = [];
    foreach ($this->geom as $ls)
      $coordinates[] = $ls->coordinates();
    return $coordinates;
  }
  
  /*PhpDoc: methods
  name:  draw
  title: function draw($drawing, $stroke='black', $fill='transparent', $stroke_with=2) - dessine
  */
  function draw($drawing, $stroke='black', $fill='transparent', $stroke_with=2) {
    return $drawing->drawPolygon($this->geom, $stroke, $fill, $stroke_with);
  }
  
  /*PhpDoc: methods
  name:  area
  title: function area($options=[]) - renvoie la surface dans le système de coordonnées courant
  doc: |
    Par défaut, l'extérieur et les intérieurs tournent dans des sens différents.
    La surface est positive si l'extérieur tourne dans le sens trigonométrique, <0 sinon.
    Si l'option 'noDirection' vaut true alors les sens ne sont pas pris en compte
  */
  function area(array $options=[]):float {
    $noDirection = (isset($options['noDirection']) and ($options['noDirection']));
    foreach ($this->geom as $ring)
      if (!isset($area))
        $area = ($noDirection ? abs($ring->area()) : $ring->area());
      else
        $area += ($noDirection ? -abs($ring->area()) : $ring->area());
    return $area;
  }
  static function test_area() {
    foreach ([
      'POLYGON((0 0,1 0,0 1,0 0))' => "triangle unité",
      'POLYGON((0 0,1 0,1 1,0 1,0 0))'=>"carré unité",
      'POLYGON((0 0,10 0,10 10,0 10,0 0))'=>"carré 10",
      'POLYGON((0 0,10 0,10 10,0 10,0 0),(2 2,2 8,8 8,8 2,2 2))'=>"carré troué bien orienté",
      'POLYGON((0 0,0 10,10 10,10 0,0 0),(2 2,2 8,8 8,8 2,2 2))'=>"carré troué mal orienté",
    ] as $polstr=>$title) {
      echo "<h3>$title</h3>";
      $pol = new Polygon($polstr);
      echo "area($pol)=",$pol->area();
      echo ", noDirection->",$pol->area(['noDirection'=>true]),"\n";
    }
  }
  
  /*PhpDoc: methods
  name:  pointInPolygon
  title: pointInPolygon(Point $pt) - teste si un point pt est dans le polygone
  */
  function pointInPolygon(Point $pt):bool {
    $c = false;
    foreach ($this->geom as $ring)
      if ($ring->pointInPolygon($pt))
        $c = !$c;
    return $c;
  }
  static function test_pointInPolygon() {
    $p0 = new Point('POINT(0 0)');
    foreach ([
      'POLYGON((1 0,0 1,-1 0,0 -1))',
      'POLYGON((1 1,-1 1,-1 -1,1 -1))',
      'POLYGON((1 1,-1 1,-1 -1,1 -1,1 1))',
      'POLYGON((1 1,2 1,2 2,1 2))',
    ] as $polstr) {
      $pol = new Polygon($polstr);
      echo "${pol}->pointInPolygon(($p0))=",($pol->pointInPolygon($p0)?'true':'false'),"\n";
    }
  }
};


if (basename(__FILE__)<>basename($_SERVER['PHP_SELF'])) return;
echo "<html><head><meta charset='UTF-8'><title>polygon</title></head><body><pre>";
require_once __DIR__.'/inc.php';

if (!isset($_GET['test'])) {
  echo <<<EOT
</pre>
<h2>Test de la classe Polygon</h2>
<ul>
  <li><a href='?test=test_new'>test_new</a>
  <li><a href='?test=test_pointInPolygon'>test_pointInPolygon</a>
  <li><a href='?test=test_area'>test_area</a>
</ul>\n
EOT;
  die();
}
else {
  $test = $_GET['test'];
  Polygon::$test();
}

