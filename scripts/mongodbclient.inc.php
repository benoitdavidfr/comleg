<?php
/*PhpDoc:
name: mongodbclient.inc.php
title: mongodbclient.inc.php - définition du client MongoDB
doc: |
  Objectif: rendre plus indépendant les scripts de l'URI de MongoDB
*/
require_once __DIR__.'/../../../vendor/autoload.php';

function mongoDbClient() {
  return new MongoDB\Client('mongodb://172.17.0.2:27017');
}