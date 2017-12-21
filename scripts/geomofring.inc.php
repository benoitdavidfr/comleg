<?php
/*PhpDoc:
name: geomofring.inc.php
title: geomofring.inc.php - reconstitue la géométrie d'un anneau à partir des limites
doc : |
  Reconstitue la géométrie d'un anneau à partir des limites
  Les géométries sont définies comme des [[ num, num ]]
journal: |
  18/12/2017:
    suppression de la propriété nbdigits
  13/12/2017:
    gestion d'erreurs par lancement d'exception
  9-10/12/2017:
    première version
*/
// classe gérant les Macro-Noeuds
class MacroNode {
  static $lims = []; // liste des id de limites appartenant à un macro-noeud : [ limId => MacroNode ]
  private $coords; // coordonnées du macro-noeud : [ float, float ]
  
  function __construct(array $mnd) {
    foreach ($mnd['lims'] as $limId)
      self::$lims[$limId] = $this;
    $this->coords = $mnd['geometry']['coordinates'][0];
  }
  
  // obtenir le macro-noeud auquel une limite appartient ou null
  static function macroNodeOfLimId(string $limId): ?MacroNode {
    return isset(self::$lims[$limId]) ? self::$lims[$limId] : null;
  }
  
  // obtenir le macro-noeud auquel un noeud appartient ou null
  static function macroNodeOfNode(Node $node): ?MacroNode {
    foreach ($node->arrivings() as $blade) {
      $limId = $blade->lim()->id();
      if (isset(self::$lims[$limId]))
        return self::$lims[$limId];
    }
    return null;
  }
  
  function coords() { return [round($this->coords[0], 2), round($this->coords[1], 2)]; }
};

function coordsOfNode(Node $node, $c_g2_lim): array {
  if ($mnd = MacroNode::macroNodeOfNode($node))
    return $mnd->coords();
  $bid = $node->blade()->id();
  $coords = coordsOfBlade($bid, $c_g2_lim);
  return $coords[count($coords)-1];
}

// renvoie la liste de points correspondant au brin
function coordsOfBlade(string $bid, $c_g2_lim): array {
  $limId = (substr($bid,0,1)=='-') ? substr($bid,1) : $bid;
  $lim = $c_g2_lim->findOne(['_id'=>$limId]);
  if (!$lim)
    throw new Exception("limite $limId non trouvée dans c_g2_lim");
  $lim = json_decode(json_encode($lim), true);
  $coords = $lim['geometry']['coordinates'];
  if (substr($bid,0,1)=='-')
    $coords = array_reverse($coords);
  //echo "coordsOfBlade($bid): "; print_r($coords);
  return $coords;
}

// renvoie la géométrie d'un anneau tenant compte des macro-noeuds sous la forme d'une LineString
// génère une exception en cas d'erreur
function geomOfRing(string $fid, array $ring, $c_g2_lim): LineString {
  $coords = []; // [ [ num, num ] ]
  try {
    foreach ($ring as $bid) {
      //echo "bid=$bid\n";
      $limId = (substr($bid,0,1)=='-') ? substr($bid,1) : $bid;
      if ($mnd = MacroNode::macroNodeOfLimId($limId)) {
        //echo "La limite $limId appartient à un macro-noeud\n";
        $coords[] = $mnd->coords();
      }
      else {
        $blade = Lim::get($bid);
        $initial = $blade->initial();
        //echo "initial: $initial\n";
        $coordsOfNode = coordsOfNode($initial, $c_g2_lim);
        if (!$coords or ($coordsOfNode <> $coords[count($coords)-1]))
          $coords[] = $coordsOfNode;
        // liste des points intermédiaires correspondant au brin c'est à dire sans les premier et dernier points
        $coordsOfBlade = array_slice(coordsOfBlade($bid, $c_g2_lim), 1, -1);
        if ($coordsOfBlade)
          $coords = array_merge($coords, $coordsOfBlade);
      }
    }
  } catch (Exception $e) {
    $stderr = fopen('php://stderr', 'w');
    fprintf($stderr, "Erreur: %s pour $fid\n", $e->getMessage());
    fprintf($stderr, "ring: %s\n",implode(',',$ring));
    throw new Exception($e->getMessage());
  }
  if ($coords[count($coords)-1] <> $coords[0])
    $coords[] = $coords[0];
  return new LineString($coords);
}
