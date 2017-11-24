# comleg

Fichier des polygones généralisés des communes françaises  
  
Données dérivées d'AdminExpress (voir source) générées le 2017-11-24. Version béta.  
Simplification des limites entre communes par l'algo de Douglas &amp; Peucker avec un seuil de 1 km.  
Suppression des limites inférieures à 2 km et de certaines petites îles.  
Chaque commune est décrite par au moins 1 polygone de surface non nulle.  
Certains polygones peuvent être dégénérés par l'algorithme de simplification.  
Restriction aux communes de métropole.  
Transformation en coord. entières en centièmes de degrés dans la boite X: -5.25 -> 9.57, Y: 41.36 -> 51.10  
puis division des latitudes par le cosinus de la latitude moyenne.  
  
Fichier généré le 2017-11-24  
  
Source: AdminExpress du 16/10/2017 (http://professionnels.ign.fr/adminexpress)  

