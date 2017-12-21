<?php
/*PhpDoc:
name:  geometry.inc.php
title: geometry.inc.php - définition abstraite d'une géométrie WKT/GeoJSON, remplace geom2d
functions:
classes:
doc: |
  7 types de géométries sont définis:
  - 3 types géométriques élémentaires: Point, LineString et Polygon
  - 3 types de collections homogènes de géométries élémentaires: MultiPoint, MultiLineString et MultiPolygon
  - 1 type de collection hétérogène de géométries élémentaires: GeometryCollection
  La classe Geometry est une une sur-classe abstraite de ces 7 types.
  Elle porte:
  - 2 méthodes de construction d'objet à partir respectivement d'un WKT ou d'un GeoJSON
  - la méthode générique geojson qui génère une représentation GeoJSON comme Array Php en appellant la méthode 
    coordinates() ; cette méthode est surchargée pour GeometryCollection
  - la méthode wkt() qui frabrique une représentations WKT
  - la méthode bbox() qui fabrique le BBox
  La classe Geometry permet de gérer a minima une géométrie sans avoir à connaitre son type.
journal: |
  17/12/2017
    chgt de logique sur le nbre de digits des coordonnées
  21/10/2017:
  - première version
*/
/*PhpDoc: classes
name:  Geometry
title: abstract class Geometry - Sur-classe abstraite des classes Point, LineString, Polygon, MultiGeom et GeometryCollection
methods:
doc: |
  Porte en variable de classe le paramètre precision qui définit le nombre de chiffres après la virgule à restituer en WKT par défaut.
  S'il est négatif, il indique le nbre de 0 à afficher comme derniers chiffres.
*/
abstract class Geometry {
  static $primitives = ['Point','LineString','Polygon','MultiPoint','MultiLineString','MultiPolygon',
                        'GeometryCollection'];
  protected $geom; // La structure de la propriété dépend de la sous-classe
  
  /*PhpDoc: methods
  name:  fromWkt
  title: "static function fromWkt(string $wkt, int $nbdigits=null): Geometry - crée une géométrie à partir d'un WKT"
  doc: |
    génère une erreur si le WKT ne correspond pas à une géométrie
  */
  static function fromWkt(string $wkt, int $nbdigits=null): Geometry {
    foreach (self::$primitives as $primitive) {
      if (strncmp($wkt,strtoupper($primitive),strlen($primitive))==0)
        return new $primitive($wkt, $nbdigits);
    }
    throw new Exception("Parametre non reconnu dans Geometry::fromWkt()");  
  }
  
  /*PhpDoc: methods
  name:  fromGeoJSON
  title: "static function fromGeoJSON(array $geometry, int $nbdigits=null): Geometry - crée une géométrie à partir d'une géométrie GeoJSON"
  doc: |
    génère une erreur si le paramètre ne correspond pas à une géométrie GeoJSON
  */
  static function fromGeoJSON(array $geometry, int $nbdigits=null): Geometry {
    if (!is_array($geometry) or !isset($geometry['type']) or !in_array($geometry['type'], self::$primitives)) {
      echo "geometry ="; var_dump($geometry);
      throw new Exception("Le paramètre de Geometry::fromGeoJSON() n'est pas une géométrie GeoJSON");
    }
    if (($geometry['type']=='GeometryCollection') and isset($geometry['geometries'])) {
      $coll = [];
      foreach ($geometry['geometries'] as $geometry)
        $coll[] = self::fromGeoJSON($geometry);
      return new GeometryCollection($coll, $nbdigits);
    }
    elseif (isset($geometry['coordinates'])) {
      return new $geometry['type']($geometry['coordinates'], $nbdigits);
    }
    else {
      echo "geometry ="; var_dump($geometry);
      throw new Exception("Le paramètre de Geometry::fromGeoJSON() n'est pas une géométrie GeoJSON");
    }
  }
  
  /*PhpDoc: methods
  name:  value
  title: function value()
  */
  function value() { return $this->geom; }
  
  /*PhpDoc: methods
  name:  geojson
  title: "function geojson(): array - retourne un tableau Php qui encodé en JSON correspondra à la geometry GeoJSON"
  */
  function geojson():array { return [ 'type'=>get_called_class(), 'coordinates'=>$this->coordinates() ]; }
  
  /*PhpDoc: methods
  name:  wkt
  title: "function wkt(): string - retourne la chaine WKT"
  */
  function wkt():string { return strtoupper(get_called_class()).$this; }

  /*PhpDoc: methods
  name:  bbox
  title: "function bbox(): BBox - calcule la bbox"
  */
  function bbox(): BBox {
    $bbox = new BBox;
    foreach ($this->geom as $geom)
      $bbox->union($geom->bbox());
    return $bbox;
  }
};

if (basename(__FILE__)<>basename($_SERVER['PHP_SELF'])) return;

require_once __DIR__.'/inc.php';

echo "<html><head><meta charset='UTF-8'><title>geometry</title></head><body><pre>\n";

$geojsons = [
  [ 'type'=>'Point',
    'coordinates'=>[5, 4],
  ],
  [ 'type'=>'LineString',
    'coordinates'=>[[5, 4],[15, 14]],
  ],
  [ 'type'=>'Polygon',
    'coordinates'=>[
      [[5, 4],[15, 14],[15, 18],[5, 4]],
      [[7, 8],[10, 12],[9, 8],[5, 4]],
    ],
  ],
  [ 'type'=>'MultiPoint',
    'coordinates'=>[[5, 4],[15, 14]],
  ],
  [ 'type'=>'MultiLineString',
    'coordinates'=>[
      [[5, 4],[15, 14],[15, 18],[5, 4]],
      [[7, 8],[10, 12],[9, 8],[5, 4]],
    ],
  ],
  [ 'type'=>'MultiPolygon',
    'coordinates'=>[
      [
        [[5, 4],[15, 14],[15, 18],[5, 4]],
        [[7, 8],[10, 12],[9, 8],[5, 4]],
      ]
    ],
  ],
  [ 'type'=>'GeometryCollection',
    'geometries'=>[
      [ 'type'=>'Point',
        'coordinates'=>[5, 4],
      ],
      [ 'type'=>'LineString',
        'coordinates'=>[[5, 4],[15, 14]],
      ],
      [ 'type'=>'Polygon',
        'coordinates'=>[
          [[5, 4],[15, 14],[15, 18],[5, 4]],
          [[7, 8],[10, 12],[9, 8],[5, 4]],
        ],
      ],
    ],
  ],
];
if (0) { // Test Geometry::fromGeoJSON(); et Geometry::fromWkt()
  foreach ($geojsons as $geojson) {
    $gc = Geometry::fromGeoJSON($geojson);
    echo "wkt=",$gc->wkt(),"\n";
    $gc2 = Geometry::fromWkt($gc->wkt());
    echo "GeoJSON=",json_encode($gc2->geojson()),"\n";
  }
}
elseif (1) {
  $nbdigits = 4;
  foreach ($geojsons as $geojson) {
    if ($geojson['type']=='Point')
      $geojson['coordinates'][0] += 1/3;
    elseif ($geojson['type']=='LineString')
      $geojson['coordinates'][0][0] += 1/3;
    elseif ($geojson['type']=='Polygon')
      $geojson['coordinates'][0][0][0] += 1/3;
    $gc = Geometry::fromGeoJSON($geojson);
    if (in_array($geojson['type'], ['Point','MultiPoint'])) 
      $gc = $gc->round($nbdigits);
    else
      $gc = $gc->filter($nbdigits);
    echo "GeoJSON=",json_encode($gc->geojson()),"\n";
  }
}

