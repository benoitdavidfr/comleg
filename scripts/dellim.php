<?php
/*PhpDoc:
name: dellim.php
title: dellim.php - supprime les petites limites entre faces en créant des macro-noeuds
tables:
  - name: c_g2__mnd
    title: c_g2__mnd - macro-noeuds constitués de limites
    database: [adminexpress]
    columns:
      _id:
        title: _id - l'id de la première limite
      lims:
        title: lims - [ LimId ]
        doc: |
          liste de identifiants des limites appartenant au macro-noeud
      geometry:
        title: geometry - geometrie GeoJSON de type Point
        doc: |
          correspond au centre du rectangle englobant l'ensemble des limites dans le noeud
doc: |
  Lit c_lim et c_g2_pol créé par simplif.php
  supprime les petites limites entre faces en créant des macro-noeuds
  Enregistre les macro-noeuds dans c_g2_mnd
journal: |
  13/12/2017
    Contournement du Segmentation fault en stockant dans MacroNode un tableau de LimId au lieu de stocker
    un tableau de Lim
  12/12/2017
    Suppression de K85307:85294 de longueur 0.0071903724498322
    Segmentation fault
  10-11/12/2017
    modification pour ne pas crasher (segmentation fault) sur la totalité
    26323 limites supprimées sur 106060
    17159 macro-noeuds enregistrés
  2-9/12/2017
    retructuration
  16/11/2017
    version montrant la faisabilité
    génération des polygones dans un fichier GeoJSON de 6,67 Mo
  15/11/2017
    le script s'exécute jusqu'à la fin
*/
ini_set('memory_limit', '512M');
require_once __DIR__.'/mongodbclient.inc.php';
require_once __DIR__.'/../geometry/inc.php';

const DISTRESHOLD = 2.0E-2; // 2 km

$adminexp = mongoDbClient()->adminexp;

// fonction de comparaison entre limites sur leur longueur
function cmplimonlength(Lim $a, Lim $b) {
  if ($a->length() == $b->length())
    return 0;
  if ($a->length() < $b->length())
    return -1;
  else
    return 1;
}

// Brin cad limite ou limite inverse
abstract class Blade {
  protected $inv; // Blade
  protected $right=null; // la face à droite : Face
  protected $final=null; // le noeud final : Node 
  
  function inv(): Blade { return $this->inv; }
  function left() { return $this->inv->right; }
  function setRight(Face $right): Face { $this->right = $right; return $right; }
  function setLeft(Face $left): Face { $this->inv->right = $left; return $left; }
  function final(): ?Node { return $this->final; }
  function initial(): ?Node { return $this->inv->final; }
  function setFinal(Node $n): Node { $this->final = $n; return $n; }
  function setInitial(Node $n): Node { $this->inv->final = $n; return $n; }
  
  // indique que 2 brins se suivent au travers d'un noeud commun
  // méthode appelée sur suiv avec le prec comme paramètre
  // cad $prec->nfinal == $this->ninitial
  function setNode(Blade $prec) {
    //echo "setNode($prec)@$this\n";
    if (!$prec->final() and !$this->initial()) {
      $node = new Node([$prec, $this->inv()]); // les brins arrivants sont $prec et $this->inv()
      $prec->setFinal($node);
      $this->setInitial($node);
      //echo "création du noeud\n";
      //$node->show('');
      return $node;
    }
    elseif (!($prec->final())) {
      //echo "affectation du noeud initial de $this comme noeud final de $prec\n";
      $this->initial()->add($prec);
      return $prec->setFinal($this->initial());
    }
    elseif (!($this->initial())) {
      //echo "affectation du noeud final de $prec comme noeud initial de $this\n";
      $prec->final()->add($this->inv());
      return $this->setInitial($prec->final());
    }
    else {
      //echo "fusion des 2 noeuds\n";
      return $this->setInitial($prec->final()->fusion($this->initial()));
    }
  }
};

// Limite entre 2 communes
class Lim extends Blade {
  static $all=[]; // stocke les limites indexés par leur id: [id => Lim]
  private $id; // id de la limite (string)
  //private $inv; // LimInv
  private $length; // longuer en degrés
  private $bbox; // Bbox
  private $deleted; // 0 non détruite ou priorité de destruction: 1 plus prioritaire, >1 moins prioritaire
  private $macroNode; // null si non deleted, le macronode si deleted
  
  // créée une nouvelle limite et l'enregistre dans l'extension
  static function create($id, $prop, $geom) {
    self::$all[$id] = new Lim($id, $prop, $geom);
  }
  
  // renvoie le brin portant cet $id ou null
  static function get(string $id) {
    if (substr($id,0,1)=='-') {
      $inv = self::get(substr($id,1));
      return ($inv ? $inv->inv() : null);
    }
    else
      return (isset(self::$all[$id]) ? self::$all[$id] : null);
  }
  
  // trie des limites selon leur longueur croissante
  static function sortOnLength() {
    uasort(self::$all, 'cmplimonlength');
  }
  
  // supprime itérativement les limites respectant les contraintes
  static function delLims(float $distThreshold) {
    $nbLimDeleted=0;
    foreach (self::$all as $limid => $lim) {
      if ($lim->right and ($lim->right->nblim() > 3)
          and (!$lim->left() or ($lim->left()->nblim() > 3))) {
        if ($lim->length > $distThreshold)
          return $nbLimDeleted;
        echo "Suppression de $limid de longueur $lim->length\n";
        $lim->right->decrNblim();
        if ($lim->left())
          $lim->left()->decrNblim();
        $lim->deleted = ++$nbLimDeleted;
        // ajout de la limite à un macro-noeud, 3 possibilités:
        // 1) créer un nouveau macro-noeud
        // 2) ajouter la limite à un macro-noeud existant adjacent
        // 3) fusionner 2 macro-noeuds adjacents
        $macroNodes = [];
        // je cherche d'éventuels macro-noeuds auquels appartiendraient les limites adjacentes
        foreach ($lim->adjacentLims() as $adjlim) {
          if ($adjlim->macroNode and !$adjlim->macroNode->in_array($macroNodes))
            $macroNodes[] = $adjlim->macroNode;
        }
        //echo "adjacentLims: ",implode(',',$lim->adjacentLims()),"\n";
        //echo "macroNodes: ",implode(',',$macroNodes),"\n";
        // Si aucun, je crée un nouveau macro-noeud
        if (count($macroNodes)==0) {
          echo "Suppression de $lim, création d'un macro-noeud\n";
          $lim->macroNode = new MacroNode([$lim]);
        }
        // Si il y en a un alors j'ajoute la limite à ce macro-noeud
        elseif (count($macroNodes)==1) {
          echo "Suppression de $lim, ajout au macro-noeud $macroNodes[0]\n";
          $lim->macroNode = $macroNodes[0];
          $lim->macroNode->add($lim);
        }
        // S'il y en a plusieurs alors je les fusionne
        else {
          echo "Suppression de $lim, fusion des macro-noeuds ",implode(',',$macroNodes),"\n";
          $lim->macroNode = array_shift($macroNodes);
          $lim->macroNode->add($lim);
          foreach ($macroNodes as $macroNode)
            $lim->macroNode->merge($macroNode);
        }
      }
    }
    return $nbLimDeleted;
  }
  
  // affiche toutes les limites
  static function showAll() {
    foreach (self::$all as $limid => $lim) {
      echo "$limid:\n";
      $lim->show('  ');
    }
  }
  
  // initialisation d'une limite, les faces et noeuds à null
  function __construct($id, $prop, $geom) {
    $this->id = $id;
    $this->inv = new LimInv($this);
    $geom = Geometry::fromGeoJSON($geom);
    $this->bbox = $geom->bbox();
    $this->length = $geom->length();
    //$this->right = $prop['right'];
    $this->right = null;
    //$this->left = (isset($prop['left']) ? $prop['left'] : null);
    $this->left = null;
    $this->ninitial = null;
    $this->nfinal = null;
    $this->deleted = 0;
    $this->macroNode = null;
  }
  
  function __toString(): string { return $this->id; }
  function id(): string { return $this->id; }
  function lim(): Lim { return $this; }
  function bbox(): BBox { return $this->bbox; }
  function length(): float { return $this->length; }
  function deleted(): int { return $this->deleted; }
  function setMacroNode(MacroNode $mn): MacroNode { $this->macroNode = $mn; return $mn; }
  function macroNode(): ?MacroNode { return $this->macroNode; }
  
  // renvoie les limites adjacentes, cad les limites partageant un des noeuds
  function adjacentLims(): array {
    $adjacentLims = [];
    if (!$this->initial())
      throw new Exception("La limite $this->id n'a pas de noeud initial");
    foreach ($this->initial()->arrivings() as $blade)
      if ($blade->lim() !== $this)
        $adjacentLims[] = $blade->lim();
    if (!$this->final())
      throw new Exception("La limite $this->id n'a pas de noeud final");
    foreach ($this->final()->arrivings() as $blade)
      if ($blade->lim() !== $this)
        if (!$blade->lim()->inArray($adjacentLims))
          $adjacentLims[] = $blade->lim();
    return $adjacentLims;
  }
  
  function inArray(array $lims): bool {
    foreach ($lims as $lim)
      if ($this===$lim)
        return true;
    return false;
  }
  
  // affiche les caractéristiques de la limite
  function show(string $indent) {
    echo $indent,"right: $this->right\n";
    echo $indent,"left: ",$this->left(),"\n";
    echo $indent,"initial:\n";
    $this->initial()->show($indent.'  ');
    echo $indent,"final:\n";
    $this->final->show($indent.'  ');
  }
};

// La limite inverse
class LimInv extends Blade {
  //private $inv; // Limite
  function __construct(Lim $inv) { $this->inv = $inv; }
  function __toString(): string { return '- '.$this->inv->id(); }
  function inv(): Blade { return $this->inv; }
  function lim(): Lim { return $this->inv; }
};

// Face définie dans la carte topologique et correspondant à une commune
class Face {
  //static $all; // liste des faces [ id => Face ]
  private $id; // string
  private $nblim; // integer
  
  static function createMPol($id, $polygons) {
    foreach ($polygons as $no => $polygon) {
      if (count($polygon['rings'][0]) == 1) // ignore les iles
        continue;
      //self::$all["$id:$no"] = new Face("$id:$no", $polygon['rings']);
      new Face("$id:$no", $polygon['rings']);
    }
  }
  
  // initialisation à partir de l'anneau extérieur défini par une liste d'id de brin
  function __construct(string $id, array $rings) {
    $this->id = $id;
    $this->nblim = 0;
    $prec = null;
    foreach ($rings[0] as $bladeid) {
      //echo "bladeid = $bladeid\n";
      $blade = Lim::get($bladeid);
      $blade->setRight($this);
      $this->nblim++;
      if ($prec) {
        $blade->setNode($prec);
      }
      $prec = $blade;
    }
    $b0 = Lim::get($rings[0][0]);
    $b0->setNode($prec);
  }
  
  function __toString() { return $this->id; }
  function nblim() { return $this->nblim; }
  function decrNblim() { return $this->nblim--; }
};

// Noeud
class Node {
  private $arrivings; // liste des brins arrivant sur le noeud
  
  function __construct(array $arrivings) { $this->arrivings = $arrivings; }
  function arrivings(): array { return $this->arrivings; }
  
  // Ajout un brin arrivant au noeud
  function add(Blade $blade) { $this->arrivings[] = $blade; }
  
  // fusion de 2 noeuds, retourne le noeud fusionné
  function fusion(Node $n2): Node {
    foreach ($n2->arrivings as $b) {
      $b->setFinal($this);
      $this->arriving[] = $b;
    }
    return $this;
  }
  
  function show(string $indent) {
    foreach ($this->arrivings as $blade)
      echo $indent,$blade,"\n";
  }
  
  function inMacroNode(): ?MacroNode {
    foreach ($this->arriving as $blade)
      if ($mn = $blade->lim()->macroNode())
        return $mn;
    return null;
  }
};

// Un macro-noeud est un ensemble de limites contigues supprimées
// Chaque limite supprimée appartient à un et un seul macro-noeud
// Dans la carte généralisée, chaque macro-noeud est réduit à un point
class MacroNode {
  static $all; // ensemble des macro-noeuds: [ id => MacroNode ]
  private $content; // liste des id de limites constituant le macro noeud: [ LimId ]
  private $bbox; // BBox
  
  function __toString(): string { return $this->content[0]; }
  
  // création à partir d'un array de Lim
  function __construct(array $content) {
    $this->content = [];
    $this->bbox = new BBox;
    foreach ($content as $lim) {
      $this->content[] = $lim->id();
      $this->bbox->union($lim->bbox());
    }
    $lim = $content[0];
    self::$all[$lim->id()] = $this;
  }
  
  function add(Lim $lim) {
    $this->content[] = $lim->id();
    $this->bbox->union($lim->bbox());
  }
  
  function merge(MacroNode $mn2): MacroNode {
    unset(self::$all[$mn2->content[0]]);
    foreach ($mn2->content as $limId) {
      $lim = Lim::get($limId);
      $lim->setMacroNode($this);
      $this->content[] = $limId;
      $this->bbox->union($lim->bbox());
    }
    return $this;
  }
  
  function in_array(array $mnds) {
    foreach ($mnds as $mnd)
      if ($mnd===$this)
        return true;
    return false;
  }
  
  function point(): array {
    return [
      ($this->bbox->min()->x()+$this->bbox->max()->x())/2,
      ($this->bbox->min()->y()+$this->bbox->max()->y())/2
    ];
  }
  
  static function storeAll($collection): int {
    $collection->drop();
    foreach (self::$all as $mnd) {
      $mnd->store($collection);
    }
    return count(self::$all);
  }
  function store($collection) {
    $lims = [];
    foreach ($this->content as $limId)
      $lims[] = $limId;
    $collection->insertOne([
      '_id'=>$lims[0],
      'lims'=>$lims,
      'geometry'=>['type'=>'Point', 'coordinates'=>[$this->point()]],
    ]);
  }
};


if (0) { // Jeu test permettant de tester la construction du graphe
  Lim::create('L1', [], ['type'=>'LineString', 'coordinates'=> [[0,0],[10,0]]]);
  Lim::create('L2', [], ['type'=>'LineString', 'coordinates'=> [[0,0],[0,10]]]);
  Lim::create('L3', [], ['type'=>'LineString', 'coordinates'=> [[10,0],[0,10]]]);
  
  Face::createMPol('P1',[[['L2','- L3','- L1']]]);
  Lim::showAll();
  die("FIN ligne ".__LINE__."\n");
}

if (0) { // Jeu test permettant de tester Com::genGenMPol()
  $lims = [
    'L1'=> ['properties'=>[], 'geometry'=>['type'=>'LineString', 'coordinates'=> [[0,0],[10,0]]]],
    'L2'=> ['properties'=>[], 'geometry'=>['type'=>'LineString', 'coordinates'=> [[0,0],[0,10]]]],
    'L3'=> ['properties'=>[], 'geometry'=>['type'=>'LineString', 'coordinates'=> [[0,10],[10,10]]]],
    'L4'=> ['properties'=>[], 'geometry'=>['type'=>'LineString', 'coordinates'=> [[10,0],[10,10]]]],
    'L5'=> ['properties'=>[], 'geometry'=>['type'=>'LineString', 'coordinates'=> [[0,0],[5,4]]]],
    'L6'=> ['properties'=>[], 'geometry'=>['type'=>'LineString', 'coordinates'=> [[10,0],[5,4]]]],
    'L7'=> ['properties'=>[], 'geometry'=>['type'=>'LineString', 'coordinates'=> [[10,10],[5,6]]]],
    'L8'=> ['properties'=>[], 'geometry'=>['type'=>'LineString', 'coordinates'=> [[0,10],[5,6]]]],
    'L9'=> ['properties'=>[], 'geometry'=>['type'=>'LineString', 'coordinates'=> [[5,4],[5,6]]]],
  ];
  foreach ($lims as $id => $lim)
    Lim::create($id, $lim['properties'], $lim['geometry']);
  
  $pols = [
    'F1' => ['L5','- L6','- L1'],
    'F2' => ['L2','L8','- L9','- L5'],
    'F3' => ['L3','L7','- L8'],
    'F4' => ['- L4','L6','L9','- L7'],
  ];
  foreach ($pols as $id=> $pol)
    Face::createMPol($id, [[$pol]]);
  Lim::sortOnLength();
  //Lim::showAll();
  $nbLimDeleted = Lim::delLims(3);
  echo "$nbLimDeleted limites supprimées sur ",count(Lim::$all),"\n";
  FeatureEncode::start(0);
  foreach ($pols as $id=> $pol) {
    $geom = Com::genGenMPol($id, [[$pol]], $lims);
    FeatureEncode::feature(['properties'=>['code'=>$id], 'geometry'=>$geom]);
  }
  FeatureEncode::end();
  die("FIN ligne ".__LINE__."\n");
}

// Suppression des limites trop petites
if (1) {
  $codeinsees = [];
  //$codeinsees = ['2A','2B'];
  //$codeinsees = ['2A270','2A144','2A345','2A323'];
  //$codeinsees = ['971'];
  //$codeinsees = ['29'];
  //$codeinsees = ['06'];

  $i=0;
  // chargement des limites
  foreach($adminexp->c_lim->find([]) as $lim) {
    $lim = json_decode(json_encode($lim), true);
    if ($codeinsees) {
      $right = $lim['properties']['right'];
      $left = (isset($lim['properties']['left']) ? $lim['properties']['left'] : null);
      if (!in_array(substr($right,0,strlen($codeinsees[0])), $codeinsees)) {
        if (!$left or !in_array(substr($left,0,strlen($codeinsees[0])), $codeinsees)) {
          continue;
        }
      }
    }
    //echo "lim="; print_r($lim);
    Lim::create($lim['_id'], $lim['properties'], $lim['geometry']);
    //if (++$i >= 2) break;
  }
  printf("memory_get_usage=%.2fMo\n",memory_get_usage(true)/1024/1024);
  Lim::sortOnLength();

  //print_r(Lim::$all); die("FIN ligne ".__LINE__."\n\n");

  // Création des faces
  foreach($adminexp->c_g2_pol->find([]) as $mpol) {
    $mpol = json_decode(json_encode($mpol), true);
    //print_r($mpol);
    if ($codeinsees and !in_array(substr($mpol['_id'],0,strlen($codeinsees[0])), $codeinsees)) continue;
    //echo "mpol="; print_r($mpol);
    Face::createMPol($mpol['_id'], $mpol['polygons']);
    if (1)
    printf("memory_get_usage=%d=%.2f Mo / %s\n",
        memory_get_usage(true),
        memory_get_usage(true)/1024/1024,
        ini_get('memory_limit'));
    //if (++$i >= 2) die("FIN ligne ".__LINE__."\n\n");
  }
  //echo "Lims = "; print_r(Lim::$all); die("FIN ligne ".__LINE__."\n\n");
  //echo "Faces = "; print_r(Face::$all); die("FIN ligne ".__LINE__."\n\n");

  //Lim::showAll(); die("FIN ligne ".__LINE__."\n");
  
  $nbLimDeleted = Lim::delLims(DISTRESHOLD);
  echo "$nbLimDeleted limites supprimées sur ",count(Lim::$all),"\n";
  
  echo MacroNode::storeAll($adminexp->c_g2_mnd)," macro-noeuds enregistrés\n";
}

