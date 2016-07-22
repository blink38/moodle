# moodle-lise 

Plugins d'inscriptions Moodle qui permet de synchroniser les groupes provenant de l'application Lise de scolarité du système d'information des Arts et Métiers dans les cohortes moodle.

Les groupes sont récupérés depuis l'application Lise via une vue sur une base de données postgreSQL. 

Les cohortes sont alors créés depuis les données postgreSQL ainsi que les comptes utilisateurs qui n'existent pas dans Moodle, afin de pouvoir ensuite faire l'association entre les utilisateurs et les cohortes. 

La création des utilisateurs est simple, la gestion de l'authentification étant faite à travers un service SSO basé sur CAS Jasig.


Ce module a été écrit en se basant sur le module déjà existant enrol_database.

