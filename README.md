# comleg

Fichier des polygones généralisés des communes françaises  
  
Données dérivées d'AdminExpress (voir source) générées le 2017-12-13. Version béta.  
Simplification des communes correspondant à plusieurs polygones.  
Simplification des limites entre communes par l'algo de Douglas &amp; Peucker avec un seuil de 0,01 degré.  
Suppression des limites de longueur inférieure à 0,02 degré et de certaines petites îles.  
Chaque commune est décrite par au moins 1 polygone de surface non nulle.  
Certains polygones peuvent être dégénérés par l'algorithme de simplification.  

Le fichier SVG est restreint aux communes de métropole.  
Transformation en coord. entières en centièmes de degrés dans la boite X: -5.25 -> 9.57, Y: 41.36 -> 51.10  
puis division des latitudes par le cosinus de la latitude moyenne.  
  
Le fichier GeoJSON contient toutes communes de métrople et des DOM.

Fichiers générés le 2017-12-13.  
  
Source: AdminExpress du 15/11/2017 (http://professionnels.ign.fr/adminexpress)  

