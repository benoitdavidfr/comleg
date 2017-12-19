# comleg - communes légères

Fichier des polygones généralisés des communes françaises  
  
Données dérivées d'AdminExpress (voir source) générées le 2017-12-18. Version béta.  
Simplification des communes correspondant à plusieurs polygones (suppression notamment des petites iles).  
Simplification des limites entre communes par l'algo de Douglas &amp; Peucker avec un seuil de 0,05 degré (soit environ 5 km).  
Suppression des limites de longueur inférieure à 0,02 degré (environ 2 km).  
Chaque commune est décrite par au moins 1 polygone de surface non nulle.  
Certains polygones peuvent être dégénérés par l'algorithme de simplification.  
Certaines limites de communes sont décrites plus finement pour minimiser les difficultés.  

Le fichier SVG est restreint aux communes de métropole.  
Transformation en coord. entières en centièmes de degrés dans la boite X: -5.25 -> 9.57, Y: 41.36 -> 51.10  
puis division des latitudes par le cosinus de la latitude moyenne.  
Il pèse moins de 3 Mo non compressé et moins de 700 Ko compressé.  
  
Le fichier GeoJSON contient toutes communes de métrople et des DOM.
Il pèse moins de 7 Mo non compressé et moins de 900 Ko compressé.

Des fichiers GeoJSON par région (+ 1 pour les 5 DOM) sont proposés dans le repository
[geojson](https://github.com/benoitdavidfr/geojson).
Ils peuvent être directement affichés dans GitHub.    

Fichiers générés le 2017-12-18.  
  
Source: AdminExpress du 15/11/2017 (http://professionnels.ign.fr/adminexpress)  

Des imperfections existent encore dans ce jeu de données expérimental.  
L'algorithme utilisé pour produire ces données est décrit dans comment.md  
Je suis intéressé à connaitre les utilisations de ce fichier.  