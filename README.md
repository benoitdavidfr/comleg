# comleg - communes légères

Fichier des polygones généralisés des communes françaises  
  
Données dérivées d'AdminExpress (voir source) générées le 2017-12-13. Version béta.  
Simplification des communes correspondant à plusieurs polygones (suppression notamment des petites iles).  
Simplification des limites entre communes par l'algo de Douglas &amp; Peucker avec un seuil de 0,01 degré (soit environ 1 km).  
Suppression des limites de longueur inférieure à 0,02 degré (environ 2 km).  
Chaque commune est décrite par au moins 1 polygone de surface non nulle.  
Certains polygones peuvent être dégénérés par l'algorithme de simplification.  

Le fichier SVG est restreint aux communes de métropole.  
Transformation en coord. entières en centièmes de degrés dans la boite X: -5.25 -> 9.57, Y: 41.36 -> 51.10  
puis division des latitudes par le cosinus de la latitude moyenne.  
Il pèse moins de 3 Mo non compressé et moins de 700 Ko compressé.  
  
Le fichier GeoJSON contient toutes communes de métrople et des DOM.
Il pèse 

Fichiers générés le 2017-12-13.  
  
Source: AdminExpress du 15/11/2017 (http://professionnels.ign.fr/adminexpress)  

Des imperfections existent encore dans ce jeu de données expérimental.  
Je suis intéressé à connaitre les utilisations de ce fichier.  