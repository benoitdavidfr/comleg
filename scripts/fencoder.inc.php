<?php
/*PhpDoc:
name: fencoder.inc.php
title: fencoder.inc.php - définition de l'encodage en GeoJSON d'un feature
classes:
doc: |
  Le code est organisé avec une classe générique et une classe par format.
  La classe générique 

  3 méthodes sont définies:
  - une méthode à appeller au début en passant en paramètre le zoom courant qui définit le nbre de chiffres à utiliser
  - une méthode par feature
  - une méthode de fin
journal: |
  18/12/2017:
    suppression de l'utilisation de nbdigits dans FeatureEncoderInGeoJSON::feature()
    Les coordonnées doivent être convenablement arrondies avant l'affichage
  26/11/2017:
    passage en objets pour permettre d'avoir un code appellant générique
  14/11/2017:
    transformation en classe
  2/11/2017:
    création
*/
require_once __DIR__.'/fencoderinsvg.inc.php';

/*PhpDoc: classes
name: class FeatureEncode
title: class FeatureEncode - classe générique
doc: |
  La classe générique définit la méthode statique create() qui créée soit un objet en fonction du format
  4 méthodes sont définies:
  - une méthode à appeller au début en passant le format et un tableau de paramètres
  - une méthode par feature
  - une méthode de fin
  - une méthode d'erreur
*/
abstract class FeatureEncoder {
  static function create(string $format, array $headers=[]) {
    if ($format=='geojson')
      return new FeatureEncoderInGeoJSON($headers);
    else
      return new FeatureEncoderInSvg($headers);
  }
  abstract function __construct(array $headers=[]);
  abstract function feature(array $feature);
  abstract function end();
  abstract function errorMessage(string $message);
};

/*PhpDoc: classes
name: class FeatureEncoderInGeoJSON
title: class FeatureEncoderInGeoJSON - classe d'encodage des feature en GeoJSON
doc: |
  Pour minimiser la taille des données transmises, le nombre de chiffres des coordonnées est limité en fonction du zoom.
  4 méthodes sont définies:
  - une méthode à appeller au début en passant en paramètre le zoom courant qui définit le nbre de chiffres à utiliser
  - une méthode par feature
  - une méthode de fin
  - une méthode d'affichage d'un message d'erreur
*/
class FeatureEncoderInGeoJSON extends FeatureEncoder {
  private $nbdigits;
  private $first;
  
  function __construct(array $headers=[]) {
    $zoom = $headers['zoom'];
    unset($headers['zoom']);
    // Definition de nbdigits qui est le nbre de chiffres après la virgule pour les coordonnées en degrés
    if ($zoom <= 3)
      $this->nbdigits = 1;
    elseif ($zoom <= 6)
      $this->nbdigits = 2;
    elseif ($zoom <= 10)
      $this->nbdigits = 3;
    elseif ($zoom <= 13)
      $this->nbdigits = 4;
    elseif ($zoom <= 16)
      $this->nbdigits = 5;
    elseif ($zoom <= 20)
      $this->nbdigits = 6;
    else
      $this->nbdigits = 7;
    $this->first = true;
    echo '{ "type":"FeatureCollection",',"\n";
    if ($headers) {
      if (in_array('nbdigits',array_keys($headers)))
        $headers['nbdigits'] = $this->nbdigits;
      echo '  "headers":',json_encode($headers),",\n";
    }
    echo '  "features": [',"\n";
  }
  
  function polygon(string $ptfmt, array $coordinates): string {
    $str = '';
    foreach ($coordinates as $ls) {
      $strls = '';
      foreach ($ls as $pt) {
        $strls .= ($strls?',':'').sprintf($ptfmt,$pt[0],$pt[1]);
      }
      $str .= ($str?',':'').'['.$strls.']';
    }
    return $str;
  }
    
  function feature(array $feature): void {
    if ($this->first)
      $this->first = false;
    else
      echo ",\n";
    echo '    ',json_encode($feature);
  }
  
  function end(): void {
    echo "\n  ]\n}\n";
  }
  
  function errorMessage(string $message): void {
    echo "  ],\n",
         '  "errorMessage": "',$message,'"',"\n",
         "}\n";
  }
};

