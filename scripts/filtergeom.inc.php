<?php
/*PhpDoc:
name: filtergeom.inc.php
title: filtergeom.inc.php - filtre des points successifs identiques
doc : |
  Fonction de filtre de points identiques dans les LineString, MultiLineString, Polygon et MultiPolygon
  S'applique sur des structures Php correspondant à une géométrie GeoJSON
journal: |
  2/11/2017:
    création
*/
// supprime les points successifs identiques
function filterLineString(array $geom, string $key) {
  $newgeom = [];
  $ptprec = array_shift($geom);
  $newgeom[] = $ptprec;
  foreach ($geom as $pt) {
    if (($pt[0]==$ptprec[0]) and ($pt[1]==$ptprec[1])) {
      echo "Alerte points successifs identiques pour key=$key dans filterLineString()\n";
    }
    else {
      $newgeom[] = $pt;
      $ptprec = $pt;
    }
  }
  return $newgeom;
}

// supprime les points successifs identiques
// $geom est une géométrie GeoJSON
// génère une exception si la géométrie est incorrecte
function filterGeometry(array $geom, string $key) {
  if (!isset($geom['type']))
    throw new Exception("type non défini");
  if ($geom['type']=='MultiPolygon') {
    $mpolCoords = [];
    foreach ($geom['coordinates'] as $polygonGeom) {
      $polCoords = [];
      foreach ($polygonGeom as $ring) {
        $ring = filterLineString($ring, $key);
        if (count($ring) < 4)
          throw new Exception("Erreur: pour key=$key dans filterGeometry() nbre de points d'un Polygon < 4");
        $polCoords[] = $ring;
      }
      $mpolCoords[] = $polCoords;
    }
    $geom['coordinates'] = $mpolCoords;
  }  
  elseif ($geom['type']=='Polygon') {
    $polCoords = [];
    foreach ($geom['coordinates'] as $ring) {
      $ring = filterLineString($ring, $key);
      if (count($ring) < 4)
        throw new Exception("Erreur: pour key=$key dans filterGeometry() nbre de points d'un Polygon < 4");
      $polCoords[] = $ring;
    }
    $geom['coordinates'] = $polCoords;
  }
  elseif ($geom['type']=='MultiLineString') {
    $mlsCoords = [];
    foreach ($geom['coordinates'] as $ls) {
      $ls = filterLineString($ls, $key);
      if (count($ls) < 2)
        throw new Exception("Erreur: pour key=$key dans filterGeometry() nbre de points d'un LineString < 2");
      $mlsCoords[] = $ls;
    }
    $geom['coordinates'] = $mlsCoords;
  }
  elseif ($geom['type']=='LineString') {
    $geom['coordinates'] = filterLineString($geom['coordinates'], $key);
    if (count($geom['coordinates']) < 2)
      throw new Exception("Erreur: pour key=$key dans filterGeometry() nbre de points d'un LineString < 2");
  }
  return $geom;
}
