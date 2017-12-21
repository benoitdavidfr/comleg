<?php
/*PhpDoc:
name:  multigeom.inc.php
title: multigeom.inc.php - définition d'une collection de géométries élémentaires
includes: [ geometry.inc.php ]
classes:
doc: |
journal: |
  22/10/2017:
    création
*/
require_once __DIR__.'/geometry.inc.php';

/*PhpDoc: classes
name:  MultiGeom
title: abstract class MultiGeom extends Geometry - Liste de géométries élémentaires homogènes
methods:
*/
abstract class MultiGeom extends Geometry {
  /*PhpDoc: methods
  name:  __toString
  title: function __toString() - génère une chaine de caractère correspondant au WKT sans l'entete
  */
  function __toString() {
    $str = '';
    foreach($this->geom as $elt)
      $str .= ($str?',':'').$elt;
    return '('.$str.')';
  }
  
  /*PhpDoc: methods
  name:  coordinates
  title: function coordinates() - renvoie un tableau de coordonnées
  */
  function coordinates() {
    $coordinates = [];
    foreach ($this->geom as $elt)
      $coordinates[] = $elt->coordinates();
    return $coordinates;
  }
  
  /*PhpDoc: methods
  name:  filter
  title: function filter(int $nbdigits) - renvoie une nouvelle collection dont chaque élément est filtré supprimant les points successifs identiques
  doc: |
    Les coordonnées sont arrondies avec $nbdigits chiffres significatifs
    Un filtre sans arrondi n'a pas de sens.
  */
  function filter(int $nbdigits) {
    $coll = [];
    foreach ($this->geom as $elt) {
      $coll[] = $elt->filter($nbdigits);
    }
    $class = get_called_class();
    return new $class ($coll);
  }
};


if (basename(__FILE__)<>basename($_SERVER['PHP_SELF'])) return;
echo "<html><head><meta charset='UTF-8'><title>multigeom</title></head><body><pre>";
