<?php
/*PhpDoc:
name: fencoderinsvg.inc.php
title: fencoderinsvg.inc.php - encodage des features en SVG
doc: |
  4 méthodes sont définies:
  - une méthode à appeller au début en passant en paramètre le zoom courant qui définit le nbre de chiffres à utiliser
  - une méthode par feature
  - une méthode de fin
  Je choisis comme système de coord utilisateur:
    - (xmin,ymax) -> (0,0)
    - (x,y) -> ((x-xmin)*100, (ymax-y)*100 / cos(Lat))
journal: |
  27/11/2017:
    détection des polygones sans surface et utilisation alors d'une résolution supérieure
  26/11/2017:
    passage en objets pour permettre d'avoir un code appellant générique
  23-24/11/2017:
    adaptation à SVG
  14/11/2017:
    transformation en classe
  2/11/2017:
    création
*/
require_once __DIR__.'/fencoder.inc.php';

class FeatureEncoderInSvg extends FeatureEncoder {
  private $xmin = -5.25;
  private $xmax = 9.57;
  private $ymin = 41.36;
  private $ymax = 51.10;
  private $coslat; // le cosinus de la latitude moyenne
  
  function __construct(array $headers=[]) {
    $this->coslat = cos(($this->ymin + $this->ymax)/2 / 180.0 * pi());
    echo '<?xml version="1.0" encoding="UTF-8"?>',"\n";
    printf ("<svg width='100%%' height='100%%' viewBox='0 0 %d %d' %s\n",
            ($this->xmax - $this->xmin)*100, round(($this->ymax - $this->ymin) / $this->coslat * 100),
            "xml:lang='fr' xmlns='http://www.w3.org/2000/svg' xmlns:xlink='http://www.w3.org/1999/xlink'>");
    if (isset($headers['metadata'])) {
      if (isset($headers['metadata']['description'])) {
        $br = sprintf('X: %.2f -> %.2f, Y: %.2f -> %.2f', $this->xmin, $this->xmax, $this->ymin, $this->ymax);
        $headers['metadata']['description'] = array_merge($headers['metadata']['description'], [
          "Restriction aux communes de métropole.",
          "Transformation en coord. entières en centièmes de degrés dans la boite $br",
          "puis division des latitudes par le cosinus de la latitude moyenne.",
        ]);
      }
       echo "<metadata xmlns:dc='http://purl.org/dc/elements/1.1/'>\n";
      foreach ($headers['metadata'] as $name => $value) {
        if (is_string($value))
          echo "  <dc:$name>$value</dc:$name>\n";
        elseif (is_array($value) and !isset($value['href'])) {
          echo "  <dc:$name>\n";
          foreach ($value as $item)
            echo "    ",str_replace('&', '&amp;', $item),"\n";
          echo "  </dc:$name>\n";
        }
        elseif (is_array($value) and isset($value['href'])) {
          echo "  <dc:$name xlink:type='simple' xlink:href='$value[href]'>$value[text]</dc:$name>\n";
        }
      }
      echo "</metadata>\n";
    }
  }
  
  // nb chiffres après la virgule
  static function nbdigits(float $number) {
    $pos = strpos($number, '.');
    if ($pos === FALSE) {
      //echo "number=$number, nbdigits=0\n";
      return 0;
    }
    $nbdigits = strlen($number)-$pos-1;
    //echo "number=$number, pos=$pos, nbdigits=$nbdigits\n";
    return $nbdigits;
  }
  
  // transforme les coord géo en coord utilisateur SVG sous la forme d'une string
  function chcoord(float $x, float $y): string {
    $nbdigits = self::nbdigits($x);
    $nbdy = self::nbdigits($y);
    if ($nbdy > $nbdigits)
      $nbdigits = $nbdy;
    if ($nbdigits < 2)
      $nbdigits = 2;
    
    if (($x < $this->xmin) or ($x > $this->xmax) or ($y < $this->ymin) or ($y > $this->ymax))
      return '';
    if ($nbdigits == 2)
      return round(($x - $this->xmin) * 100).','.round(($this->ymax - $y) / $this->coslat * 100);
    else {
      $fmt = sprintf('%%.%df,%%.%df', $nbdigits-2, $nbdigits-2);
      return sprintf($fmt, 
                     round(($x - $this->xmin) * 100, $nbdigits-2),
                     round(($this->ymax - $y) / $this->coslat * 100, $nbdigits-2));
    }
  }
  
  // la commune 50481 nécessite plus de digits
  function polygon(string $insee, array $coordinates) {
    $str = '';
    $ptprec = '';
    $nbpts = 0;
    foreach ($coordinates[0] as $pt) {
      if (!($pt = $this->chcoord($pt[0], $pt[1]))) return;
      if ($pt <> $ptprec) {
        $str .= ($str?' ':'').$pt;
        $ptprec = $pt;
        $nbpts++;
      }
    }
    echo "<polygon id='$insee' points='$str'/>\n";
  }
  
  function feature(array $feature) {
    if ($feature['geometry']['type']=='Polygon')
      $this->polygon($feature['properties']['insee'], $feature['geometry']['coordinates']);
    elseif ($feature['geometry']['type']=='MultiPolygon') {
      foreach ($feature['geometry']['coordinates'] as $polygon)
        $this->polygon($feature['properties']['insee'], $polygon);
    }
    else
      throw new Exception("type ".$feature['geometry']['type']." non prévu");
  }
  
  function end() {
    echo "</svg>\n";
  }
  
  function errorMessage(string $message) {
    echo "$message\n";
  }
};


if (basename(__FILE__)<>basename($_SERVER['PHP_SELF'])) return;

foreach([7, 123, 7.1, 7.0, 7.45, 7.40, 7.678, 7.670] as $number)
  echo "nbdigits($number)=",FeatureEncoderInSvg::nbdigits($number),"\n";