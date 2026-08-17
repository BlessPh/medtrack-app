# Import Excel des étudiants

## Format accepté

Fichiers `XLSX` ou `CSV`, 10 Mio et 1 000 lignes maximum. La première ligne contient les en-têtes.

Colonnes obligatoires : `Matricule`, `Nom`, `Post-nom`, `Prénom`, `Sexe`, `Date de naissance` (`AAAA-MM-JJ`). Les colonnes `Email` et `Téléphone` sont facultatives. Les formules ne sont jamais évaluées.

## 1. Prévisualisation

Avant l'upload, appeler `GET /api/v1/academic/current-context?university_id={UUID}`. La réponse fournit automatiquement l'année académique active, les programmes et les promotions compatibles. Le front-end réutilise ensuite `academic_year.id` et la promotion choisie dans la requête de prévisualisation.

`POST /api/v1/academic/student-imports/preview` en `multipart/form-data`, avec un jeton JWT Bearer :

- `university_id` : UUID public ;
- `promotion_id` : identifiant de promotion ;
- `academic_year_id` : identifiant d'année académique ;
- `file` : fichier XLSX ou CSV.

La réponse contient `rows`. Chaque ligne fournit `row_number`, `selected`, `valid`, `student` et `errors`. Les matricules déjà présents ou dupliqués dans le fichier sont signalés dès cette étape. Aucun fichier et aucun étudiant ne sont conservés.

## 2. Confirmation

`POST /api/v1/academic/student-imports/confirm` reçoit le même contexte et `students`, le tableau des lignes retenues/corrigées. L'ensemble est validé puis enregistré dans une seule transaction. Une erreur annule tout le lot.

## 3. Vérification du matricule

`POST /api/v1/auth/student-registration/check` reçoit `university_id`, `promotion_id`, `academic_year_id` et `student_number`. Une correspondance active et sans compte retourne un `registration_token` chiffré valable 15 minutes.

## 4. Création du compte

`POST /api/v1/auth/student-registration` reçoit le jeton, `email`, `password`, `password_confirmation` et les informations de profil facultatives. Le dossier étudiant est verrouillé pendant la création : un matricule ne peut donc servir qu'une fois, même en cas de requêtes simultanées.
