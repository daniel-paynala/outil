# Les erreurs de Paynala — une requête indépendante par type

Trente fichiers dans [`sondes/`](sondes/). **Chacun est autonome** : une
requête complète, sans vue à créer, sans socle partagé, sans dépendance aux
autres. Tu en colles une dans le champ « Requête » d'une sonde Arche, elle
marche seule.

Quinze d'entre elles interrogent **une seule table**. La plus longue fait
quatorze lignes.

---

## Ce qui a permis de les raccourcir

Les premières versions partaient de `payment` et rejoignaient `airtel_logs`
trois fois, comme le fait le dashboard. C'était soixante lignes par sonde,
recopiées quinze fois.

Le dashboard a une bonne raison de faire ça : il **affiche** les trois jambes
côte à côte sur une ligne de tableau, donc il lui faut le paiement et ses trois
logs ensemble. Une sonde ne fait que compter. Elle n'a besoin ni du paiement,
ni des trois jambes en même temps.

Or les logs de réconciliation portent leur jambe dans leur identifiant :

| Jambe | Fin de `request_id` |
|---|---|
| CP | `CP` |
| MC — tout le monde sauf Rengus | `MC` |
| MC1 — Rengus Digital | `MC1` |
| MC2 — Rengus Digital | `MC2` |

`request_id like '%CP'` suffit donc à isoler la première jambe. Aucune
jointure. C'est ce qui fait passer une sonde de soixante lignes à cinq.

---

## Les vingt-deux sondes, et les huit diagnostics

Le nom du fichier dit lequel est lequel.

Les fichiers **numérotés** sont des sondes : leur contenu se colle dans le champ
« Requête » d'une sonde Arche. Ils contiennent tous `:depuis`.

Les fichiers **`diag-`** n'en sont pas. Ils rendent des lignes et non un nombre,
et se lancent dans la console SQL de l'écran Supervision. Cette distinction n'a
pas toujours été portée par les noms, et elle a coûté trois « relation does not
exist » en deux jours.

### Une jambe a répondu non

| # | Fichier | Jambe |
|---|---|---|
| 01 | `01-cp-echec.sql` | CP |
| 02 | `02-mc-echec.sql` | MC — tous sauf Rengus Digital |
| 03 | `03-mc1-echec.sql` | MC1 — Rengus Digital |
| 04 | `04-mc2-echec.sql` | MC2 — Rengus Digital |

MC et MC1 sont deux fichiers, pas un : deux populations de marchands
différentes, et les mélanger empêcherait de voir que Rengus va mal pendant que
les autres vont bien.

### Une jambe n'a laissé aucun log

| # | Fichier | Jambe |
|---|---|---|
| 05 | `05-cp-sans-log.sql` | CP |
| 06 | `06-mc-sans-log.sql` | MC — tous sauf Rengus |
| 11 | `07-mc1-sans-log.sql` | MC1 — Rengus |
| 10 | `08-mc2-sans-log.sql` | MC2 — Rengus |

**Les quatre seules qui touchent deux tables**, et c'est irréductible : une
ligne absente ne se compte pas dans la table où elle manque. Ce sont aussi les
seules à garder `status = 'SUCCESS'` et le filtre de canal — ailleurs,
l'existence même du log les implique.

### Un type d'erreur

| # | Fichier | Ce qu'elle compte |
|---|---|---|
| 11 | `09-timeout.sql` | Time-out, quelle que soit la forme du log |
| 10 | `10-invalid-transaction-id.sql` | Identifiant de transaction refusé |
| 11 | `11-solde-insuffisant.sql` | Solde insuffisant |
| 12 | `12-coupure-reseau.sql` | Connexion coupée : socket, DNS, refus |
| 13 | `13-infrastructure-non-classee.sql` | **Filet** — panne technique inconnue |
| 14 | `14-echec-non-detaille.sql` | Airtel refuse sans dire pourquoi |
| 15 | `15-refus-metier-non-classe.sql` | **Filet** — refus métier inconnu |

### Hors réconciliation

| # | Fichier | Ce qu'elle compte |
|---|---|---|
| 16 | `16-paiements-en-echec.sql` | Paiements en statut `FAILED` |
| 17 | `17-paiements-reussis.sql` | Paiements en statut `SUCCESS` |
| 18 | `18-silence-production.sql` | Minutes depuis le dernier paiement |

### L'argent collecté

Même requête, quatre fenêtres. Elle additionne les montants crédités sur les
trois portefeuilles et rend leur décomposition sous le total.

| # | Fichier | Fenêtre | Sens |
|---|---|---|---|
| 19 | `19-montant-collecte-total.sql` | depuis toujours | croissant |
| 20 | `20-montant-collecte-annee.sql` | cette année | croissant |
| 21 | `21-montant-collecte-mois.sql` | ce mois-ci | croissant |
| 22 | `22-montant-collecte-1h.sql` | 1 h glissante | **décroissant** |

Un cumul depuis le 1er du mois est forcément bas le 2 : un plancher sonnerait
tous les débuts de mois sans qu'il se soit rien passé. Les paliers des trois
premières sont donc des jalons, et ils sont mesurés —
`api/database/sql/2026_09_06_paliers_mesures.sql`.

La **22** est celle qui alerte vraiment : sur une heure glissante, un montant
qui tombe sous le plancher habituel signale l'incident tout de suite, sans
attendre la fin du mois.

Une sonde par période, et non une sonde à quatre fenêtres : l'acquittement est
porté par la sonde, donc accuser réception d'un creux horaire remettrait aussi
à zéro le comptage du mois.

### Les diagnostics

| Fichier | Ce qu'il répond |
|---|---|
| `diag-libelles-derreur.sql` | les libellés d'erreur réels |
| `diag-entonnoir-montant.sql` | à quel filtre les lignes disparaissent |
| `diag-cles-json.sql` | les clés réelles de `request` et `response` |
| `diag-un-log-en-entier.sql` | un log complet, sans le `pin` |
| `diag-confirmer-le-montant.sql` | la sonde de montant, de bout en bout |
| `diag-index.sql` | les index de `airtel_logs` et leur usage |
| `diag-plan-dexecution.sql` | pourquoi une sonde est lente |
| `diag-volumes-reels.sql` | les cumuls réels, pour poser les paliers |

## La partition est stricte, et c'est vérifié

Les sept sondes de type forment une partition : **tout échec tombe dans
exactement une**. Ni deux, ni zéro. La logique des sept clauses a été rejouée
sur quinze messages couvrant chaque forme documentée plus des inconnus.

La ligne de partage principale est `jsonb_exists(response, 'error')` : les
logs d'infrastructure portent cette clé, les refus métier non. Seule la 07 la
traverse — un time-out peut se présenter sous les deux formes.

Contrôle en production :

```
01 + 02 + 03 + 04  =  09 + 10 + 11 + 12 + 13 + 14 + 15
```

Les deux côtés comptent les mêmes logs en échec, découpés autrement. S'ils
divergent, une sonde a été modifiée sans que la partition suive.

### Les deux filets

Les sondes **13** et **15** attrapent ce qu'aucune autre ne reconnaît. Elles ne
sont pas des fourre-tout : ce sont les deux seules qui peuvent te prévenir d'un
type d'erreur que personne n'avait anticipé.

**Si 15 monte pendant que 10 reste à zéro, c'est mon motif qui est faux, pas
Airtel qui va bien.**

---

## D'abord : la reconnaissance

`diag-libelles-derreur.sql` **ne se colle pas dans Arche** — elle rend des
lignes, pas un nombre. Elle se lance dans DBeaver ou l'éditeur SQL de Supabase,
sur trente jours, et liste chaque message d'erreur distinct avec sa forme et son
nombre d'occurrences.

Lance-la avant tout le reste. Les sondes 09, 10, 11 et 12 reposent sur des
motifs de texte que **le dépôt de référence ne contient pas** : `filters.js` ne
documente que deux exemples, `"Proxy request failed / socket hang up"` et
`"Solde insuffisant…"`. Le reste vient des erreurs usuelles de Node et des
libellés que tu as cités.

Un motif faux ne casse rien de visible : la sonde s'installe et ne signale
jamais rien. C'est le pire défaut possible — c'est précisément à elle qu'on fait
confiance.

| Sonde | Expression régulière | Statut |
|---|---|---|
| 11 | `timeout\|time out\|timed out\|ETIMEDOUT\|ESOCKETTIMEDOUT` | à confirmer |
| 10 | `invalid.*transaction\|transaction.*invalide` | à confirmer |
| 11 | `solde.*insuffisant\|insufficient\|balance` | à confirmer |
| 10 | `socket hang up\|ECONNRESET\|ECONNREFUSED\|ENETUNREACH\|EAI_AGAIN\|EHOSTUNREACH` | à confirmer |

Corriger un motif, c'est éditer une chaîne dans un fichier de six lignes.

---

## Deux écarts avec le dashboard, à connaître

Les sondes autonomes ne sont pas la requête du dashboard découpée : elles
partent d'ailleurs. Deux conséquences, toutes deux assumées.

**La fenêtre porte sur la date du log, pas sur celle du paiement.** Le dashboard
filtre `payment.created_at` parce qu'il répond à « qu'est-ce qui s'est passé sur
les transactions de cette période ». Une sonde répond à « qu'est-ce qui a raté
ces dernières heures » — la date de l'erreur est la bonne. Un log de
réconciliation arrivant le lendemain du paiement est compté le jour où il rate,
ce qui est ce qu'on veut d'une alerte.

**Le filtre `payment.status = 'SUCCESS'` disparaît des sondes 01-04 et 09-15.**
Une jambe de réconciliation n'est émise qu'après un paiement réussi, donc le
filtre devrait être sans effet. *Devrait* : je ne peux pas le vérifier sans la
base. Le test tient en une minute — lance **Essayer** sur la sonde 01 pour 24 h,
et compare au CP `FAILED` du dashboard sur la même journée. S'ils collent,
l'hypothèse tient.

Les sondes 05-08, elles, gardent les deux filtres : elles partent des paiements,
et là ils sont indispensables.

---

## Les règles reprises du projet

### 1. Trois états, jamais deux

`SUCCESS`, `FAILED`, `MISSING`. **Un log absent n'est pas un échec.**
`failedBeneficiaries.js` l'écrit noir sur blanc (« never "log absent", only a log
that came back unsuccessful ») et `walletFailures.js` ne compte que `= 'FAILED'`.

Un `FAILED` dit qu'Airtel a répondu non. Un `MISSING` dit que la réconciliation
n'a jamais été journalisée : un maillon s'est tu. Deux jeux de sondes séparés —
01-04 contre 05-08.

### 2. L'autorité est le drapeau du log, pas le code HTTP

`response->'status'->>'success' = 'true'`. `http_code` est fréquemment `NULL`
**même sur des succès authentiques**, sur les canaux API et RECOVERY. Une sonde
qui compterait `http_code >= 400` raterait des échecs et en inventerait
d'autres.

D'où le `is distinct from 'true'` de toutes les sondes d'échec : il traite
`NULL` comme un non-succès, ce que `!= 'true'` ne ferait pas.

### 3. Quatre canaux seulement se réconcilient

`WEB`, `USSD`, `API`, `RECOVERY`. `webview` et `backend_cron` ne portent jamais
de jambe CP/MC — ce n'est pas un défaut. Le filtre n'apparaît que dans les
sondes 05-08 : ailleurs, l'existence même du log l'implique.

### 4. Le suffixe dépend du canal

| | CP | deuxième | troisième |
|---|---|---|---|
| `USSD` | `airtel_money_id \|\| 'CP'` | `…'MC1'` ou `…'MC'` | `…'MC2'` |
| WEB / API / RECOVERY | `request_id \|\| '_CP'` | `…'_MC1'` ou `…'_MC'` | `…'_MC2'` |

Les sondes 01-04 et 09-15 n'ont pas besoin de cette table : elles ne regardent
que la **fin** de l'identifiant, qui est la même dans les deux cas. Seules les
sondes 05-08, qui reconstruisent l'identifiant attendu, la reprennent
intégralement.

### 5. `request.pin` ne sort jamais de la base

Interdiction explicite dans `failedBeneficiaries.js`. Les sondes ne rendent que
des nombres — la question se posera au premier qui voudra une sonde qui remonte
un détail.

---

## Avant d'enregistrer

**`?` est impraticable.** La connexion de supervision utilise
`PDO::ATTR_EMULATE_PREPARES` — le pooler Supabase ne supporte pas les requêtes
préparées nommées — donc PDO analyse le SQL lui-même et prend l'opérateur jsonb
`?` pour un paramètre positionnel. Mesuré : la requête est refusée avant
d'atteindre Postgres. D'où `jsonb_exists(response, 'error')`.

**Le cast `::` aussi.** `::timestamptz` est indistinguable d'un paramètre nommé
`:timestamptz`. On écrit `cast(x as timestamptz)`. Un premier jet de la sonde 18
en contenait deux ; le contrôle les a vus.

**`:depuis` est obligatoire**, et peut servir plusieurs fois — permis en mode
émulé, et la sonde 18 le fait.

**Une colonne nommée `valeur`, entière.** `NULL` est lu comme zéro, une absence
de ligne aussi.

**Le fuseau.** Arche calcule `:depuis` chez lui et l'envoie en texte. Si
`created_at` est un `timestamp` sans fuseau dans un autre fuseau que celui
d'Arche, le décalage est silencieux. Même test que ci-dessus : comparer au
dashboard sur la même journée.

**Un index sur `airtel_logs.created_at`** rendra les quatorze sondes de cette
table quasi gratuites. Sans lui, chacune parcourt les ~890 000 lignes, et
quatorze parcours toutes les cinq minutes se sentiront. À vérifier avant de tout
activer :

```sql
select indexname, indexdef from pg_indexes where tablename = 'airtel_logs';
```

---

## Unités et paliers

L'unité est lue **dans le corps de la notification**, qu'Arche compose ainsi :

```
{valeur} {unité} sur {fenêtre} h (palier {palier}).
```

Donc sur un écran verrouillé, la nuit, par quelqu'un sans contexte. Elle doit
faire une phrase, distinguer la sonde de ses dix-huit voisines, et nommer
l'objet métier plutôt que la table. C'est ce qui écarte « jambes » — *12 jambes
sur 24 h* ne se lit pas.

| # | Unité | 24 h | 48 h | Pourquoi ces paliers |
|---|---|---|---|---|
| 01 | `échecs CP` | `3, 10, 20, 40, 60, 100` | `10, 40, 100` | CP est la première jambe : elle casse rarement, et quand elle casse le reste suit. |
| 02 | `échecs MC` | `3, 10, 20, 40, 60, 100` | `10, 40, 100` | Même échelle que CP, pour que les deux chiffres se comparent à l'œil. |
| 03 | `échecs MC1 Rengus` | `3, 10, 20, 40` | `10, 40` | Un seul marchand : la grande échelle ne sonnerait jamais. |
| 04 | `échecs MC2 Rengus` | `3, 10, 20, 40` | `10, 40` | Idem 03. |
| 05 | `CP sans log` | `5, 20, 50, 100` | `20, 100` | 5 et non 3 : un log peut arriver en retard, et la sonde en attrape en vol. |
| 06 | `MC sans log` | `5, 20, 50, 100` | `20, 100` | Idem 05. |
| 07 | `MC1 sans log` | `5, 20, 50` | `20` | À l'échelle d'un marchand. |
| 08 | `MC2 sans log` | `5, 20, 50` | `20` | Idem 07. |
| 09 | `time-outs Airtel` | `1, 3, 10, 30` | `5, 30` | Premier palier à **1**. Un time-out isolé est déjà une information : ce n'est pas du métier qui refuse. |
| 10 | `identifiants refusés` | `1, 5, 20, 50` | `10, 50` | À 1 aussi : un identifiant refusé signale presque toujours un défaut de génération. |
| 11 | `soldes insuffisants` | `20, 100, 300, 1000` | `100, 1000` | Le métier qui fonctionne. Paliers hauts : on guette un décrochage. |
| 12 | `coupures réseau` | `1, 3, 10, 30` | `5, 30` | Comme 09 : Airtel injoignable. |
| 13 | `pannes techniques inconnues` | `1, 3, 10` | `3, 10` | À 1 : le premier exemplaire est ce qu'on veut voir, il nomme un type qu'aucune sonde n'attrape encore. |
| 14 | `refus sans motif` | `5, 20, 50` | `20, 50` | Un refus non détaillé est aveuglant en nombre. |
| 15 | `refus métier inconnus` | `3, 10, 30, 100` | `10, 100` | À 3 : au-delà, c'est un libellé nouveau qui mérite sa propre sonde. |
| 16 | `paiements en échec` | `10, 50, 100, 250, 500` | `50, 250` | Un volume d'échecs est normal ; on guette le décrochage. |
| 17 | `paiements réussis` | `100` | — | Voir la note sur l'acquittement, plus haut. |
| 18 | `minutes sans paiement` | `30, 60, 180, 360` | — | **À régler après observation** — voir ci-dessous. |
| 19 | `F CFA` | `1,5 Md · 2 · 3 · 5 · 10` | — | Depuis toujours. Cumul réel : 1,12 Md. Prochain jalon dans ~51 jours. |
| 20 | `F CFA` | `1 Md · 2 · 3 · 5` | — | Cette année. 960 M au 2 septembre : le milliard tombe dans ~5 jours. |
| 21 | `F CFA` | `100 M · 250 · 500 · 1 Md` | — | Ce mois-ci. ~220 M au rythme des trente derniers jours. |
| 22 | `F CFA` | **à observer** | — | 1 h glissante, décroissant. Le seul des quatre qui prévienne d'un effondrement. |

Les paliers 19 à 21 ne sont pas des intuitions : ils viennent de
`diag-volumes-reels.sql`, et l'`UPDATE` correspondant est dans
`api/database/sql/2026_09_06_paliers_mesures.sql`.

### La sonde 18 mérite son propre paragraphe

Les autres comptent ce qui va mal, ou ce qui va bien. **Aucune ne se déclenche quand plus
rien n'arrive** — et c'est la panne la plus grave, parce qu'un compteur d'échecs
à zéro se lit exactement comme un système en bonne santé.

Celle-ci s'inverse : elle mesure les minutes depuis le dernier paiement et monte
toute seule quand la production s'arrête.

```sql
-- Minutes depuis le dernier paiement. La seule sonde qui monte quand plus
-- rien n'arrive — un compteur d'échecs à zéro se lit comme un système sain.
select cast(coalesce(
    extract(epoch from (now() - max(created_at))) / 60,
    extract(epoch from (now() - cast(:depuis as timestamptz))) / 60
) as int) as valeur
from payment
where created_at >= :depuis
```

Le `coalesce` n'est pas une précaution de style. Sans lui, une fenêtre
entièrement vide rend `NULL`, que `readValue` lit comme zéro : la sonde
annoncerait « tout va bien » au moment précis où plus rien n'arrive depuis
vingt-quatre heures. Le repli mesure alors depuis le début de la fenêtre, ce qui
est un minorant.

Ses paliers dépendent du rythme réel de nuit, que ce fichier ne connaît pas.
Regarde le plus long trou habituel d'un dimanche à 4 h, puis pose le premier
au-dessus. Posés trop bas, ils réveilleront quelqu'un chaque nuit — et une sonde
qu'on apprend à ignorer ne sert plus à rien.

---

## Ce qui n'est pas transcrit, et pourquoi

**Les rapports partenaires** (`partners.js`) ne décrivent pas des erreurs mais
des livrables hebdomadaires, avec leur liste de dates de test exclues en dur.
Rien à surveiller là.

**Le détail des bénéficiaires** (`failedBeneficiaries.js`) répond à « qui n'a
pas été payé », pas à « est-ce que ça va mal ». Une sonde rend un nombre ; cette
question demande une liste. Elle reste du ressort du dashboard.

**Le filtre par marchand** est absent, volontairement : une alerte doit dire que
quelque chose ne va pas, pas demander pour qui. Si un marchand doit être suivi à
part, c'est une sonde de plus — un fichier copié, une ligne changée.
