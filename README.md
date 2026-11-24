## 1.2.5
- Menu Rapport des garanties sous Outils, visible dans l’interface standard.
- Contrôle de lecture des ordinateurs conservé dans front/report.php.

## 1.2.4
- Corrige le rendu du tableau de rapport : remplace un appel non compatible `Html::getRootURL()` par l’URL racine GLPI configurée (`$CFG_GLPI['root_doc']`).
- Conserve le regroupement par entité, les filtres et les exports.

## 1.2.3
- Filtre par entité autorisée, option toutes les entités autorisées.
- Tri par entité puis nom du matériel, en-têtes de groupe dans le rapport.
- Respect strict des entités actives et autorisées dans GLPI.

## v1.2.2 — Correction du menu GLPI

Le menu est enregistré indépendamment de la session courante. La classe de menu et report.php contrôlent le droit `computer` READ. Après mise à jour, déconnectez/reconnectez les utilisateurs.

# Fujitsu Warranty v1.1.1 — GLPI 10

Créateur : **Charly TISSOT PROXIMIWEB** — GPLv3+

## Pagination
- Affiche « Page X sur Y » et le nombre total de matériels correspondant aux filtres.
- Boutons Première, Précédente, numéros de page, Suivante et Dernière.
- Les filtres sont conservés dans tous les liens ; les pages hors plage sont ramenées à la dernière page disponible.

## Nouveautés
- Filtre garantie : toutes / sans garantie renseignée (aucune date et aucune durée renseignées dans les informations financières GLPI).
- Filtre nom : tous / HYPERV* / SRV* / HYPERV* ou SRV*.
- Filtres combinables, conservés pendant la pagination et l’envoi du formulaire.
- Tout cocher/décocher sur la page affichée (50 maximum). **La synchronisation traite au maximum 20 matériels par action** ; un message prévient si plus de 20 sont cochés.
- Le bouton de synchronisation par lot respecte les filtres actifs.

## Fonctions conservées
- Simulation par défaut ; écriture seulement avec autorisation et confirmation.
- Garanties existantes conservées sauf option d’écrasement.
- Résultats affichés dans un tableau Bootstrap avec `thead` et `tbody`.
- Affichage de la date Fujitsu, de la date GLPI calculée et de l’écart.

## Installation
Décompresser l’archive à la racine `plugins/` de GLPI, remplacer le dossier `fujitsuwarranty`, vérifier les permissions et actualiser Configuration > Plugins.

## Attention
Le filtre « sans garantie » porte sur les informations financières de GLPI, et non sur l’état de garantie en temps réel chez Fujitsu. Les garanties expirées mais renseignées ne sont pas considérées « sans garantie ».
GLPI stocke la durée en mois entiers : la date de fin calculée peut différer de celle du constructeur.
Tester d’abord en simulation sur la préproduction.


## Version 1.2.0 — Rapport des garanties

Nouveau menu **Parc > Rapport des garanties** pour les profils disposant du droit de lecture des ordinateurs, et accès direct `/plugins/fujitsuwarranty/front/report.php`.

- Rapports toutes marques (les synchronisations Fujitsu restent inchangées).
- Filtres : hors garantie, expiration sous 90 jours, sans information, valide, tous ; postes/serveurs ; entités autorisées/entité courante.
- Serveurs : type GLPI contenant `serveur`/`server` ou nom commençant par `HYPERV`/`SRV`. Ajuster la convention selon le parc.
- Pagination 50 éléments, export CSV et XLSX jusqu'à 10 000 éléments (XLSX nécessite PHP zip).
- Dates de fin calculées avec `DATE_ADD(date_debut, INTERVAL duree MONTH)` ; garantie inconnue si date ou durée absente.
- Exports en lecture seule ; filtrage des entités par `Session::getActiveEntities()`.
- Aucun changement de schéma SQL.

**Important :** tester sur GLPI 10 en préproduction, notamment le menu et les droits des profils. Le rapport repose sur les dates/durées enregistrées dans les informations financières GLPI.


## v1.2.2
Correction du rapport : suppression de Session::getActiveEntities() et utilisation des entités de session GLPI 10.
