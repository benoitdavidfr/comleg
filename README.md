# comleg

Fichier des polygones généralisés des communes françaises  
  
Données dérivées d'AdminExpress (voir source) générées les 2017-11-24 et 2017-11-26. Version béta.  
Simplification des limites entre communes par l'algo de Douglas &amp; Peucker avec un seuil de 1 km.  
Suppression des limites inférieures à 2 km et de certaines petites îles.  
Chaque commune est décrite par au moins 1 polygone de surface non nulle.  
Certains polygones peuvent être dégénérés par l'algorithme de simplification.  

Le fichier SVG est restreint aux communes de métropole.  
Transformation en coord. entières en centièmes de degrés dans la boite X: -5.25 -> 9.57, Y: 41.36 -> 51.10  
puis division des latitudes par le cosinus de la latitude moyenne.  
  
Le fichier GeoJSON contient toutes communes de métrople et des DOM.

Fichiers générés les 2017-11-24  et 2017-11-26  
  
Source: AdminExpress du 16/10/2017 (http://professionnels.ign.fr/adminexpress)  

