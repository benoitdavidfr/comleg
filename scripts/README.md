# Scripts Php de Simplifae

L'objectif de Simplifae est de générer des fichiers SVG et GeoJSON les plus légers possibles.
Le process enchaine plusieurs scripts utilisant un stockage intermédiaire dans MongoDB.
Les scripts sont les suivants :
1. mklimdb.php lit les fichiers SHP d'origine, génère un GeoJSON et fabrique une collection c_lim
   des limites entre communes
2. mkpol.php reconstruit chaque commune en s'appuyant sur les limites et peuple une collection c_pol
3. simplif.php effectue une simplification topologique avant d'effectuer la simplification géométrique ;
   chaque commune est réduite autant que possible à un seul polygone ;
   la collection c_g2_pol est peuplée avec une description de chaque commune sur les limites de c_lim
4. dellim.php supprime les limites les plus petites et crée à la place des macro-noeuds ;
   un macro-noeud:
    - est un sous-graphe du graphe initial qui sera géométriquement représenté par un point
    - est défini par un ensemble de limites du graphe initial
    - peut être identifié par l'id d'une de ses limites    
   
   Les macro-noeuds sont stockés dans la collection c_g2_mnd    
   Ils peuvent être exportées en GeoJSON par exportmnd.php    
5. mkglim.php simplifie géométriquement les limites et simule la reconstruction des polygones pour s'assurer
   qu'aucun polygone n'a une surface nulle et
   que les segments définissant le contour d'un polygone ne s'intersectent pas.
   Quand c'est le cas la résolution est augmentée.
   Les nouvelles limites sont stockées dans c_g2_lim
6. genpol.php génère les fichiers SVG et GeoJSON.


