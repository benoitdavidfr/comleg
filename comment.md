# Comment les données sont-elles produites ?

7 étapes :

## 0) Générer un fichier GeoJSON à partir des données IGN
ogr2ogr est utilisé pour générer un fichier GeoJSON pour la métropole et un par DOM.    
Les coordonnées sont converties en coordonnées géographiques.    

## 1) Fabriquer des limites mitoyennes
Fonctionne en 2 phases:
1. découpage des contours des communes en segments de droite
2. structuration de ces segments sous la forme de limites ayant une commune à droite et à gauche soit une autre
   soit aucune commune ; à chaque limite est associée une liste de points
   
Suppression des communes de Paris, Lyon et Marseille redondantes avec leurs arrondissements.

## 2) Redéfinir les communes à partir de ces limites
- lecture des limites et affectation de chacune à sa commune droite et éventuellement sa commune gauche    
  on définit la notion de brin qui est soit une limite soit une limite prise en sens inverse
- organisation des limites en anneaux (rings) définis comme une liste de brins pour lesquels:
  - le dernier point d'un brin est identique au premier du brin suivant
  - le dernier point du dernier brin est identique au premier point du premier brin
- en testant l'inclusion géométrique des anneaux les uns dans les autres,
  chaque commune est structurée comme un ensemble de faces, chaque face correspond à un anneau extérieur plus
  d'éventuels anneaux formant des trous

Gestion du cas particulier des communes 14756 et 14174 qui présentent une configuration spécifique.

## 3) Simplifier les communes
Les communes correspondant à plusieurs faces sont rammenées à une seule.
2 cas sont distingués:
- lorqu'une commune comporte des îles soit dans la mer soit dans une autre commune, ces îles sont supprimées ;
  seule la face la plus grande est conservée
- lorqu'une commune comporte plusieurs faces autres que des iles, seule la plus grande est conservée,
  les plus petites sont fusionnées dans une de leurs voisines

## 4) Supprimer les petites limites
Les limites d'une longueur inférieure à un certain seuil (0,02 degré) sont supprimées si les faces qu'elles
délimitent comporte plus de 3 limites. Ceci est effectué par longueur croissante.

## 5) Simplifier la géométrie des limites
Cette étape consiste à réduire le nombre de points des limites et à arrondir leurs coordonnées en centièmes de degrés.
La géométrie des limites est simplifiée par l'algorithe de Douglas & Peucker avec un seuil de 0,01 degré.
Les coordonnées des points sont ramenées en centièmes de degrés.
On calcule alors la surface de chaque face. Si elle est inférieure à un certain seuil (1e-5) le calcul
est effectué à nouveau en prenant comme seuil divisé par 10.

## 6) Générer un fichier GeoJSON ou SVG
Les communes sont exportées dans un fichier GeoJSON ou SVG.

Le fichier SVG est restreint aux communes de métropole.  
Transformation en coord. dans la boite X: -5.25 -> 9.57, Y: 41.36 -> 51.10  
puis division des latitudes par le cosinus de la latitude moyenne.  
Le fichier pèse moins de 3 Mo non compressé et moins de 700 Ko compressé.  

Le fichier GeoJSON contient toutes communes de métrople et des DOM.
Il pèse moins de 7 Mo non compressé et moins de 800 Ko compressé.
