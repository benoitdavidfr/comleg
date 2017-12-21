# Simplifae - Simplification d'ADMIN EXPRESS

ADMIN-EXPRESS est une base de données de l'IGN qui décrit le découpage administratif français,
notamment celui des communes.
Elle est mise à jour chaque mois.
Voir http://professionnels.ign.fr/adminexpress.    

La géométrie d'ADMIN EXPRESS correspond à une résolution de l'ordre de 10 m ce qui conduit à des fichiers
relativement volumineux pour décrire la France entière.

L'objectif de Simplifae est de générer des fichiers SVG et GeoJSON les plus légers possibles tout en conservant :
- au moins un polygone de surface non nulle par commune,
- une partition de l'espace par les communes (cad que l'intérieur des communes ne s'intersectent pas).

Le processus de simplification enchaine plusieurs scripts utilisant un stockage intermédiaire dans MongoDB.
Ces scripts sont dans le [répertoire scripts](https://github.com/benoitdavidfr/simplifae2/tree/master/scripts).  

Le résultat de la simplification sous la forme de différents fichiers est dans le
[répertoire output](https://github.com/benoitdavidfr/simplifae2/tree/master/output).
