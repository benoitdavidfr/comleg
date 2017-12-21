<?php
/*PhpDoc:
name: topomap.inc.php
title: topomap.inc.php - structure topologique de carte
classes:
doc: |
  Structure topologique de carte définie par 6 classes et 1 interface:
    - Feature : l'objet "métier" défini par un ensemble de Face et identifié par un id métier
        la classe enregistre en outre en statique l'ensemble des objets
    - Face : une face de la carte, définie par un extérieur et d'éventuels trous, chacun défini par un anneau de brins
    - Blade : un brin qui est soit une limite soit une limite inverse
    - Lim: une limite mitoyenne entre 2 faces ou d'une face avec l'extérieur de la carte
        la classe enregistre en outre en statique l'ensemble des limites
    - InvLim: une limite inverse, cad une limite considérée géométriquement dans le sens inverse
    - Node: un noeud du graphe
    - interface LimGeometry définissant l'accès à la géométrie d'une limite à partir de son id

  Chaque brin référence le brin inverse (inv) et le brin suivant définissant les anneaux des faces et trous (fi).
  Un cycle de brins défini par intention soit:
    - par la propriété fi portée par chaque brin, chaque cycle est un anneau est soit l'extérieur d'une face soit un trou,
    - par la proprité inv portée par chaque brin, chaque cycle correspond à une limite,
    - par fi o inv, chaque anneau correspond à un noeud.
  En raison d'un bug Php le brin suivant de chaque brin est défini comme id et non comme référence.

  L'interface extérieure de ce package est définie par les méthodes suivantes:
    - new Feature(string $id, array $polygons) crée un nouveau Feature et l'enregistre dans la classe
      polygons est [[area:float, rings:[[BladeId]]]] / BladeId est un identifiant de brin
      Le premier anneau défini correspond à l'extérieur de la face,
      les autres correspondent chacun à un trou associé à la face.
      Un id. de brin est un id de limite éventuellement précédé du signe '-'
    - Feature::showAll() affiche les features
    - Lim::completeFi() complète la définition des faces non définies dans la construction des features
      notamment l'extérieur de la carte n'est généralement pas défini explicitement par un anneau
      il peut aussi y avoir des lacs non définis explicitement par un anneau
    - Lim::buildNodes() fabrique les noeuds du graphe
    - Lim::showNodes() affiche les noeuds
    - Feature::checkAll() effectue un test de cohérence
    - Feature::delAllSmallFaces() détruit les petites faces
      Lorsqu'un Feature est composé de plusieurs faces, j'appelle petite face une face:
        - ne comportant pas de trou et
        - dont la surface est inférieure à celle de la face du Feature ayant la plus grande surface
    - Feature::storeAll($collection) enregistre les objets dans la collection de polygones

  Une carte topologique est stockée en MongoDB dans 2 collections:
    - celle des limites qui définit pour chaque limite un identifiant et lui associe notamment sa géométrie
    - celle des polygones qui associe à chaque feature une liste de polygones, chacun défini par une surface
      et une liste d'anneaux, le premier correspondant à l'extérieur du polygone et les autres à des trous,
      chaque anneau étant défini comme une liste d'identifiants soit de limite soit de limite inverse.
      L'identifiant d'une limite inverse est représenté par l'identifiant de la limite précédé de la chaine '- '.

journal: |
  12/12/2017:
    correction d'un bug dans Blade::deleteNonIsland()
  11/12/2017:
    Suppression de ttes les petites faces y compris celles qui ne sont pas des iles
    Sur l'ensemble de la France suppression de 550 faces
  6-7/12/2017:
    Amélioration de la doc
    Lors de la suppression d'une ile "terrestre" augmentation de la surface de la face correspondante
  5/12/2017:
    Stockage de l'id des brins au lieu de la référence à l'objet
  4/12/2017:
    Plante avec le test créant de nombreux objets interconnectés
    Cela semble provenir de la classe abstraite Blade
    J'ai essayé de la supprimer complètement et cela ne résoud pas le problème
  3/12/2017
    restructuration en supprimant la classe Ring et en définissant les anneaux dans Blade
    plante avec simplif.php !!!
  2/12/2017
    création à partir de simplif.php pour partager le code avec dellim.php
*/

/*PhpDoc: classes
name: Node
title: Node - un noeud de la carte
doc: |
*/
class Node {
  private $blade; // un des brins arrivant au noeud : Blade
  
  function __construct(Blade $blade) { $this->blade = $blade; }
  
  // renvoie le premier brin arrivant au noeud
  function blade(): Blade { return $this->blade; }
  
  // renvoie la liste des brins arrivant au noeud
  function arrivings(): array { return self::ring($this->blade); }
  
  // renvoie la liste des brins définie par sigma = inv o fi
  static function ring(Blade $b0): array {
    $ring = [];
    $b = $b0;
    for($i=0; $i<1000; $i++) {
      //echo "Blade::nodeRing: b=$b\n";
      if (!$b)
        throw new Exception("Erreur dans Node::ring b null");
      $ring[] = $b;
      $b = $b->fi()->inv();
      if ($b === $b0)
        return $ring;
    }
    throw new Exception("Erreur dans Node::ring boucle");
  }
  
  // Teste si un noeud appartient au tableau de noeuds
  function inArray(array $elts):bool {
    foreach ($elts as $elt)
      if ($elt===$this)
        return true;
    return false;
  }
  
  function __toString(): string { return 'N'.$this->blade; }
    
  function show() {
    $blades = [];
    foreach ($this->arrivings() as $b)
      $blades[] = ($b ? $b : 'null');
    echo "Node:",implode(',',$blades),"\n";
  }
};

/*PhpDoc: classes
name: Face
title: abstract class Blade - un brin cad soit une limite soit une limite inverse
doc: |
  la propritété fi contient l'identifiant du brin et non sa référence en raison d'un bug de Php
*/
abstract class Blade {
  protected $inv=null; // le brin inverse : Blade
  protected $fi=null; // le brin suivant définissant la face à droite : BladeId
  protected $right=null; // la face à droite : Face
  protected $final=null; // le noeud final : Node 
  
  function inv(): Blade { return $this->inv; }
  function fi(): ?Blade { return ($this->fi ? Lim::get($this->fi) : null); }
  function left(): ?Face { return $this->inv->right; }
  function setRight(Face $right): void { $this->right = $right; }
  function final(): ?Node { return $this->final; }
  function initial(): ?Node { return $this->inv->final; }
  function __toString(): string { return $this->id(); }
  
  function setFi(string $bladeId): void {
    //echo "Blade::setFi(): $this -> $fi\n";
    if ($this->fi)
      throw new Exception("Erreur dans Blade::setFi($bladeId) : fi est déjà défini");
    $this->fi = $bladeId;
  }
  
  // appliquée aux brins pour lesquels fi est indéfini à la fin du chargement des faces
  // il s'agit des brins bordant la mer ou un lac
  function setUndefinedFi(): void {
    //echo "Blade::setUndefinedFi()@$this\n";
    $b = $this;
    for($i=0; $i<100000; $i++) {
      try {
        if (!$b->inv->fiInv()) {
          //echo "Blade::setUndefinedFi: fi($this)=",$b->inv,"\n";
          $this->fi = $b->inv->id();
          return;
        }
        $b = $b->inv->fiInv();
        //echo "Blade::setUndefinedFi: b=$b\n";
      } catch(Exception $e) {
        echo "Exception dans Blade::fiInv()@",$b->inv,"\n";
        throw new Exception("Erreur dans Blade::fiInv()@".$b->inv." boucle");
      }
    }
    throw new Exception("Erreur dans Blade::setUndefinedFi boucle");
  }
  
  // fi-1(), retourne null si fi-1 est non défini
  function fiInv(): ?Blade {
    $b = $this;
    for($i=0; $i<100000; $i++) {
      //echo "Blade::fiInv: b=$b\n";
      if (!$b)
        return null;
      if ($b->fi() === $this)
        return $b;
      $b = $b->fi();
    }
    throw new Exception("Erreur dans Blade::fiInv boucle");
  }
    
  // créée le noeud final
  function setUndefinedFinal(): void {
    $final = new Node($this);
    $b = $this;
    for($i=0; $i<1000; $i++) {
      $b->final = $final;
      $b = $b->fi()->inv;
      if ($b === $this)
        return;
      if (!$b)
        throw new Exception("Erreur dans Blade::setUndefinedFinal b null");
    }
    throw new Exception("Erreur dans Blade::setUndefinedFinal boucle");
  }
  
  // la suppression d'un brin constituant une ile marine ou terrestre
  function deleteIsland(float $area): void {
    echo "Blade::deleteIsland()@".$this->id()."\n";
    $limId = $this->lim()->id();
    unset(Lim::$all[$limId]);
    Lim::$deleted[$limId] = 1;
    if ($this->inv->right) {
      $this->inv->right->deleteHole($this->inv, $area);
      $this->inv->right = null;
    }
    $this->final = null;
    $this->inv->final = null;
    $this->inv->inv = null;
    $this->inv = null;
  }
  
  // la suppression de la face définie par le brin, la face ne constituant pas une ile marine ou terrestre
  // la face est intégrée dans la face définie par le brin inverse
  // 5 actions:
  // 1) pour tous les brins de la face à détruire réaffecter Blade::right à la nouvelle face
  // 2) changer les 2 Blade::fi
  // 3) supprimer la limite de Lim::$all et l'affecter à Lim::$deleted
  // 4) s'assurer que l'autre face n'est pas définie par le brin inverse qui disparait
  // 5) transférer la surface à la nouvelle face
  function deleteNonIsland(float $area): void {
    echo "Blade::deleteNonIsland()@".$this->id()."\n";
    // 1) pour tous les brins de la face à détruire réaffecter Blade::right à la nouvelle face
    foreach (Face::ring($this) as $blade)
      $blade->right = $this->left();
    // 2) changer les 2 Blade::fi
    $blade = $this->fiInv();
    if (!$blade)
      throw new Exception("Erreur dans Blade::deleteNonIsland()@$this : fiInv() non défini");
    $blade->fi = $this->inv->fi;
    $blade = $this->inv->fiInv();
    if (!$blade)
      throw new Exception("Erreur dans Blade::deleteNonIsland()@$this : fiInv(inv()) non défini");
    $blade->fi = $this->fi;
    // 3) supprimer la limite de Lim::$all et l'affecter à Lim::$deleted
    $limId = $this->lim()->id();
    unset(Lim::$all[$limId]);
    Lim::$deleted[$limId] = 1;
    // 4) s'assurer que l'autre face n'est pas définie par le brin inverse qui disparait
    // 5) transférer la surface à la nouvelle face
    if ($this->left())
      $this->left()->deleteNonIsland2($area, $this->inv, $this->inv->fi());
  }
};

/*PhpDoc: classes
name: Lim
title: class Lim extends Blade - une limite entre faces ou avec l'extérieur
*/
class Lim extends Blade {
  static $all=[]; // [ id => Lim ]
  static $deleted; // [ id => 1 ]
  private $id;
  
  function id(): string { return $this->id; }
  function lim(): Lim { return $this; }
    
  function __construct(string $id) {
    $this->id = $id;
    $this->inv = new InvLim($this);
    self::$all[$id] = $this;
  }
    
  // retourne le brin ayant cet id, le crée s'il n'existe pas
  static function get(string $id): Blade {
    $inv = false;
    if (substr($id,0,2)=='- ') {
      $id = substr($id,2);
      $inv = true;
    }
    elseif (substr($id,0,1)=='-') {
      $id = substr($id,1);
      $inv = true;
    }
    if (!isset(Lim::$all[$id]))
      Lim::$all[$id] = new Lim($id);
    if (!$inv)
      return Lim::$all[$id];
    else
      return Lim::$all[$id]->inv;
  }

  // Complète fi() pour les brins bordant la mer ou un lac
  // Après l'exécution de cette méthode pour chaque brin Blade::fi est non null
  static function completeFi() {
    foreach (self::$all as $lim) {
      if (!$lim->left()) {
        //echo "left non defini pour $lim\n";
        $lim->inv->setUndefinedFi();
      }
      if (!$lim->right) {
        //echo "right non defini pour $lim\n";
        $lim->setUndefinedFi();
      }
    }
  }
  
  // construit les objets Noeuds et les affectent à Blade::final
  // Après l'exécution de cette méthode: pour chaque brinBlade::final est non null
  static function buildNodes() {
    foreach (self::$all as $lim) {
      if (!$lim->final()) {
        //echo "final non defini pour $lim\n";
        $lim->setUndefinedFinal();
      }
      if (!$lim->initial()) {
        //echo "initial non defini pour $lim\n";
        $lim->inv()->setUndefinedFinal();
      }
    }
  }
  
  static function showNodes() {
    $nodes = [];
    foreach (self::$all as $lim) {
      if (!$lim->final->inArray($nodes))
        $nodes[] = $lim->final;
      if (!$lim->inv->final->inArray($nodes))
        $nodes[] = $lim->inv->final;
    }
    echo "Nodes:\n";
    foreach ($nodes as $node) {
      echo '  - ';
      $node->show();
    }
  }
};

/*PhpDoc: classes
name: 
title: class InvLim extends Blade - une limite prise en sens inverse
*/
class InvLim extends Blade {
  function __construct(Lim $inv) { $this->inv = $inv; }
  function id():string { return '-'.$this->inv->id(); }
  function lim(): Lim { return $this->inv; }
};

/*PhpDoc: classes
name: Face
title: Face - une face de la carte, définie par un extérieur et des trous, chacun défini par un anneau
doc: |
*/
class Face {
  private $parent; // le feature auquel appartient la face : Feature
  private $area; // la surface en °2 : float
  private $exterior; // un brin sur l'anneau extérieur : Blade
  private $holes=[]; // l'ensembles des trous chacun défini par un brin : [ Blade ]
  
  function id(): string { return $this->parent->id(); }
  function area(): float { return $this->area; }
  function holes(): array { return $this->holes; }
  
  function __construct(Feature $parent, float $area, array $rings) {
    $this->parent = $parent;
    $this->area = $area;
    $this->exterior = null;
    $this->holes = [];
    foreach ($rings as $noring => $ring) {
      $bprec = null;
      if (count($ring) < 1)
        throw new Exception("Face::new() : erreur anneau vide");
      foreach ($ring as $bladeId) {
        $b = Lim::get($bladeId);
        $b->setRight($this);
        if (!$bprec)
          $b0 = $b;
        else
          $bprec->setFi($bladeId); // fi(bprec) <- b
        $bprec = $b;
       }
      $b->setFi($b0); // fi(b) <- b0
      if (!$this->exterior)
        $this->exterior = $b0;
      else
        $this->holes[] = $b0;
    }
  }
    
  // renvoie la liste des anneaux définissant la face, chaque anneau étant une liste de brins définie par fi 
  function rings(): array {
    $rings = [ self::ring($this->exterior) ];
    foreach ($this->holes as $hole) {
      $rings[] = self::ring($hole);
    }
    return $rings;
  }
  
  // renvoie la liste des brins définie par fi
  static function ring(Blade $b0): array {
    $ring = [];
    $b = $b0;
    for($i=0; $i<1000; $i++) {
      //echo "Face::ring: b=$b\n";
      if (!$b)
        throw new Exception("Erreur dans Face::ring b null");
      $ring[] = $b;
      $b = $b->fi();
      if ($b === $b0)
        return $ring;
    }
    throw new Exception("Erreur dans Face::ring boucle");
  }
  
  function show(string $indent): void {
    echo $indent,"area: $this->area\n";
    echo $indent,"rings:\n";
    foreach ($this->rings() as $ring) {
      $ids = [];
      foreach ($ring as $b)
        $ids[] = $b->id();
      echo $indent,"  - [",implode(', ',$ids),"]\n";
    }
  }
  function show2(string $indent): void {
    echo $indent,"area: $this->area\n";
    echo $indent,"exterior: ",$this->exterior,"\n";
    if ($this->holes) {
      echo $indent,"holes:\n";
      foreach ($this->holes as $hole)
        echo $indent,"  - $hole\n";
    }
  }
  
  function check(string $fid, int $noface): void {
    if ($this->area < 0)
      echo "Alerte $fid:$noface area = $this->area\n";
  }
  
  // supprime une face sans trou dont l'extérieur ne comprend qu'un brin
  function deleteIsland(string $fid, int $noface): void {
    echo "Face::deleteIsland()@$fid:$noface\n";
    $this->exterior->deleteIsland($this->area);
    $this->exterior = null;
    $this->parent = null;
  }
  
  // supprime un trou constitué d'un brin, appelé par Blade::delete()
  function deleteHole(Blade $holeToDelete, float $area): void {
    echo "Face::deleteHole($holeToDelete)@",$this->parent->id(),"\n";
    foreach ($this->holes as $nohole => $hole) {
      if ($hole === $holeToDelete) {
        unset($this->holes[$nohole]);
        $this->holes = array_values($this->holes);
        $this->area += $area; // augmentation de la surface de la face de celle du trou
        return;
      }
    }
    throw new Exception("Face::deleteHole() hole not found");
  }
  
  // supprime une face sans trou dont l'extérieur comprend plusieurs brins
  function deleteNonIsland(string $fid, int $noface): void {
    echo "Face::deleteNonIsland()@$fid:$noface\n";
    $this->exterior->deleteNonIsland($this->area);
    $this->exterior = null;
    $this->parent = null;
  }
  
  // augmente la face de la face supprimée par deleteNonIsland()
  function deleteNonIsland2(float $area, Blade $blade, Blade $fi): void {
    // 4) s'assurer que l'autre face n'est pas définie par le brin inverse qui disparait
    if ($this->exterior === $blade)
      $this->exterior = $fi;
    // 5) transférer la surface à la nouvelle face
    $this->area += $area;
  }
  
  // retourne une représentation en tableau pur Php sans objet
  function asArray(): array {
    $doc = ['area'=>$this->area, 'rings'=>[]];
    foreach ($this->rings() as $ring) {
      $ringArray = [];
      foreach ($ring as $b)
        $ringArray[] = $b->id();
      $doc['rings'][] = $ringArray;
    }
    return $doc;
  }
};

/*PhpDoc: classes
name: Feature
title: Feature - objet "métier" défini par un ensemble de Faces et identifié par un id métier
doc: |
  La classe enregistre en outre l'ensemble des objets
  La classe sait supprimer les petites faces des features
*/
class Feature {
  static $all=[]; // liste des MPol [ id => MPol ]
  private $id;
  private $faces; // [ Face ]
  
  function id() { return $this->id; }
  function get(string $id) { return isset(self::$all[$id]) ? self::$all[$id] : null; }
    
  function __construct(string $id, array $polygons) {
   // echo "Feature::__construct(id=$id, polygons)\n";
    $this->id = $id;
    $this->faces = [];
    foreach ($polygons as $polygon) {
      $this->faces[] = new Face($this, $polygon['area'], $polygon['rings']);
    }
    self::$all[$id] = $this;
    //echo "FIN Feature::__construct(id=$id, polygons)\n";
    //printf("memory_get_usage=%.2fMo\n",memory_get_usage(true)/1024/1024);
  }
  
  static function showAll() {
    foreach (self::$all as $feature) {
      $feature->show();
    }
  }
  function show() {
    echo $this->id,":\n";
    foreach($this->faces as $face) {
      echo "  -:\n";
      $face->show('    ');
    }
  }
  
  static function checkAll() {
    foreach(self::$all as $feature)
      $feature->check();
  }
  function check() {
    foreach ($this->faces as $noface => $face)
      $face->check($this->id, $noface);
  }
  
  // supprime les petites faces dans tous les features
  static function delAllSmallFaces() {
    $nbDeletedFaces = 0;
    foreach (self::$all as $feature)
      $nbDeletedFaces += $feature->delSmallFaces();
    echo $nbDeletedFaces," faces supprimées\n";
  }
  // supprime les petites faces du feature courant
  function delSmallFaces() {
    // le MPol 14174 est mal formé
    if ($this->id=='14174')
      return;
    $nbDeletedFaces = 0;
    if (count($this->faces) > 1) {
      $biggest = $this->biggest();
      foreach ($this->faces as $noface => $face) {
        if ($noface <> $biggest) {
          if (!$face->holes()) { // face sans trou
            $rings = $face->rings();
            if (count($rings[0]) == 1) { // extérieur composé d'un seul brin
              //$this->show();
              echo "effacement de l'ile définie par la face $noface dans l'objet $this->id\n";
              $face->deleteIsland($this->id, $noface);
            }
            else { // extérieur composé de plusieurs brins
              echo "effacement de la face (non ile) $noface dans l'objet $this->id\n";
              $face->deleteNonIsland($this->id, $noface);
            }
            unset($this->faces[$noface]);
            $nbDeletedFaces++;
          }
        }
      }
      $this->faces = array_values($this->faces);
    }
    return $nbDeletedFaces;
  }
  
  // retourne le no du polygone le plus grand dans le MutiPolygone
  function biggest(): int {
    $biggest = 0;
    $bigarea = 0;
    foreach ($this->faces as $noface => $face) {
      if ($face->area() > $bigarea) {
        $biggest = $noface;
        $bigarea = $face->area();
      }
    }
    return $biggest;
  }
  
  static function storeAll($collection): int {
    foreach (self::$all as $feature)
      $feature->store($collection);
    return count(self::$all);
  }
  function store($collection) {
    $doc['_id'] = $this->id;
    $doc['polygons'] = [];
    foreach($this->faces as $face)
      $doc['polygons'][] = $face->asArray();
    $collection->insertOne($doc);
  }
};

/*PhpDoc: classes
name: LimGeometry
title: interface LimGeometry - l'interface d'accès à la géométrie des limites
doc: |
*/
interface LimGeometry {
  static function getCoordinates(string $bladeId): array;
};

if (basename(__FILE__)<>basename($_SERVER['PHP_SELF'])) return;

require_once __DIR__.'/../../geometry/inc.php';

class LimGeometryTest implements LimGeometry {
  static $lims = [
    'L1'=> [[0,0],[10,0]],
    'L2'=> [[0,0],[0,10]],
    'L3'=> [[0,10],[10,10]],
    'L4'=> [[10,0],[10,10]],
    'L5'=> [[0,0],[5,4]],
    'L6'=> [[10,0],[5,4]],
    'L7'=> [[10,10],[5,6]],
    'L8'=> [[0,10],[5,6]],
    'L9'=> [[5,4],[5,6]],
    'L21'=> [[15,0],[15,2],[17,0],[15,0]],
    'L41'=> [[7,4],[9,4],[9,2],[7,4]],
  ];
  static function getCoordinates(string $bladeId): array {
    if (substr($bladeId,0,1)=='-')
      return (array_reverse(self::getCoordinates(substr($bladeId,1))));
    else
      return (isset(self::$lims[$bladeId]) ? self::$lims[$bladeId] : null);
  }

  static function faceArea(string $id, array $rings): float {
    $area = 0;
    foreach ($rings as $ring) {
      $lpts = []; // liste des points
      foreach ($ring as $bladeId) {
        $coords = self::getCoordinates($bladeId);
        if (!$lpts)
          $lpts = $coords;
        else {
          array_pop($lpts);
          $lpts = array_merge($lpts, $coords);
        }
      }
      $polygon = new Polygon([$lpts]);
      $area += - $polygon->area();
      echo "featureArea($id): $area\n";
    }
    return $area;
  }
};

if (1) { // Jeu test permettant de tester la construction de la carte
  // Chaque feature définit une liste de faces, chacune définie par une liste d'anneaux,
  // chacun défini par une liste d'identifiant de brin
  $features = [
    'F1' => [[['L5','-L6','-L1']]],
    'F2' => [[['L2','L8','-L9','-L5']],[['L21']]],
    'F3' => [[['L3','L7','-L8']],[['L41']]],
    'F4' => [[['-L4','L6','L9','-L7'],['-L41']]],
  ];
  foreach ($features as $id=> $faces) {
    $polygons = [];
    foreach ($faces as $rings) {
      $polygons[] = ['area'=>LimGeometryTest::faceArea($id, $rings), 'rings'=>$rings];
    }
    new Feature($id, $polygons);
  }
  Feature::showAll();
  Lim::completeFi();
  Lim::buildNodes();
  Lim::showNodes();
  Feature::checkAll();
  if (1) {
    Feature::delAllIslands();
    Feature::showAll();
    Lim::showNodes();
  }
  die("FIN ligne ".__LINE__."\n");
}

elseif (0) { // Jeu test multipliant le nbre d'objets indépendants
  ini_set('memory_limit', '512M');
  $features = [
    'F1' => [[['L5','-L6','-L1']]],
    'F2' => [[['L2','L8','-L9','-L5']],[['L21']]],
    'F3' => [[['L3','L7','-L8']],[['L41']]],
    'F4' => [[['-L4','L6','L9','-L7'],['-L41']]],
  ];
  for($i=0; $i<10000; $i++) {
    echo "i=$i\n";
    foreach ($features as $id=> $faces) {
      $polygons = [];
      foreach ($faces as $rings) {
        foreach ($rings as $noring => $ring) {
          $ring2 = [];
          foreach ($ring as $bid)
            $ring2[] = $bid.'.'.$i;
          $rings[$noring] = $ring2;
        }
        $polygons[] = ['area'=>0.0, 'rings'=>$rings];
      }
      new Feature($id.'.'.$i, $polygons);
    }
  }
  Feature::showAll();
}

elseif (1) { // Jeu test avec des objets fortement connectés
  // Segmentation fault pour max=200, pas pour 100 ou 3
  ini_set('memory_limit', '512M');
  $max = 200;
  //$max = 100;
  //$max = 3;
  for ($i=0; $i<$max; $i++) {
    for ($j=0; $j<$max; $j++) {
      $ring = [
        sprintf('%dy%d',$i,$j),
        sprintf('%dx%d',$i,$j+1),
        sprintf('-%dy%d',$i+1,$j),
        sprintf('-%dx%d',$i,$j),
      ];
      new Feature("$i:$j", [['area'=>1.0, 'rings'=>[$ring]]]);
    }
  }
  Feature::showAll();
}

