# Onglet Mail — mise en service

Rattacher les boîtes Google Workspace à Arche. Une seule fois pour
l'organisation, puis chacun rattache la sienne depuis l'app.

Tout ce qui suit se passe dans la console Google Cloud et dans deux fichiers de
configuration. Le code est déjà en place.

---

## Pourquoi c'est possible ici

Les portées Gmail sont **restreintes** chez Google : une application publique
doit passer une vérification avec audit de sécurité annuel, facturé en milliers
de dollars.

Arche y échappe parce que son écran de consentement sera réglé sur **« Interne »**
— possible uniquement pour une organisation Workspace, et qui limite l'accès aux
comptes du domaine. C'est la seule raison pour laquelle ce chantier se compte en
jours et non en mois. **Ne pas passer l'écran en « Externe »** : la vérification
redeviendrait obligatoire du jour au lendemain, et les connexions cesseraient.

---

## 1. Projet et API

1. Console Google Cloud → créer un projet (ou réutiliser celui de l'organisation).
2. **APIs & Services → Library** → activer **Gmail API**.
3. Activer aussi **Cloud Pub/Sub API**.

## 2. Écran de consentement — *Google Auth Platform*

Google a réorganisé cette partie : ce qui s'appelait « OAuth consent screen »
est maintenant **Google Auth Platform**, et les réglages sont éclatés en
plusieurs onglets. La correspondance :

| Ce qu'il faut régler | Où c'est passé |
| --- | --- |
| Interne / Externe | **Audience** |
| Nom de l'app, adresse d'assistance | **Branding** |
| Portées | **Accès aux données** |
| Identifiants OAuth | **Clients** |

### Audience

Type d'utilisateur : **Internal**. Voir plus haut pourquoi ce réglage est celui
qui rend tout le reste possible.

### Branding

Nom de l'application : `Arche`. Adresse d'assistance et de contact : une adresse
du domaine.

### Accès aux données

Bouton **« Ajouter ou supprimer des niveaux d'accès »**, puis descendre jusqu'à
**« Ajouter manuellement des niveaux d'accès »** en bas du panneau, et coller :

```
https://www.googleapis.com/auth/gmail.modify
https://www.googleapis.com/auth/userinfo.email
```

Puis *Ajouter au tableau* → *Mettre à jour* → *Enregistrer*.

**Le passage par la saisie manuelle n'est pas un raccourci, c'est le seul
chemin** : les portées restreintes ne figurent pas dans la liste qu'on parcourt.
On peut chercher « gmail » longtemps sans rien trouver et croire que l'API n'est
pas activée.

`gmail.modify` couvre lecture, envoi, archivage et libellés, et met à la
corbeille sans jamais effacer définitivement. Ne pas demander
`https://mail.google.com/`, qui donnerait la suppression sans retour pour aucun
usage supplémentaire.

## 3. Trois identifiants OAuth

**Google Auth Platform → Clients → Créer un client**

(La même page reste accessible par *APIs & Services → Credentials*, sous
l'ancien nom.)

### a. Client « Web »

C'est **le seul qui a un secret**, et c'est lui qui échange le code
d'autorisation contre un jeton de rafraîchissement — y compris quand le code
vient d'un téléphone Android. Contre-intuitif, mais c'est ainsi que Google l'a
conçu.

Aucune URI de redirection n'est nécessaire : l'échange se fait de serveur à
serveur.

→ donne `GOOGLE_CLIENT_ID` et `GOOGLE_CLIENT_SECRET`, **et**
  `GOOGLE_SERVER_CLIENT_ID` côté app (même valeur que `GOOGLE_CLIENT_ID`).

### b. Client « Android »

- Nom du package : `com.paynala.arche`
- Empreinte SHA-1 du certificat de signature.

Pour la release :

```bash
keytool -list -v -keystore <votre-keystore.jks> -alias <alias> | grep SHA1
```

Pour le debug (indispensable pour tester avant publication) :

```bash
keytool -list -v -keystore ~/.android/debug.keystore \
  -alias androiddebugkey -storepass android -keypass android | grep SHA1
```

**Déclarer les deux empreintes.** C'est l'oubli le plus fréquent : la connexion
marche en debug puis échoue en release, avec une erreur qui ne mentionne nulle
part la signature.

Ce client n'a pas de secret et ne se copie nulle part — Google l'associe à
l'app par sa signature.

### c. Client « iOS »

- Bundle ID : `com.paynala.arche`

→ donne `GOOGLE_IOS_CLIENT_ID`, et le **reversed client ID** à mettre dans
  `ios/Flutter/Google.xcconfig` (voir `Google.xcconfig.example`) :
  `123-abc.apps.googleusercontent.com` devient
  `com.googleusercontent.apps.123-abc`.

## 4. Pub/Sub, pour les notifications

Sans cette section, l'onglet fonctionne — on lit et on écrit — mais aucune
notification n'arrive.

1. **Pub/Sub → Topics → Create topic**, par exemple `arche-gmail`.
   Le nom complet est `projects/<projet>/topics/arche-gmail`.

2. **Donner à Gmail le droit de publier.** Sur le sujet → *Permissions* →
   *Add principal* :
   - Principal : `gmail-api-push@system.gserviceaccount.com`
   - Rôle : **Pub/Sub Publisher**

   Sans cela, `users.watch()` échoue avec un message qui parle de permissions
   sans dire lesquelles.

3. **Créer une souscription push** sur ce sujet :
   - Delivery type : **Push**
   - Endpoint URL :
     `https://<votre-api>/api/mail/pubsub?token=<GOOGLE_PUBSUB_TOKEN>`
   - Acknowledgement deadline : 10 s suffit — le point d'entrée accuse
     réception immédiatement et fait le travail en file.

   Le jeton en paramètre d'URL est ce qui distingue un appel de Google de
   n'importe qui ayant trouvé l'adresse : ce point d'entrée est nécessairement
   hors authentification. Le générer avec `openssl rand -hex 32`.

   **L'adresse doit être en HTTPS et publiquement joignable.** Pub/Sub refuse
   les adresses IP nues et le HTTP simple.

---

## 5. Configuration

### Serveur — `api/.env`

```dotenv
GOOGLE_CLIENT_ID=<client Web>.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=<secret du client Web>
GOOGLE_WORKSPACE_DOMAIN=paynala.com
GOOGLE_PUBSUB_TOPIC=projects/<projet>/topics/arche-gmail
GOOGLE_PUBSUB_TOKEN=<la sortie de openssl rand -hex 32>
```

Puis appliquer la migration :

```bash
docker exec arche-api php artisan migrate
```

Elle crée `google_accounts` et ajoute `notify_mail` à `users`. Les migrations
sont gardées : celles qui décrivent des tables déjà présentes ne font rien.

### App — `mobile/env/dev.json`

```json
{
  "GOOGLE_SERVER_CLIENT_ID": "<le même que GOOGLE_CLIENT_ID>",
  "GOOGLE_IOS_CLIENT_ID": "<client iOS>.apps.googleusercontent.com",
  "GOOGLE_WORKSPACE_DOMAIN": "paynala.com"
}
```

### iOS — `mobile/ios/Flutter/Google.xcconfig`

Copier `Google.xcconfig.example` et y mettre le reversed client ID. Le fichier
n'est pas versionné.

---

## Vérifier que ça marche

1. Ouvrir **Messages → Mail** dans l'app. L'écran explique ce qu'Arche va
   pouvoir faire ; si à la place il annonce « Courrier non configuré », il
   **nomme le côté qui manque** — serveur ou app.
2. Rattacher. Un compte hors du domaine est refusé, c'est voulu.
3. **Profil → Courrier** doit afficher l'adresse et la date jusqu'à laquelle la
   veille est active.
4. S'envoyer un message depuis un autre compte : la notification doit arriver.

Si la boîte se lit mais que rien n'arrive, l'onglet Mail affiche un bandeau
« notifications arrêtées » et **Profil → Courrier** donne le motif exact remonté
par Google. C'est presque toujours la section 4 qui est incomplète — le plus
souvent le droit de publication de `gmail-api-push@system.gserviceaccount.com`.

---

## Ce que le serveur détient, et ce qu'il ne voit pas

Il détient **un jeton de rafraîchissement par personne**, c'est-à-dire un accès
permanent à sa boîte. C'est la donnée la plus sensible de l'installation :
chiffrée au repos avec l'`APP_KEY`, jamais rendue par l'API.

Il ne détient **aucun courrier**. L'app parle à Gmail directement pour lire,
répondre et envoyer. Le serveur ne demande que l'expéditeur et l'objet d'un
message qui vient d'arriver, le temps d'écrire un bandeau de notification, et ne
les conserve pas.

Se débrancher depuis **Profil → Courrier** arrête la surveillance, révoque
l'autorisation dans le compte Google et efface le jeton.

## Rotation d'`APP_KEY`

Changer l'`APP_KEY` de Laravel rend **tous les jetons illisibles** : les
surveillances s'arrêteront et chacun devra rattacher sa boîte à nouveau. Rien
n'est perdu — aucun courrier n'est stocké — mais c'est à savoir avant, pas après.
