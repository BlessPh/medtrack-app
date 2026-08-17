# Notifications institutionnelles

Les notifications sont persistées en base avant leur diffusion temps réel. Le Front-end écoute exclusivement le canal privé `users.{public_id}`, autorisé par le guard JWT `api`.

## Cas déclencheurs

| Événement | Destinataires | Niveau | Temps réel |
|---|---|---|---|
| Ajout à une institution | Membre ajouté | Succès | Oui |
| Modification du rôle | Membre concerné | Information | Oui |
| Retrait de l’institution | Membre concerné | Avertissement | Oui |
| Annonce institutionnelle | Tous les membres actifs | Choisi par l’administrateur | Oui |
| Admission à un stage | Étudiant concerné | Succès | Oui |
| Approbation ou refus institutionnel | Demandeur | Information ou avertissement | À conserver et diffuser |
| Changement de planning ou rotation | Étudiants et superviseurs concernés | Information ou avertissement | À brancher avec le module Planning |
| Évaluation publiée ou contestée | Étudiant et évaluateurs concernés | Information | À brancher avec le module Évaluation |
| Paiement confirmé, échoué ou remboursé | Payeur et gestionnaires autorisés | Succès, avertissement ou critique | À brancher avec le module Finance |

## Événements volontairement silencieux

Les modifications ordinaires d’adresse, de contact, de description, de département, de service ou de programme ne déclenchent pas de notification. Elles produiraient du bruit sans nécessiter une action immédiate du destinataire.

## Exploitation

- Lancer Reverb avec `php artisan reverb:start`.
- Lancer un worker avec `php artisan queue:work` lorsque les broadcasts sont mis en file.
- En production, exécuter Reverb et le worker sous un gestionnaire de processus.
- Limiter `REVERB_ALLOWED_ORIGINS` aux domaines Front-end autorisés.
- Ne jamais utiliser un canal public pour des données utilisateur ou institutionnelles.
