# Les règles d'erreur de Paynala, et leurs sondes

Relevé dans `paynala-dashboard` (backend et composants d'affichage), pas
d'après ce qu'on suppose du métier.

**Les cinq requêtes plus bas se collent telles quelles** dans le champ
« Requête » d'une sonde Arche. Rien à substituer, rien à compléter. Elles sont
longues parce que les jointures y sont recopiées en entier : c'est le prix
d'un copier-coller qui marche.

Les expressions reprises du projet — le bloc de jointures et le `case` qui
décide du statut — sont **identiques au caractère près** à celles de
`filters.js`, vérifié par comparaison automatique. C'est délibéré : le jour où
quelqu'un se demandera si la sonde compte bien la même chose que le tableau de
bord, il pourra mettre les deux textes côte à côte. Une reformulation, même
juste, lui aurait coûté une demi-journée de doute.

Les cinq requêtes ont été analysées par la grammaire PostgreSQL réelle
(`libpg_query`). Cela garantit qu'elles sont syntaxiquement correctes ; cela ne
garantit pas qu'elles comptent ce que tu attends sur ta base. Le bouton
**Essayer** est là pour ça, et la section « Avant d'enregistrer » dit quoi
regarder.

---

## Les règles

### 1. Trois états, jamais deux

`SUCCESS`, `FAILED`, `MISSING`. **Un log absent n'est pas un échec.** Ce n'est
pas une commodité d'affichage : `failedBeneficiaries.js` l'écrit noir sur blanc
(« never "log absent", only a log that came back unsuccessful ») et
`walletFailures.js` ne compte que `= 'FAILED'`.

Les deux appellent des gestes différents. Un `FAILED` dit qu'Airtel a répondu
non — solde insuffisant, numéro invalide. Un `MISSING` dit que la
réconciliation n'a jamais été journalisée : un maillon de la chaîne s'est tu.
Les additionner rendrait la panne d'infrastructure invisible derrière le bruit
des refus ordinaires.

D'où **deux sondes séparées** (1 et 2), jamais une.

### 2. L'autorité est le drapeau du log, pas le code HTTP

```sql
response->'status'->>'success' = 'true'
```

`http_code` est fréquemment `NULL` **même sur des succès authentiques**, sur
les canaux API et RECOVERY — constaté sur les données réelles. Une sonde qui
compterait `http_code >= 400` raterait une partie des échecs et en inventerait
d'autres.

### 3. Quatre canaux seulement se réconcilient

`WEB`, `USSD`, `API`, `RECOVERY`.

`webview` et `backend_cron` **ne portent jamais** de jambe CP/MC — ce n'est pas
un défaut, ces flux ne journalisent simplement pas la réconciliation. Les
inclure produirait un torrent de `MISSING` permanent, et une sonde qui hurle en
continu ne se lit plus.

### 4. On ne réconcilie que ce qui a réussi

`p.status = 'SUCCESS'`. Un paiement en échec n'a pas de jambe à attendre :
la compter manquante n'aurait pas de sens.

### 5. Une ligne par jambe, pas par paiement

Un même paiement peut voir MC1 **et** MC2 échouer. Compter les paiements
sous-estimerait l'incident d'un facteur deux — et c'est ce qui dicte l'unité de
la sonde 1.

### 6. Le suffixe dépend du canal, le nombre de jambes du marchand

| | CP | deuxième | troisième |
|---|---|---|---|
| `USSD` | `airtel_money_id \|\| 'CP'` | `…'MC1'` ou `…'MC'` | `…'MC2'` |
| WEB / API / RECOVERY | `request_id \|\| '_CP'` | `…'_MC1'` ou `…'_MC'` | `…'_MC2'` |

**Rengus Digital** a trois wallets (`_MC1` / `_MC2`), tout le monde en a deux
(`_MC` sans chiffre). Reconnu par le nom du marchand et non par son
identifiant — délibéré, pour survivre à un changement d'environnement.

> **Le piège de MC2.** La jointure `mc2` porte déjà
> `where m.name = 'Rengus Digital'` : pour tout autre marchand elle ne matche
> jamais, donc `MISSING`. Sans effet quand on compte les `FAILED` (sonde 1) —
> mais la sonde 2, qui compte justement les `MISSING`, doit répéter ce filtre
> dans son `count`, sinon elle compte **chaque paiement de tous les autres
> marchands**. C'est l'erreur la plus facile à commettre ici, et elle
> passerait inaperçue : le chiffre serait simplement gros.

### 7. Deux formes de raison d'échec, deux gestes différents

| Forme | JSON | Exemple |
|---|---|---|
| Infrastructure | `response->>'error'` + `message` | `Proxy request failed — socket hang up` |
| Métier | `response->'status'->>'message'` | `Solde insuffisant` |

« Solde insuffisant » est le métier qui fonctionne. « socket hang up » est
Airtel injoignable. Les mélanger noierait la panne dans le bruit — d'où la
sonde 3, avec des paliers bien plus bas.

### 8. La raison d'un paiement échoué vient d'ailleurs

Pas de `response->'status'`, mais de
`response->'data'->'transaction'->>'message'`, filtré sur `status = 'TF'`.

### 9. `request.pin` ne sort jamais de la base

Interdiction explicite dans `failedBeneficiaries.js`. Les sondes ne rendent que
des nombres, donc la question ne se pose pas — elle se posera au premier qui
voudra une sonde qui remonte un détail.

### 10. Ce que le projet établit pour l'affichage

- `--status-good` / `--status-critical` sont réservées à Succès / Échec et
  **jamais réutilisées pour une série de graphique**.
- `--status-warning` est la couleur de `MISSING` — ni bonne, ni critique.
- La raison n'apparaît que sous une jambe non réussie, en petit et en gris :
  elle explique, elle n'alerte pas.
- Un `MISSING` affiche « Log Airtel absent » plutôt que rien : un vide se lit
  comme un bug de l'écran, pas comme un fait.

---

## Le choix des unités

L'unité n'est pas une étiquette de colonne. Elle est lue **dans le corps de la
notification**, qu'Arche compose ainsi :

```
{valeur} {unité} sur {fenêtre} h (palier {palier}).
```

Elle est donc lue sur un écran verrouillé, la nuit, par quelqu'un qui n'a pas
le contexte sous les yeux. Trois exigences en découlent, et chaque unité
ci-dessous est choisie pour y répondre :

**Elle doit faire une phrase.** « 12 jambes sur 24 h » ne se lit pas ; « 12
réconciliations en échec sur 24 h » se lit. C'est pourquoi les unités portent
l'état (« en échec », « sans log ») et pas seulement l'objet.

**Elle doit distinguer les sondes entre elles.** Quatre des cinq comptent des
choses qui ratent. « échecs » tout court laisserait le lecteur incapable de
dire si la sonde 1 ou la sonde 4 vient de sonner — et ces deux-là n'appellent
pas le même appel téléphonique.

**Elle doit nommer ce qu'on compte, jamais d'où ça vient.** « lignes
d'airtel_logs » serait exact et inutilisable : personne n'agit sur une ligne de
table. « réconciliations » désigne l'objet métier, celui dont on peut dire
qu'il va mal.

| Sonde | Unité | Pourquoi celle-là |
|---|---|---|
| 1 | `réconciliations en échec` | Le pluriel porte la règle 5 : on compte des réconciliations, pas des paiements, parce qu'un paiement peut en rater deux. |
| 2 | `réconciliations sans log` | « sans log » et non « manquantes » : ce qui manque est la trace, pas la réconciliation — elle a peut-être eu lieu. La nuance décide de qui on appelle. |
| 3 | `refus techniques Airtel` | Nomme le responsable. « erreurs » aurait fait chercher chez nous ; le mot « Airtel » dans la notification économise le premier quart d'heure. |
| 4 | `paiements en échec` | Le seul endroit où « paiement » est le bon objet — c'est la seule sonde qui ne parle pas de réconciliation. |
| 5 | `minutes sans paiement` | Une durée, pas un compte. C'est la seule sonde dont la valeur n'est pas un dénombrement, et l'unité doit le dire pour qu'on ne lise pas « 95 paiements ». |

---

## Avant d'enregistrer une sonde

**`?` est impraticable.** La connexion de supervision utilise
`PDO::ATTR_EMULATE_PREPARES` — le pooler Supabase ne supporte pas les requêtes
préparées nommées. PDO analyse donc le SQL lui-même et prend l'opérateur jsonb
`?` pour un paramètre positionnel : la requête est refusée avant d'atteindre
Postgres. Vérifié : `response ? 'error'` fait voir à PDO **deux** paramètres là
où la sonde n'en fournit qu'un. On écrit `jsonb_exists(response, 'error')`.

**`:depuis` est obligatoire.** La sonde reçoit exactement un paramètre. Une
requête qui ne l'utilise pas échoue sur « Invalid parameter number ». Elle peut
en revanche s'en servir plusieurs fois : c'est permis en mode émulé, et la
sonde 5 le fait.

**Le cast `::` est à proscrire, pour la même raison que `?`.** `:depuis` est un
paramètre nommé ; `::timestamptz` ressemble à s'y méprendre à un paramètre
nommé `:timestamptz`. Le premier jet de la sonde 5 en contenait deux, et le
contrôle les a vus comme des paramètres que personne ne fournirait jamais. On
écrit `cast(x as timestamptz)` — plus long, mais sans ambiguïté possible pour
l'analyseur qui lit la requête avant Postgres.

**Une colonne nommée `valeur`, entière.** `NULL` est lu comme zéro ; une
absence de ligne aussi.

**8 secondes.** `DatabaseConnector::TIMEOUT_MS`. La requête wallet du dashboard
peut prendre 30 s, mais sur des mois d'historique : sur 24 h le volume est sans
commune mesure. À confirmer au bouton **Essayer**.

**Le fuseau.** Arche calcule `:depuis` chez lui et l'envoie en texte. Si
`payment.created_at` est un `timestamp` sans fuseau dans un autre fuseau que
celui d'Arche, le décalage est silencieux — la sonde comptera une fenêtre
décalée sans jamais se plaindre. Le test : lancer **Essayer** sur 24 h et
comparer au même chiffre lu sur le dashboard Paynala pour la même période. S'ils
diffèrent, c'est là qu'il faut chercher.

---

## Les sondes

### Sonde 1 — Réconciliations en échec

*Unité : `réconciliations en échec`*
*24 h : `3, 10, 20, 40, 60, 100` · 48 h : `10, 40, 100`*

Le chiffre de tête. Un `FAILED` par jambe (règle 5), sur les seuls canaux
réconciliables (règle 3), sur les seuls paiements réussis (règle 4).

Les paliers reprennent ceux que tu avais posés. Le premier à 3 dit la règle du
métier : trois échecs de réconciliation dans une journée sont déjà anormaux.

```sql
select
    count(*) filter (where
        case
            when cp.request_id is null then 'MISSING'
            when cp.response->'status'->>'success' = 'true' then 'SUCCESS'
            else 'FAILED'
        end = 'FAILED')
  + count(*) filter (where
        case
            when mc1.request_id is null then 'MISSING'
            when mc1.response->'status'->>'success' = 'true' then 'SUCCESS'
            else 'FAILED'
        end = 'FAILED')
  + count(*) filter (where
        case
            when mc2.request_id is null then 'MISSING'
            when mc2.response->'status'->>'success' = 'true' then 'SUCCESS'
            else 'FAILED'
        end = 'FAILED')
    as valeur
from payment p
join merchant m on m.id = p.merchant_id
left join lateral (
    select al.request_id, al.response
    from airtel_logs al
    where al.request_id =
        case
            when p.channel = 'USSD' then p.airtel_money_id || 'CP'
            else p.request_id || '_CP'
        end
    order by al.created_at desc
    limit 1
) cp on true
left join lateral (
    select al.request_id, al.response
    from airtel_logs al
    where al.request_id =
        case
            when m.name = 'Rengus Digital' then
                case when p.channel = 'USSD' then p.airtel_money_id || 'MC1'
                     else p.request_id || '_MC1' end
            else
                case when p.channel = 'USSD' then p.airtel_money_id || 'MC'
                     else p.request_id || '_MC' end
        end
    order by al.created_at desc
    limit 1
) mc1 on true
left join lateral (
    select al.request_id, al.response
    from airtel_logs al
    where m.name = 'Rengus Digital'
      and al.request_id =
        case
            when p.channel = 'USSD' then p.airtel_money_id || 'MC2'
            else p.request_id || '_MC2'
        end
    order by al.created_at desc
    limit 1
) mc2 on true
where p.created_at >= :depuis
  and p.channel in ('WEB', 'USSD', 'API', 'RECOVERY')
  and p.status = 'SUCCESS'
```

### Sonde 2 — Réconciliations sans log

*Unité : `réconciliations sans log`*
*24 h : `5, 20, 50, 100` · 48 h : `20, 100`*

Règle 1 : ce n'est pas un échec, c'est un silence. Noter le
`m.name = 'Rengus Digital'` **dans le troisième `count`** — sans lui, cette
sonde compterait chaque paiement de tous les autres marchands (règle 6).

Premier palier à 5 et non à 3 : un log peut arriver en retard, et une sonde
qui tourne toutes les cinq minutes en attrapera quelques-uns en vol. Trois
seraient du bruit ; cinq ne le sont plus.

```sql
select
    count(*) filter (where
        case
            when cp.request_id is null then 'MISSING'
            when cp.response->'status'->>'success' = 'true' then 'SUCCESS'
            else 'FAILED'
        end = 'MISSING')
  + count(*) filter (where
        case
            when mc1.request_id is null then 'MISSING'
            when mc1.response->'status'->>'success' = 'true' then 'SUCCESS'
            else 'FAILED'
        end = 'MISSING')
  + count(*) filter (where
        m.name = 'Rengus Digital'
        and case
            when mc2.request_id is null then 'MISSING'
            when mc2.response->'status'->>'success' = 'true' then 'SUCCESS'
            else 'FAILED'
        end = 'MISSING')
    as valeur
from payment p
join merchant m on m.id = p.merchant_id
left join lateral (
    select al.request_id, al.response
    from airtel_logs al
    where al.request_id =
        case
            when p.channel = 'USSD' then p.airtel_money_id || 'CP'
            else p.request_id || '_CP'
        end
    order by al.created_at desc
    limit 1
) cp on true
left join lateral (
    select al.request_id, al.response
    from airtel_logs al
    where al.request_id =
        case
            when m.name = 'Rengus Digital' then
                case when p.channel = 'USSD' then p.airtel_money_id || 'MC1'
                     else p.request_id || '_MC1' end
            else
                case when p.channel = 'USSD' then p.airtel_money_id || 'MC'
                     else p.request_id || '_MC' end
        end
    order by al.created_at desc
    limit 1
) mc1 on true
left join lateral (
    select al.request_id, al.response
    from airtel_logs al
    where m.name = 'Rengus Digital'
      and al.request_id =
        case
            when p.channel = 'USSD' then p.airtel_money_id || 'MC2'
            else p.request_id || '_MC2'
        end
    order by al.created_at desc
    limit 1
) mc2 on true
where p.created_at >= :depuis
  and p.channel in ('WEB', 'USSD', 'API', 'RECOVERY')
  and p.status = 'SUCCESS'
```

### Sonde 3 — Refus techniques Airtel

*Unité : `refus techniques Airtel`*
*24 h : `1, 3, 10, 30` · 48 h : `5, 30`*

Règle 7 : la forme `{"error": …}` du log, celle des pannes de proxy et des
sockets coupées. Distincte de la forme métier, qui elle est le système qui
fonctionne.

Paliers volontairement très bas, jusqu'à **1**. Ce n'est pas du métier qui
refuse, c'est un partenaire injoignable : le premier mérite déjà d'être vu, et
trois dans la journée méritent un appel. C'est la seule sonde dont le premier
palier est à un — parce que c'est la seule où un événement isolé est déjà une
information.

```sql
select
    count(*) filter (where jsonb_exists(cp.response, 'error'))
  + count(*) filter (where jsonb_exists(mc1.response, 'error'))
  + count(*) filter (where jsonb_exists(mc2.response, 'error'))
    as valeur
from payment p
join merchant m on m.id = p.merchant_id
left join lateral (
    select al.request_id, al.response
    from airtel_logs al
    where al.request_id =
        case
            when p.channel = 'USSD' then p.airtel_money_id || 'CP'
            else p.request_id || '_CP'
        end
    order by al.created_at desc
    limit 1
) cp on true
left join lateral (
    select al.request_id, al.response
    from airtel_logs al
    where al.request_id =
        case
            when m.name = 'Rengus Digital' then
                case when p.channel = 'USSD' then p.airtel_money_id || 'MC1'
                     else p.request_id || '_MC1' end
            else
                case when p.channel = 'USSD' then p.airtel_money_id || 'MC'
                     else p.request_id || '_MC' end
        end
    order by al.created_at desc
    limit 1
) mc1 on true
left join lateral (
    select al.request_id, al.response
    from airtel_logs al
    where m.name = 'Rengus Digital'
      and al.request_id =
        case
            when p.channel = 'USSD' then p.airtel_money_id || 'MC2'
            else p.request_id || '_MC2'
        end
    order by al.created_at desc
    limit 1
) mc2 on true
where p.created_at >= :depuis
  and p.channel in ('WEB', 'USSD', 'API', 'RECOVERY')
  and p.status = 'SUCCESS'
```

### Sonde 4 — Paiements en échec

*Unité : `paiements en échec`*
*24 h : `10, 50, 100, 250, 500` · 48 h : `50, 250`*

Niveau paiement, pas réconciliation. **Aucune jointure** : c'est la sonde la
moins chère, et la seule qui répondra encore le jour où `airtel_logs` sera trop
lourd pour les autres. À garder pour cette raison même si elle paraît redondante.

Paliers bien plus hauts : un volume d'échecs de paiement est normal — clients
au solde vide, numéros erronés. Ce qu'on guette ici est un décrochage, pas un
incident.

```sql
select count(*) as valeur
from payment p
where p.created_at >= :depuis
  and p.status = 'FAILED'
```

### Sonde 5 — Silence de la production

*Unité : `minutes sans paiement`*
*24 h : `30, 60, 180, 360`*

Les quatre sondes ci-dessus comptent ce qui va mal. **Aucune ne se déclenche
quand plus rien n'arrive** — et c'est la panne la plus grave, parce qu'un
compteur d'échecs à zéro se lit exactement comme un système en bonne santé.

Celle-ci s'inverse : elle mesure les minutes écoulées depuis le dernier
paiement, et monte toute seule quand la production s'arrête.

Le `coalesce` n'est pas une précaution de style. Sans lui, une fenêtre
entièrement vide rend `NULL`, que `readValue` interprète comme zéro : la sonde
annoncerait « tout va bien » au moment précis où plus rien n'arrive depuis
vingt-quatre heures. Le repli mesure alors depuis le début de la fenêtre, ce
qui est un minorant — la production est peut-être arrêtée depuis plus
longtemps.

**Les paliers sont à régler après observation.** Ils dépendent du rythme réel
de nuit, que ce fichier ne connaît pas. Regarde d'abord le plus long trou
habituel d'un dimanche à 4 h du matin, puis pose le premier palier au-dessus.
Posés trop bas, ils réveilleront quelqu'un chaque nuit — et une sonde qu'on
apprend à ignorer ne sert plus à rien.

```sql
select cast(coalesce(
    extract(epoch from (now() - max(p.created_at))) / 60,
    extract(epoch from (now() - cast(:depuis as timestamptz))) / 60
) as int) as valeur
from payment p
where p.created_at >= :depuis
```

---

## Ce qui n'est pas transcrit, et pourquoi

**Les rapports partenaires** (`partners.js`) ne décrivent pas des erreurs mais
des livrables hebdomadaires, avec leur liste de dates de test exclues en dur.
Rien à surveiller là.

**Le détail des bénéficiaires** (`failedBeneficiaries.js`) répond à « qui n'a
pas été payé », pas à « est-ce que ça va mal ». Une sonde rend un nombre ; cette
question demande une liste. Elle reste du ressort du dashboard.

**Le filtre par marchand** est absent des cinq sondes, volontairement : une
alerte doit dire que quelque chose ne va pas, pas demander pour qui. Si un
marchand doit être suivi à part, c'est une sonde de plus avec son
`and m.name = '…'` — pas un paramètre.
