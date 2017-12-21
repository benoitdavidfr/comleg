<?php
/*PhpDoc:
name:  coordsys.inc.php
title: coordsys.inc.php (v2) - changement simple de système de projection
classes:
functions:
doc: |
  Fonctions (long,lat) -> (x,y) et inverse
  Implémente les projections Lambert93, WebMercator et UTM uniquement sur l'ellipsoide IAG_GRS_1980
  Le Web Mercator est défini dans:
  http://earth-info.nga.mil/GandG/wgs84/web_mercator/(U)%20NGA_SIG_0011_1.0.0_WEBMERC.pdf
journal: |
  22/110/2017:
    intégration dans geometry
  14-15/12/2016
  - ajout de l'UTM
  - chgt de l'organisation des classes et de l'interface
  - passage en v2
  14/11/2016
  - correction d'un bug
  12/11/2016
  - ajout de wm2geo() et geo2wm()
  26/6/2016
  - ajout de chg pour améliorer l'indépendance de ce module avec geom2d.inc.php
  23/6/2016
  - première version
*/
/*PhpDoc: classes
name:  Class CoordSys
title: Class CoordSys - classe statique contenant les fonctions de proj et inverse
methods:
doc: |
  La classe est prévue pour gérer un nombre limité de codes, à priori:
  - 'geo' pour coordonnées géographiques en degrés décimaux
  - 'L93' pour Lambert 93
  - 'WM' pour web Mercator
  - UTM-ddX où dd est le numéro de zone et X est soit 'N', soit 'S'
  Les chgt de système peuvent être effectués au travers de la méthode statique chg() ou directement au travers des méthodes
  de changement.
  La classe porte les constantes définies pour l'elliposide IAG_GRS_1980 utilisée pour WGS84
  Dans les calculs, l'ellipsoide peut être changé à ce niveau.
  Cette possibilité est utilisée pour vérifier le code par rapport à l'exemple du rapport USGS fondé sur l'ellipsoide de Clarke1866
*/
class CoordSys {
  const a = 6378137.0; // Grand axe de l'ellipsoide IAG_GRS_1980 utilisée pour WGS84
  const aplat = 298.2572221010000; // 1/f: inverse de l'aplatissement = a / (a - b)
    
  static function e2() { return 1 - pow(1 - 1/self::aplat, 2); }
  static function e() { return sqrt(self::e2()); }
  
/*PhpDoc: methods
name:  detect
title: static function detect($opengiswkt) - detecte le système de coord exprimé en Well Known Text d'OpenGIS
doc: |
  Analyse le WKT OpenGis pour y détecter un des syst. de coord. gérés.
  Ecriture très partielle, MapInfo ci-dessous non traité.
  WKT issu de MapInfo:
  projcs=PROJCS["unnamed",
      GEOGCS["unnamed",
          DATUM["GRS_80",
              SPHEROID["GRS 80",6378137,298.257222101],
              TOWGS84[0,0,0,0,0,0,0]],
          PRIMEM["Greenwich",0],
          UNIT["degree",0.0174532925199433]],
      PROJECTION["Lambert_Conformal_Conic_2SP"],
      PARAMETER["standard_parallel_1",44],
      PARAMETER["standard_parallel_2",49.00000000001],
      PARAMETER["latitude_of_origin",46.5],
      PARAMETER["central_meridian",3],
      PARAMETER["false_easting",700000],
      PARAMETER["false_northing",6600000],
      UNIT["Meter",1.0]]
*/
  static function detect($opengiswkt) {
    $pattern = '!^PROJCS\["RGF93_Lambert_93",\s*'
               .'GEOGCS\["GCS_RGF_1993",\s*'
                  .'DATUM\["RGF_1993",\s*'
                    .'SPHEROID\["GRS_1980",6378137.0,298.257222101\]\],\s*'
                  .'PRIMEM\["Greenwich",0.0\],\s*'
                  .'UNIT\["Degree",0.0174532925199433\]\],\s*'
                .'PROJECTION\["Lambert_Conformal_Conic_2SP"\],\s*'
                .'PARAMETER\["False_Easting",700000.0\],\s*'
                .'PARAMETER\["False_Northing",6600000.0\],\s*'
                .'PARAMETER\["Central_Meridian",3.0\],\s*'
                .'PARAMETER\["Standard_Parallel_1",44.0\],\s*'
                .'PARAMETER\["Standard_Parallel_2",49.0\],\s*'
                .'PARAMETER\["Latitude_Of_Origin",46.5\],\s*'
                .'UNIT\["Meter",1.0\]\]\s*$'
 /*
*/
                .'!';
    if (preg_match($pattern, $opengiswkt))
      return 'L93';
    else
      die("Don't match");
  }
  
/*PhpDoc: methods
name:  chg
title: static function chg($src, $dest, $x, $y) - chg de syst. de coord. de $src vers $dest
doc: |
  Les couples acceptés sont 'geo',proj et proj,'geo'
  où proj vaut: WM, L93 ou UTM-ddX où dd est le numéro de zone et X est soit 'N', soit 'S'
*/
  static function chg($src, $dest, $x, $y) {
    foreach (['src','dest'] as $var)
      if (substr($$var,0,3)=='UTM') {
        $zone = substr($$var,4,3);
        $utm = new UTM($zone);
        $$var = 'UTM';
      }
    switch("$src-$dest") {
      case 'L93-geo':
        return Lambert93::geo($x, $y);
      case 'geo-L93':
        return Lambert93::proj($x, $y);
      case 'WM-geo':
        return WebMercator::geo($x, $y);
      case 'geo-WM':
        return WebMercator::proj($x, $y);
      case 'UTM-geo':
        return $utm->geo($x, $y);
      case 'geo-UTM':
        return $utm->proj($x, $y);
      default:
        throw new Exception("CoordSys::chg($src, $dest) inconnu");
    }
  }
};

/*PhpDoc: classes
name:  Class Lambert93 extends CoordSys
title: Class Lambert93 extends CoordSys - classe statique contenant les fonctions de proj et inverse du Lambert 93
methods:
*/
class Lambert93 extends CoordSys {
  const c = 11754255.426096; //constante de la projection
  const n = 0.725607765053267; //exposant de la projection
  const xs = 700000; //coordonnées en projection du pole
  const ys = 12655612.049876; //coordonnées en projection du pole
/*PhpDoc: methods
name:  proj
title: static function proj($longitude, $latitude)  - prend des degrés et retourne [X, Y]
*/
  static function proj($longitude, $latitude) {
// définition des constantes
    $e = self::e(); // 0.0818191910428158; //première exentricité de l'ellipsoïde

// pré-calculs
    $lat_rad= $latitude/180*PI(); //latitude en rad
    $lat_iso= atanh(sin($lat_rad))-$e*atanh($e*sin($lat_rad)); //latitude isométrique

//calcul
    $x = ((self::c * exp(-self::n * $lat_iso)) * sin(self::n * ($longitude-3)/180*pi()) + self::xs);
    $y = (self::ys - (self::c*exp(-self::n*($lat_iso))) * cos(self::n * ($longitude-3)/180*pi()));
    return [$x,$y];
  }
  
/*PhpDoc: methods
name:  geo
title: static function geo($X, $Y)  - retourne [longitude, latitude] en degrés
*/
  static function geo($X, $Y) {
    $e = self::e(); // 0.0818191910428158; //première exentricité de l'ellipsoïde

// pré-calcul
    $a = (log(self::c/(sqrt(pow(($X-self::xs),2)+pow(($Y-self::ys),2))))/self::n);

// calcul
    $longitude = ((atan(-($X-self::xs)/($Y-self::ys)))/self::n+3/180*PI())/PI()*180;
    $latitude = asin(tanh(
                  (log(self::c/sqrt(pow(($X-self::xs),2)+pow(($Y-self::ys),2)))/self::n)
                 + $e*atanh($e*(tanh($a+$e*atanh($e*(tanh($a+$e*atanh($e*(tanh($a+$e*atanh($e*(tanh($a+$e*atanh($e*(tanh($a+$e*atanh($e*(tanh($a+$e*atanh($e*sin(1))))))))))))))))))))
                 ))/PI()*180;
    return [ $longitude , $latitude ];
  }
 };
  
/*PhpDoc: classes
name:  Class WebMercator extends CoordSys
title: Class WebMercator extends CoordSys - classe statique contenant les fonctions de proj et inverse du Web Mercator
methods:
*/
class WebMercator extends CoordSys {
/*PhpDoc: methods
name:  proj
title: static function proj($longitude, $latitude)  - prend des degrés et retourne [X, Y] en Web Mercator
*/
  static function proj($longitude, $latitude) {
    $lambda = $longitude * pi() / 180.0; // longitude en radians
    $phi = $latitude * pi() / 180.0;  // latitude en radians
	  
    $x = self::a * $lambda; // (7-1)
    $y = self::a * log(tan(pi()/4 + $phi/2)); // (7-2)
    return [$x,$y];
  }
    
/*PhpDoc: methods
name:  geo
title: static function geo($X, $Y)  - prend des coordonnées Web Mercator et retourne [longitude, latitude] en degrés
*/
  static function geo($X, $Y) {
    $phi = pi()/2 - 2*atan(exp(-$Y/self::a)); // (7-4)
    $lambda = $X / self::a; // (7-5)
    return [ $lambda / pi() * 180.0 , $phi / pi() * 180.0 ];
  }
};


/*PhpDoc: classes
name:  Class UTM extends CoordSys
title: Class UTM extends CoordSys - classe contenant les fonctions de proj et inverse de l'UTM
methods:
doc: |
  Contrairement aux autres classes, cette classe doit être instantiée en fonction de la zone UTM
  Ellipsoide de Clarke pour tester l'exemple USGS à mettre à la place de CoordSys
  class Clarke1866 {
    const a = 6378206.4; // Grand axe de l'ellipsoide Clarke 1866
    static function e2() { return 0.00676866; }
  };
  class UTM extends Clarke1866 {
*/
class UTM extends CoordSys {
  const k0 = 0.9996;
  private $zone; // la zone UTM sur 3 caractères avec 2 caractères pour le numéro et un caractère N ou S, ex: '20N'
/*PhpDoc: methods
name:  __construct
title: function __construct($zone) - Le paramètre zone est codé sur 3 caractères, 2 caractères pour le numéro et un caractère N ou S, ex '20N'
*/
  function __construct($zone) { $this->zone = $zone; }
  
  function lambda0() {
    $nozone = substr($this->zone,0,2);
    return (($nozone-30.5)*6)/180*pi(); // en radians
  }
  
  function Xs() { return 500000;; }
  function Ys() { return (substr($this->zone,2,1)=='S'? 10000000 : 0); }
  
// distanceAlongMeridianFromTheEquatorToLatitude (3-21)
  static function distanceAlongMeridianFromTheEquatorToLatitude($phi) {
    $e2 = self::e2();
    return (self::a)
         * (   (1 - $e2/4 - 3*$e2*$e2/64 - 5*$e2*$e2*$e2/256)*$phi
             - (3*$e2/8 + 3*$e2*$e2/32 + 45*$e2*$e2*$e2/1024)*sin(2*$phi)
             + (15*$e2*$e2/256 + 45*$e2*$e2*$e2/1024) * sin(4*$phi)
             - (35*$e2*$e2*$e2/3072)*sin(6*$phi)
           );
  }
 
/*PhpDoc: methods
name:  proj
title: function proj($longitude, $latitude)  - prend des degrés et retourne [X, Y] en UTM zone
*/
  function proj($longitude, $latitude) {
//    echo "lambda0 = ",$this->lambda0()," rad = ",$this->lambda0()/pi()*180," degres\n";
    $e2 = $this->e2();
    $lambda = $longitude * pi() / 180.0; // longitude en radians
    $phi = $latitude * pi() / 180.0;  // latitude en radians
    $ep2 = $e2/(1 - $e2);  // echo "ep2=$ep2 (8-12)\n"; // (8-12)
    $N = (self::a) / sqrt(1 - $e2*pow(sin($phi),2)); // echo "N=$N (4-20)\n"; // (4-20)
    $T = pow(tan($phi),2); // echo "T=$T (8-13)\n"; // (8-13)
    $C = $ep2 * pow(cos($phi),2); // echo "C=$C\n"; // (8-14)
    $A = ($lambda - $this->lambda0()) * cos($phi); // echo "A=$A\n"; // (8-15)
    $M = $this->distanceAlongMeridianFromTheEquatorToLatitude($phi); // echo "M=$M\n"; // (3-21)
    $M0 = $this->distanceAlongMeridianFromTheEquatorToLatitude(0); // echo "M0=$M0\n"; // (3-21)
    $x = (self::k0) * $N * ($A + (1-$T+$C)*pow($A,3)/6 + (5-18*$T+pow($T,2)+72*$C-58*$ep2)*pow($A,5)/120); // (8-9)
//  echo "x = ",($this->k0)," * $N * ($A + (1-$T+$C)*pow($A,3)/6 + (5-18*$T+pow($T,2)+72*$C-58*$ep2)*pow($A,5)/120)\n";
//  echo "x = $x\n";
    $y = (self::k0) * ($M - $M0 + $N * tan($phi) * ($A*$A/2 + (5 - $T + 9*$C +4*$C*$C)
        * pow($A,4)/24 + (61 - 58*$T + $T*$T + 600*$C - 330*$ep2) * pow($A,6)/720));                    // (8-10)
// echo "y = ($this->k0) * ($M - $M0 + $N * tan($phi) * ($A*$A/2 + (5 - $T + 9*$C +4*$C*$C)
//          * pow($A,4)/24 + (61 - 58*$T + $T*$T + 600*$C - 330*$ep2) * pow($A,6)/720))\n";
    $k = (self::k0) * (1 + (1 + $C)*$A*$A/2 + (5 - 4*$T + 42*$C + 13*$C*$C - 28*$ep2)*pow($A,4)/24
         + (61 - 148*$T +16*$T*$T)*pow($A,6)/720);                                                    // (8-11)
    return [$x + $this->Xs(), $y + $this->Ys()];
  }
    
/*PhpDoc: methods
name:  geo
title: function geo($X, $Y)  - prend des coordonnées UTM zone et retourne [longitude, latitude] en degrés
*/
  function geo($X, $Y) {
    $e2 = $this->e2();
    $x = $X - $this->Xs();
    $y = $Y - $this->Ys();
    $M0 = $this->distanceAlongMeridianFromTheEquatorToLatitude(0); // echo "M0=$M0\n"; // (3-21)
    $ep2 = $e2/(1 - $e2); // echo "ep2=$ep2\n"; // (8-12)
    $M = $M0 + $y/self::k0; // echo "M=$M\n"; // (8-20)
    $e1 = (1 - sqrt(1-$e2)) / (1 + sqrt(1-$e2)); // echo "e1=$e1\n"; // (3-24)
    $mu = $M/(self::a*(1 - $e2/4 - 3*$e2*$e2/64 - 5*$e2*$e2*$e2/256)); // echo "mu=$mu\n"; // (7-19)
    $phi1 = $mu + (3*$e1/2 - 27*pow($e1,3)/32)*sin(2*$mu) + (21*$e1*$e1/16
                - 55*pow($e1,4)/32)*sin(4*$mu) + (151*pow($e1,3)/96)*sin(6*$mu)
                + 1097*pow($e1,4)/512*sin(8*$mu); // echo "phi1=$phi1 radians = ",$phi1*180/pi(),"°\n"; // (3-26)
    $C1 = $ep2*pow(cos($phi1),2); // echo "C1=$C1\n"; // (8-21)
    $T1 = pow(tan($phi1),2); // echo "T1=$T1\n"; // (8-22)
    $N1 = self::a/sqrt(1-$e2*pow(sin($phi1),2)); // echo "N1=$N1\n"; // (8-23)
    $R1 = self::a*(1-$e2)/pow(1-$e2*pow(sin($phi1),2),3/2); // echo "R1=$R1\n"; // (8-24)
    $D = $x/($N1*self::k0); // echo "D=$D\n"; 
    $phi = $phi1 - ($N1 * tan($phi1)/$R1) * ($D*$D/2 - (5 + 3*$T1 + 10*$C1 - 4*$C1*$C1 -9*$ep2)*pow($D,4)/24
         + (61 + 90*$T1 + 298*$C1 + 45*$T1*$T1 - 252*$ep2 - 3*$C1*$C1)*pow($D,6)/720); // (8-17)
    $lambda = $this->lambda0() + ($D - (1 + 2*$T1 + $C1)*pow($D,3)/6 + (5 - 2*$C1 + 28*$T1
               - 3*$C1*$C1 + 8*$ep2 + 24*$T1*$T1)*pow($D,5)/120)/cos($phi1); // (8-18)
    return [ $lambda / pi() * 180.0, $phi / pi() * 180.0 ];
  }
};


if (basename(__FILE__)<>basename($_SERVER['PHP_SELF'])) return;


/*PhpDoc: functions
name: degres_sexa
title: function degres_sexa($r, $ptcardinal='', $dr=0)
doc: |
  Transformation d'une valeur en radians en une chaine en degres sexagesimaux
  si ptcardinal est fourni alors le retour respecte la notation avec point cardinal
  sinon c'est la notation signee qui est utilisee
  dr est la precision de r
*/
function degres_sexa($r, $ptcardinal='', $dr=0) {
  $signe = '';
  if ($r < 0) {
    if ($ptcardinal) {
      if ($ptcardinal == 'N')
        $ptcardinal = 'S';
      elseif ($ptcardinal == 'E')
        $ptcardinal = 'W';
      elseif ($ptcardinal == 'S')
        $ptcardinal = 'N';
      else
        $ptcardinal = 'E';
    } else
      $signe = '-';
    $r = - $r;
  }
  $deg = $r / pi() * 180;
  $min = ($deg - floor($deg)) * 60;
  $sec = ($min - floor($min)) * 60;
  if ($dr == 0) {
    return $signe.sprintf("%d°%d'%.3f''%s", floor($deg), floor($min), $sec, $ptcardinal);
  } else {
    $dr = abs($dr);
    $ddeg = $dr / pi() * 180;
    $dmin = ($ddeg - floor($ddeg)) * 60;
    $dsec = ($dmin - floor($dmin)) * 60;
    $ret = $signe.sprintf("%d",floor($deg));
    if ($ddeg > 0.5) {
      $ret .= sprintf(" +/- %d ° %s", round($ddeg), $ptcardinal);
      return $ret;
    }
    $ret .= sprintf("°%d",floor($min));
    if ($dmin > 0.5) {
      $ret .= sprintf(" +/- %d ' %s", round($dmin), $ptcardinal);
      return $ret;
    }
    $f = floor(log($dsec,10));
    $fmt = '%.'.($f<0 ? -$f : 0).'f';
    return $ret.sprintf("'$fmt +/- $fmt'' %s", $sec, $dsec, $ptcardinal);
  }
};

echo "<html><head><meta charset='UTF-8'><title>coordsys</title></head><body><pre>";

if (0) {
  echo "Example du rapport USGS pp 269-270 utilisant l'Ellipsoide de Clarke\n";
  $utm18N = new UTM('18N');
  $pt = [-73.5, 40.5];
  echo "phi=",degres_sexa($pt[1]/180*PI(),'N'),", lambda=", degres_sexa($pt[0]/180*PI(),'E'),"\n";
  $utm = $utm18N->proj($pt[0],$pt[1]);
  echo "UTM: X=$utm[0] / 127106.5, Y=$utm[1] / 4,484,124.4\n";
  
  $verif = $utm18N->geo($utm[0], $utm[1]);
  echo "phi=",degres_sexa($verif[1]/180*PI(),'N')," / ",degres_sexa($pt[1]/180*PI(),'N'),
       ", lambda=", degres_sexa($verif[0]/180*PI(),'E')," / ", degres_sexa($pt[0]/180*PI(),'E'),"\n";
  die("FIN ligne ".__LINE__);
}

$refs = [
  'Paris I (d) Quartier Carnot'=>[
    'src'=> 'http://geodesie.ign.fr/fiches/pdf/7505601.pdf',
    'L93'=> [658557.548, 6860084.001],
    'LatLong'=> [48.839473, 2.435368],
    'dms'=> ["48°50'22.1016''", "2°26'07.3236''"],
    'WM'=> [271103.889193, 6247667.030696],
    'UTM-31N'=> [458568.90, 5409764.67],
  ],
  'FORT-DE-FRANCE V (c)' =>[
    'src'=>'http://geodesie.ign.fr/fiches/pdf/9720905.pdf',
    'UTM'=> ['20N', 708544.10, 1616982.70],
    'dms'=> ["14° 37' 05.3667''", "61° 03' 50.0647''" ],
  ],
  'SAINT-DENIS C (a)' =>[
    'src'=>'http://geodesie.ign.fr/fiches/pdf/97411C.pdf',
    'UTM'=> ['40S', 338599.03, 7690489.04],
    'dms'=> ["20° 52' 43.6074'' S", "55° 26' 54.2273'' E" ],
  ],
];

foreach ($refs as $name => $ref) {
  echo "\nCoordonnees Pt Geodesique <a href='$ref[src]'>$name</a>\n";
  if (isset($ref['L93'])) {
    $clamb = $ref['L93'];
    echo "geo ($clamb[0], $clamb[1], L93) ->";
    $cgeo = Lambert93::geo ($clamb[0], $clamb[1]);
    printf ("phi=%s / %s lambda=%s / %s\n",
      degres_sexa($cgeo[1]/180*PI(),'N'), $ref['dms'][0],
      degres_sexa($cgeo[0]/180*PI(),'E'), $ref['dms'][1]);
    $cproj = Lambert93::proj($cgeo[0], $cgeo[1]);
    printf ("Verification du calcul inverse: %.2f / %.2f , %.2f / %.2f\n\n", $cproj[0], $clamb[0], $cproj[1], $clamb[1]);

    $cwm = WebMercator::proj($cgeo[0], $cgeo[1]);
    printf ("Coordonnées en WM: %.2f / %.2f, %.2f / %.2f\n", $cwm[0], $ref['WM'][0], $cwm[1], $ref['WM'][1]);
  
// UTM
    $zone = sprintf('%2d',floor($cgeo[0]/6)+31).($cgeo[1]>0?'N':'S');
    echo "\nUTM:\nzone=$zone\n";
    $utm = new UTM($zone);
    $cutm = $utm->proj($cgeo[0], $cgeo[1]);
    printf ("Coordonnées en UTM-$zone: %.2f / %.2f, %.2f / %.2f\n", $cutm[0], $ref['UTM-31N'][0], $cutm[1], $ref['UTM-31N'][1]);
    $verif = $utm->geo($cutm[0], $cutm[1]);
    echo "Verification du calcul inverse:\n";
    printf ("phi=%s / 48°50'22.1016'' lambda=%s / 2°26'07.3236''\n",
      degres_sexa($verif[1]/180*PI(),'N'), degres_sexa($verif[0]/180*PI(),'E'));
  }
  elseif (isset($ref['UTM'])) {
    $utm = new UTM($ref['UTM'][0]);
    $cgeo = $utm->geo($ref['UTM'][1], $ref['UTM'][2]);
    printf ("phi=%s / %s lambda=%s / %s\n",
      degres_sexa($cgeo[1]/180*PI(),'N'), $ref['dms'][0],
      degres_sexa($cgeo[0]/180*PI(),'E'), $ref['dms'][1]);
    $cutm = $utm->proj($cgeo[0], $cgeo[1]);
    printf ("Coordonnées en UTM-%s: %.2f / %.2f, %.2f / %.2f\n", $ref['UTM'][0], $cutm[0], $ref['UTM'][1], $cutm[1], $ref['UTM'][2]);
  }
}